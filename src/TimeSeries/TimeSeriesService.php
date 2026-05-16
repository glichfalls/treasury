<?php

namespace App\TimeSeries;

use App\Entity\Account;
use App\Entity\User;
use App\Fx\FxConverter;
use Doctrine\DBAL\Connection;

/**
 * Computes net-worth-over-time series from raw transactions, prices, and FX rates.
 *
 * Algorithm:
 *  1. Pull every transaction for the scope sorted by date.
 *  2. For each requested sample date, walk cumulative cash balance + per-asset cumulative
 *     quantity up to that date.
 *  3. Per asset, look up the last known price ≤ sample date in native currency,
 *     convert to the display currency via the last known FX rate ≤ sample date.
 *  4. Sum cash + Σ(qty × price × fx).
 *
 * Prices and FX rates are pre-loaded into sorted arrays so the per-date lookup is binary
 * search instead of a query per date.
 */
final class TimeSeriesService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly FxConverter $fx,
    ) {}

    /** Expose the connection so sibling services can run ad-hoc queries through us. */
    public function getConnection(): Connection
    {
        return $this->conn;
    }

    /**
     * Monthly cashflow grouped by transaction category. Trade legs and FX
     * conversions are excluded (they're internal money movement, not real
     * income/expense). Uncategorized transactions are bucketed as null so the
     * frontend can show them as "Uncategorized".
     *
     * @return list<array{month: string, category: ?string, amountMinor: string}>
     */
    public function cashFlowByCategoryMonthly(
        \App\Entity\User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $baseCurrency = 'CHF',
    ): array {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.category, t.amount_minor, t.currency
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
               AND t.occurred_at BETWEEN :from AND :to
               AND t.type NOT IN ('trade_buy', 'trade_sell', 'fx_conversion')
             ORDER BY t.occurred_at ASC",
            [
                'owner' => $user->getId()->toBinary(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        );

        // Bucketize in PHP so we can apply FX per row. Key: "YYYY-MM|category".
        $buckets = [];
        foreach ($rows as $r) {
            $amount = $this->convertCash((int) $r['amount_minor'], $r['currency'], $r['occurred_at'], $baseCurrency);
            $month = substr($r['occurred_at'], 0, 7);
            $cat = $r['category'];
            $key = $month . '|' . ($cat ?? '');
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['month' => $month, 'category' => $cat, 'amount' => 0];
            }
            $buckets[$key]['amount'] += $amount;
        }

        return array_values(array_map(fn($b) => [
            'month' => $b['month'],
            'category' => $b['category'],
            'amountMinor' => (string) $b['amount'],
        ], $buckets));
    }

    /**
     * Monthly income vs expense across all accounts owned by $user, converted to
     * $baseCurrency using the FX rate effective on each transaction's date.
     *
     * Trade legs (trade_buy/trade_sell) and FX conversions are excluded — they represent
     * money moving inside the system, not real income or spending. Deposits/dividends/
     * interest count as income; withdrawals/fees count as expenses.
     *
     * @return CashFlowPoint[]
     */
    public function cashFlowMonthly(
        \App\Entity\User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $baseCurrency = 'CHF',
    ): array {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
               AND t.occurred_at BETWEEN :from AND :to
               AND t.type NOT IN ('trade_buy', 'trade_sell', 'fx_conversion')
             ORDER BY t.occurred_at ASC",
            [
                'owner' => $user->getId()->toBinary(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        );

        $byYm = [];
        foreach ($rows as $r) {
            $amount = $this->convertCash((int) $r['amount_minor'], $r['currency'], $r['occurred_at'], $baseCurrency);
            $month = substr($r['occurred_at'], 0, 7);
            if (!isset($byYm[$month])) {
                $byYm[$month] = ['income' => 0, 'expense' => 0];
            }
            if ($amount > 0) {
                $byYm[$month]['income'] += $amount;
            } elseif ($amount < 0) {
                $byYm[$month]['expense'] += $amount;
            }
        }

        // Fill in months with no activity so the chart shows a continuous axis.
        $out = [];
        $cursor = $from->modify('first day of this month');
        $end = $to->modify('first day of this month');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $row = $byYm[$key] ?? ['income' => 0, 'expense' => 0];
            $out[] = new CashFlowPoint(
                month: $cursor,
                incomeMinor: (string) $row['income'],
                expenseMinor: (string) $row['expense'],
            );
            $cursor = $cursor->modify('first day of next month');
        }
        return $out;
    }

    /**
     * Convert a cash flow into $baseCurrency using historical FX on the row's
     * date. Falls back to the raw amount when no rate is known (silent loss of
     * cash flows would be worse than a slightly-off number you can debug).
     */
    private function convertCash(int $amountMinor, string $currency, string $dateStr, string $baseCurrency): int
    {
        if (strtoupper($currency) === strtoupper($baseCurrency)) {
            return $amountMinor;
        }
        $converted = $this->fx->convertMinor($amountMinor, $currency, $baseCurrency, new \DateTimeImmutable($dateStr));
        return $converted ?? $amountMinor;
    }

    /**
     * Aggregated allocation across all the user's accounts. Holdings are converted to
     * $displayCurrency via latest FX; cash from each account converted similarly.
     *
     * @return array{baseCurrency: string, slices: list<array{label: string, isin: ?string, valueBaseMinor: string}>}
     */
    public function globalAllocation(\App\Entity\User $user, string $displayCurrency = 'CHF'): array
    {
        // Aggregate cash per currency, then aggregate holdings qty per ISIN.
        $cashRows = $this->conn->fetchAllAssociative(
            "SELECT t.currency, COALESCE(SUM(t.amount_minor), 0) AS cash
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             GROUP BY t.currency",
            ['owner' => $user->getId()->toBinary()],
        );

        $holdingRows = $this->conn->fetchAllAssociative(
            "SELECT t.asset_isin, SUM(t.asset_quantity) AS qty
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
               AND t.asset_isin IS NOT NULL AND t.asset_quantity IS NOT NULL
             GROUP BY t.asset_isin
             HAVING qty <> 0",
            ['owner' => $user->getId()->toBinary()],
        );

        // Resolve latest prices and FX for the conversion.
        $isins = array_column($holdingRows, 'asset_isin');
        $pricesByIsin = $isins === [] ? [] : $this->loadPrices($isins);
        $allCurrencies = array_unique(array_merge(
            array_column($cashRows, 'currency'),
            array_map(fn($r) => $r['currency'] ?? '', array_map(fn($rs) => end($rs), $pricesByIsin)),
        ));
        $fxByPair = $this->loadFxRates($displayCurrency, $pricesByIsin);

        $today = (new \DateTimeImmutable())->format('Y-m-d');

        // One pseudo-slice for total cash converted to display currency.
        $cashTotalMinor = 0;
        foreach ($cashRows as $r) {
            $ccy = $r['currency'];
            $val = (int) $r['cash'];
            if ($ccy === $displayCurrency) {
                $cashTotalMinor += $val;
            } else {
                $fx = $this->findOnOrBefore($fxByPair[$ccy] ?? [], $today);
                if ($fx !== null) {
                    $cashTotalMinor += (int) round($val * $fx['rate']);
                }
            }
        }

        $slices = [];
        if ($cashTotalMinor !== 0) {
            $slices[] = ['label' => 'Cash', 'isin' => null, 'valueBaseMinor' => (string) $cashTotalMinor];
        }

        // Per-holding slice.
        $assetMeta = [];
        if ($isins !== []) {
            $placeholders = implode(',', array_fill(0, count($isins), '?'));
            $metaRows = $this->conn->fetchAllAssociative(
                "SELECT isin, ticker, name FROM assets WHERE isin IN ($placeholders)",
                $isins,
            );
            foreach ($metaRows as $m) {
                $assetMeta[$m['isin']] = $m;
            }
        }

        foreach ($holdingRows as $r) {
            $isin = $r['asset_isin'];
            $qty = (float) $r['qty'];
            $priceEntry = $this->findOnOrBefore($pricesByIsin[$isin] ?? [], $today);
            if ($priceEntry === null) {
                continue;
            }
            $priceMajor = $priceEntry['price_minor'] / 100;
            $native = $qty * $priceMajor;
            $assetCcy = $priceEntry['currency'];
            $valueDisplay = $native;
            if ($assetCcy !== $displayCurrency) {
                $fx = $this->findOnOrBefore($fxByPair[$assetCcy] ?? [], $today);
                if ($fx === null) {
                    continue;
                }
                $valueDisplay = $native * $fx['rate'];
            }
            $meta = $assetMeta[$isin] ?? ['ticker' => null, 'name' => null];
            $slices[] = [
                'label' => $meta['ticker'] ?? $meta['name'] ?? $isin,
                'isin' => $isin,
                'valueBaseMinor' => (string) (int) round($valueDisplay * 100),
            ];
        }

        return ['baseCurrency' => $displayCurrency, 'slices' => $slices];
    }

    /**
     * Net-worth time series for all accounts owned by $user.
     *
     * @return TimeSeriesPoint[]
     */
    public function netWorthSeries(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity = 'daily',
        string $displayCurrency = 'CHF',
    ): array {
        $accountIds = $this->conn->fetchFirstColumn(
            'SELECT id FROM accounts WHERE owner_id = ?',
            [$user->getId()->toBinary()],
        );
        if ($accountIds === []) {
            return [];
        }
        return $this->compute($accountIds, $from, $to, $granularity, $displayCurrency);
    }

    /**
     * Time series for a single account, valued in that account's own currency.
     *
     * @return TimeSeriesPoint[]
     */
    public function accountSeries(
        Account $account,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity = 'daily',
    ): array {
        return $this->compute(
            [$account->getId()->toBinary()],
            $from,
            $to,
            $granularity,
            $account->getCurrency(),
        );
    }

    /**
     * @param list<string> $accountBinIds
     * @return TimeSeriesPoint[]
     */
    private function compute(
        array $accountBinIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity,
        string $displayCurrency,
    ): array {
        $placeholders = implode(',', array_fill(0, count($accountBinIds), '?'));

        // All transactions in scope, sorted by date.
        $tx = $this->conn->fetchAllAssociative(
            "SELECT occurred_at, amount_minor, currency, type, asset_isin, asset_quantity
             FROM transactions
             WHERE account_id IN ($placeholders)
             ORDER BY occurred_at ASC, id ASC",
            $accountBinIds,
        );

        // Nothing happened in this scope — no point sampling phantom flat-zero
        // points back to whatever $from the caller asked for.
        if ($tx === []) {
            return [];
        }

        // Don't draw a flat-zero line stretching back further than the user's
        // actual history. Clamp $from upward to the first transaction date so
        // the "all" range starts where the portfolio began.
        $firstTxDate = new \DateTimeImmutable($tx[0]['occurred_at']);
        if ($from < $firstTxDate) {
            $from = $firstTxDate;
        }

        // External-cash-flow types only. Dividends and interest are PORTFOLIO
        // INCOME (generated by holdings/cash you already have, not new money
        // entering); fees are portfolio drag. Counting any of them as
        // "deposits" makes vsDeposits and TWR systematically wrong — TWR in
        // particular strips period flows out of the return calculation, so
        // tagging a $50 dividend as a flow makes that $50 of real return
        // invisible. `other` is ambiguous, kept out for safety.
        $depositLikeTypes = ['deposit', 'withdrawal'];

        // Collect ISINs we'll need to look up prices for.
        $isins = [];
        foreach ($tx as $r) {
            if ($r['asset_isin'] !== null) {
                $isins[$r['asset_isin']] = true;
            }
        }

        $pricesByIsin = $this->loadPrices(array_keys($isins));
        // For commodity-backed assets (gold coins), substitute their price series with
        // one derived from gold spot prices × grams × (1 + premium). This way the rest
        // of the loop treats them like any other asset.
        $pricesByIsin = $this->applyCommodityDerivedPrices($pricesByIsin, array_keys($isins));
        $fxByPair = $this->loadFxRates($displayCurrency, $pricesByIsin);

        $dates = $this->sampleDates($from, $to, $granularity);
        $points = [];

        $cashCum = 0;
        $netDepositsCum = 0;
        $qtyByIsin = [];   // isin => cumulative qty (decimal string)
        $txIdx = 0;
        $txCount = count($tx);

        foreach ($dates as $date) {
            $dateStr = $date->format('Y-m-d');

            while ($txIdx < $txCount && $tx[$txIdx]['occurred_at'] <= $dateStr) {
                $row = $tx[$txIdx];
                // Cash impacts come through in the transaction's native currency
                // (typically the account's currency). For a portfolio-wide view in
                // a different base, convert via the FX rate on the transaction
                // date. Same-currency rows shortcut through the converter without
                // a DB hit.
                $txAmount = (int) $row['amount_minor'];
                $rowCurrency = $row['currency'];
                $converted = $this->fx->convertMinor(
                    $txAmount,
                    $rowCurrency,
                    $displayCurrency,
                    new \DateTimeImmutable($row['occurred_at']),
                );
                // If no FX rate is available we fall back to the raw amount; the
                // alternative (dropping the row) silently hides cash flows, which
                // is worse than a slightly wrong number that you can debug.
                $converted ??= $txAmount;
                $cashCum += $converted;
                if (in_array($row['type'], $depositLikeTypes, true)) {
                    $netDepositsCum += $converted;
                }
                if ($row['asset_isin'] !== null && $row['asset_quantity'] !== null
                    && in_array($row['type'], ['trade_buy', 'trade_sell'], true)
                ) {
                    $isin = $row['asset_isin'];
                    $qtyByIsin[$isin] = bcadd($qtyByIsin[$isin] ?? '0', $row['asset_quantity'], 8);
                }
                $txIdx++;
            }

            // Value of holdings at this date. Assets without a price ≤ date contribute 0:
            // honest about what we can value rather than inventing a fallback that creates
            // phantom drops when the position is later sold.
            $holdings = 0.0;
            foreach ($qtyByIsin as $isin => $qty) {
                if (bccomp($qty, '0', 8) === 0) {
                    continue;
                }
                $priceEntry = $this->findOnOrBefore($pricesByIsin[$isin] ?? [], $dateStr);
                if ($priceEntry === null) {
                    continue;
                }
                $priceMajor = $priceEntry['price_minor'] / 100;
                $native = (float) $qty * $priceMajor;
                $assetCcy = $priceEntry['currency'];
                if ($assetCcy === $displayCurrency) {
                    $holdings += $native;
                } else {
                    $fxEntry = $this->findOnOrBefore($fxByPair[$assetCcy] ?? [], $dateStr);
                    if ($fxEntry !== null) {
                        $holdings += $native * $fxEntry['rate'];
                    }
                }
            }

            $holdingsMinor = (string) (int) round($holdings * 100);
            $totalMinor = (string) ((int) $holdingsMinor + $cashCum);
            $points[] = new TimeSeriesPoint(
                date: $date,
                cashMinor: (string) $cashCum,
                holdingsMinor: $holdingsMinor,
                totalMinor: $totalMinor,
                netDepositsMinor: (string) $netDepositsCum,
            );
        }

        return $points;
    }

    /**
     * For each held ISIN that has a unitWeightGrams set (commodity-backed coins),
     * replace its price series with one derived from gold spot × grams × (1 + premium).
     * Non-commodity ISINs are passed through unchanged.
     *
     * @param array<string, list<array{date:string,price_minor:int,currency:string}>> $pricesByIsin
     * @param list<string> $isins
     * @return array<string, list<array{date:string,price_minor:int,currency:string}>>
     */
    private function applyCommodityDerivedPrices(array $pricesByIsin, array $isins): array
    {
        if ($isins === []) {
            return $pricesByIsin;
        }
        // Look up unit_weight_grams + premium per requested isin.
        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $metaRows = $this->conn->fetchAllAssociative(
            "SELECT isin, unit_weight_grams, price_premium_pct
             FROM assets WHERE isin IN ($placeholders) AND unit_weight_grams IS NOT NULL",
            $isins,
        );
        if ($metaRows === []) {
            return $pricesByIsin;
        }

        // Pull the gold spot history once.
        $spot = $this->conn->fetchAllAssociative(
            "SELECT p.occurred_at AS date, p.price_minor, p.currency
             FROM prices p INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin = ? ORDER BY p.occurred_at ASC",
            [\App\Holdings\HoldingsService::SPOT_GOLD_ISIN],
        );
        if ($spot === []) {
            return $pricesByIsin;
        }
        $troyOunce = 31.1034768;

        foreach ($metaRows as $m) {
            $grams = (float) $m['unit_weight_grams'];
            $premium = (float) ($m['price_premium_pct'] ?? '0') / 100;
            $factor = ($grams / $troyOunce) * (1 + $premium);
            $series = [];
            foreach ($spot as $s) {
                $series[] = [
                    'date' => $s['date'],
                    'price_minor' => (int) round((int) $s['price_minor'] * $factor),
                    'currency' => $s['currency'],
                ];
            }
            $pricesByIsin[$m['isin']] = $series;
        }
        return $pricesByIsin;
    }

    /**
     * @param list<string> $isins
     * @return array<string, list<array{date:string,price_minor:int,currency:string}>>
     */
    private function loadPrices(array $isins): array
    {
        if ($isins === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $rows = $this->conn->fetchAllAssociative(
            "SELECT a.isin, p.occurred_at AS date, p.price_minor, p.currency
             FROM prices p
             INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin IN ($placeholders)
             ORDER BY a.isin, p.occurred_at ASC",
            $isins,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['isin']][] = [
                'date' => $r['date'],
                'price_minor' => (int) $r['price_minor'],
                'currency' => $r['currency'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, list<array{currency:string,...}>> $pricesByIsin
     * @return array<string, list<array{date:string,rate:float}>>
     */
    private function loadFxRates(string $displayCurrency, array $pricesByIsin): array
    {
        $needed = [];
        foreach ($pricesByIsin as $rows) {
            foreach ($rows as $r) {
                if ($r['currency'] !== $displayCurrency) {
                    $needed[$r['currency']] = true;
                }
            }
        }
        if ($needed === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($needed), '?'));
        $params = array_merge([$displayCurrency], array_keys($needed));
        $rows = $this->conn->fetchAllAssociative(
            "SELECT from_currency, occurred_at AS date, rate
             FROM fx_rates
             WHERE to_currency = ? AND from_currency IN ($placeholders)
             ORDER BY from_currency, occurred_at ASC",
            $params,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['from_currency']][] = [
                'date' => $r['date'],
                'rate' => (float) $r['rate'],
            ];
        }
        return $out;
    }

    /**
     * Binary search: largest entry whose 'date' ≤ $target, or null if none.
     *
     * @param list<array{date:string,...}> $sorted
     * @param string $target  YYYY-MM-DD
     * @return array{date:string, ...}|null
     */
    private function findOnOrBefore(array $sorted, string $target): ?array
    {
        $count = count($sorted);
        if ($count === 0 || $sorted[0]['date'] > $target) {
            return null;
        }
        $lo = 0;
        $hi = $count - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($sorted[$mid]['date'] <= $target) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $sorted[$lo];
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function sampleDates(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $step = match ($granularity) {
            'weekly' => new \DateInterval('P1W'),
            'monthly' => new \DateInterval('P1M'),
            default => new \DateInterval('P1D'),
        };

        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $out = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $out[] = $cursor;
            $cursor = $cursor->add($step);
        }
        // Always include the end date so we don't truncate by step alignment.
        $last = end($out);
        if ($last === false || $last < $to) {
            $out[] = $to;
        }
        return $out;
    }
}
