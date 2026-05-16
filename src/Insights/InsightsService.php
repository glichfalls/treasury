<?php

namespace App\Insights;

use App\Entity\User;
use App\Fx\FxConverter;
use Doctrine\DBAL\Connection;

/**
 * Aggregations powering the Insights page (currencies, fees, dividends).
 *
 * All amounts are converted to the user's base currency using historical FX for
 * cash-flow rows and today's FX for snapshot exposure — same conventions as the
 * net-worth time series so numbers reconcile.
 */
final class InsightsService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly FxConverter $fx,
    ) {}

    /**
     * Current FX exposure: how much of the portfolio sits in each currency,
     * reported in both native units AND the user's base currency.
     *
     * Cash is grouped by transaction currency. Holdings are grouped by the
     * price currency of the asset.
     *
     * @return list<array{currency: string, valueNativeMinor: string, valueBaseMinor: string}>
     */
    public function currencyExposureSnapshot(User $user, string $baseCurrency): array
    {
        $ownerBin = $user->getId()->toBinary();
        $today = new \DateTimeImmutable('today');

        // Native accumulator first — base values are derived from native via today's FX.
        $nativeByCcy = [];

        $cashRows = $this->conn->fetchAllAssociative(
            "SELECT t.currency, COALESCE(SUM(t.amount_minor), 0) AS minor
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             GROUP BY t.currency",
            ['owner' => $ownerBin],
        );
        foreach ($cashRows as $r) {
            $ccy = $r['currency'];
            $val = (int) $r['minor'];
            if ($val === 0) continue;
            $nativeByCcy[$ccy] = ($nativeByCcy[$ccy] ?? 0) + $val;
        }

        $holdingRows = $this->conn->fetchAllAssociative(
            "SELECT t.asset_isin, SUM(t.asset_quantity) AS qty
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
               AND t.asset_isin IS NOT NULL AND t.asset_quantity IS NOT NULL
             GROUP BY t.asset_isin
             HAVING qty <> 0",
            ['owner' => $ownerBin],
        );

        if ($holdingRows !== []) {
            $isins = array_column($holdingRows, 'asset_isin');
            $placeholders = implode(',', array_fill(0, count($isins), '?'));
            $priceRows = $this->conn->fetchAllAssociative(
                "SELECT a.isin, p.price_minor, p.currency
                 FROM prices p
                 INNER JOIN assets a ON a.id = p.asset_id
                 INNER JOIN (
                    SELECT asset_id, MAX(occurred_at) AS m FROM prices GROUP BY asset_id
                 ) latest ON latest.asset_id = p.asset_id AND latest.m = p.occurred_at
                 WHERE a.isin IN ($placeholders)",
                $isins,
            );
            $priceByIsin = [];
            foreach ($priceRows as $pr) {
                $priceByIsin[$pr['isin']] = $pr;
            }

            foreach ($holdingRows as $h) {
                $price = $priceByIsin[$h['asset_isin']] ?? null;
                if ($price === null) continue;
                $qty = (float) $h['qty'];
                $native = (int) round($qty * (int) $price['price_minor']);
                $ccy = $price['currency'];
                $nativeByCcy[$ccy] = ($nativeByCcy[$ccy] ?? 0) + $native;
            }
        }

        $out = [];
        foreach ($nativeByCcy as $ccy => $native) {
            if ($native === 0) continue;
            $base = $this->fx->convertMinor($native, $ccy, $baseCurrency, $today) ?? $native;
            $out[] = [
                'currency' => $ccy,
                'valueNativeMinor' => (string) $native,
                'valueBaseMinor' => (string) $base,
            ];
        }
        usort($out, fn($a, $b) => (int) $b['valueBaseMinor'] <=> (int) $a['valueBaseMinor']);
        return $out;
    }

    /**
     * Stacked history of base-currency value per source currency. For each
     * sample date, computes cash-balance-per-currency + holdings-value-per-
     * price-currency, all converted to base at the FX rate effective on that
     * date.
     *
     * Currencies are returned as a list of series; each series has its own
     * date-aligned values array. The dates list is shared.
     *
     * @return array{
     *   dates: list<string>,
     *   series: list<array{currency: string, values: list<string>}>
     * }
     */
    public function currencyExposureHistory(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity,
        string $baseCurrency,
    ): array {
        $ownerBin = $user->getId()->toBinary();

        $tx = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency, t.type, t.asset_isin, t.asset_quantity
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             ORDER BY t.occurred_at ASC, t.id ASC",
            ['owner' => $ownerBin],
        );

        if ($tx === []) {
            return ['dates' => [], 'series' => []];
        }

        $firstTxDate = new \DateTimeImmutable($tx[0]['occurred_at']);
        if ($from < $firstTxDate) $from = $firstTxDate;

        // Load price + FX history per asset (and convert via FX-on-date).
        $isins = array_values(array_unique(array_filter(array_column($tx, 'asset_isin'))));
        $pricesByIsin = $this->loadPricesByIsin($isins);
        // Currencies needed for FX: every cash currency + every price currency.
        $cashCurrencies = array_values(array_unique(array_column($tx, 'currency')));
        $priceCurrencies = [];
        foreach ($pricesByIsin as $rows) {
            foreach ($rows as $r) $priceCurrencies[] = $r['currency'];
        }
        $allCcys = array_values(array_unique(array_merge($cashCurrencies, $priceCurrencies)));
        $fxByPair = $this->loadFxRates($allCcys, $baseCurrency);

        $dates = $this->sampleDates($from, $to, $granularity);

        // Running state across the sweep: cumulative cash by currency, cumulative qty by ISIN.
        $cashByCcy = [];   // ccy => native minor int
        $qtyByIsin = [];   // isin => decimal string
        $txIdx = 0;
        $txCount = count($tx);

        // Per-currency value series, base minor, parallel to $dates.
        $valuesByCcy = []; // ccy => list<int>

        foreach ($dates as $i => $date) {
            $dateStr = $date->format('Y-m-d');
            while ($txIdx < $txCount && $tx[$txIdx]['occurred_at'] <= $dateStr) {
                $row = $tx[$txIdx];
                $ccy = $row['currency'];
                $cashByCcy[$ccy] = ($cashByCcy[$ccy] ?? 0) + (int) $row['amount_minor'];
                if ($row['asset_isin'] !== null && $row['asset_quantity'] !== null
                    && in_array($row['type'], ['trade_buy', 'trade_sell'], true)
                ) {
                    $isin = $row['asset_isin'];
                    $qtyByIsin[$isin] = bcadd($qtyByIsin[$isin] ?? '0', $row['asset_quantity'], 8);
                }
                $txIdx++;
            }

            // Bucket: base value per source currency.
            $bucket = []; // ccy => int (base minor)

            // Cash contribution.
            foreach ($cashByCcy as $ccy => $minor) {
                if ($minor === 0) continue;
                $base = $this->convertOnDate($minor, $ccy, $baseCurrency, $dateStr, $fxByPair);
                $bucket[$ccy] = ($bucket[$ccy] ?? 0) + $base;
            }

            // Holdings contribution (grouped by price currency).
            foreach ($qtyByIsin as $isin => $qty) {
                if (bccomp($qty, '0', 8) === 0) continue;
                $priceEntry = $this->findOnOrBefore($pricesByIsin[$isin] ?? [], $dateStr);
                if ($priceEntry === null) continue;
                $native = (float) $qty * ($priceEntry['price_minor'] / 100);
                $ccy = $priceEntry['currency'];
                $baseVal = $ccy === $baseCurrency
                    ? (int) round($native * 100)
                    : $this->convertOnDate((int) round($native * 100), $ccy, $baseCurrency, $dateStr, $fxByPair);
                $bucket[$ccy] = ($bucket[$ccy] ?? 0) + $baseVal;
            }

            // Record into per-currency series.
            foreach ($bucket as $ccy => $minor) {
                if (!isset($valuesByCcy[$ccy])) {
                    $valuesByCcy[$ccy] = array_fill(0, count($dates), 0);
                }
                $valuesByCcy[$ccy][$i] = $minor;
            }
        }

        // Sort currencies by their most recent value (largest first).
        $lastIndex = count($dates) - 1;
        uksort($valuesByCcy, function ($a, $b) use ($valuesByCcy, $lastIndex) {
            return ($valuesByCcy[$b][$lastIndex] ?? 0) <=> ($valuesByCcy[$a][$lastIndex] ?? 0);
        });

        $series = [];
        foreach ($valuesByCcy as $ccy => $vals) {
            $series[] = [
                'currency' => $ccy,
                'values' => array_map(fn($v) => (string) $v, $vals),
            ];
        }

        return [
            'dates' => array_map(fn($d) => $d->format('Y-m-d'), $dates),
            'series' => $series,
        ];
    }

    /** @return list<\DateTimeImmutable> */
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
        $last = end($out);
        if ($last === false || $last < $to) $out[] = $to;
        return $out;
    }

    /**
     * @param list<string> $isins
     * @return array<string, list<array{date:string,price_minor:int,currency:string}>>
     */
    private function loadPricesByIsin(array $isins): array
    {
        if ($isins === []) return [];
        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $rows = $this->conn->fetchAllAssociative(
            "SELECT a.isin, p.occurred_at AS date, p.price_minor, p.currency
             FROM prices p INNER JOIN assets a ON a.id = p.asset_id
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
     * @param list<string> $fromCcys
     * @return array<string, list<array{date:string,rate:float}>>
     */
    private function loadFxRates(array $fromCcys, string $toCurrency): array
    {
        $fromCcys = array_values(array_filter($fromCcys, fn($c) => $c !== $toCurrency));
        if ($fromCcys === []) return [];
        $placeholders = implode(',', array_fill(0, count($fromCcys), '?'));
        $params = array_merge([$toCurrency], $fromCcys);
        $rows = $this->conn->fetchAllAssociative(
            "SELECT from_currency, occurred_at AS date, rate
             FROM fx_rates
             WHERE to_currency = ? AND from_currency IN ($placeholders)
             ORDER BY from_currency, occurred_at ASC",
            $params,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['from_currency']][] = ['date' => $r['date'], 'rate' => (float) $r['rate']];
        }
        return $out;
    }

    /** @param array<string, list<array{date:string,rate:float}>> $fxByPair */
    private function convertOnDate(int $amount, string $from, string $to, string $dateStr, array $fxByPair): int
    {
        if ($from === $to) return $amount;
        $series = $fxByPair[$from] ?? [];
        $entry = $this->findOnOrBefore($series, $dateStr);
        if ($entry === null) return $amount; // fall back to raw amount, matches TimeSeriesService policy
        return (int) round($amount * $entry['rate']);
    }

    /**
     * @param list<array{date:string,...}> $sorted
     * @return array{date:string, ...}|null
     */
    private function findOnOrBefore(array $sorted, string $target): ?array
    {
        $count = count($sorted);
        if ($count === 0 || $sorted[0]['date'] > $target) return null;
        $lo = 0; $hi = $count - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($sorted[$mid]['date'] <= $target) $lo = $mid;
            else $hi = $mid - 1;
        }
        return $sorted[$lo];
    }

    /**
     * Pure FX P&L per currency, on the user's CURRENT total exposure
     * (cash + holdings priced in the currency, at today's native price).
     *
     * Formula per currency X:
     *   FX P&L = current_exposure_native × (today_FX − avg_acquisition_FX)
     *
     * where `avg_acquisition_FX` is the weighted-average rate (base per unit
     * native) at which the user historically ACQUIRED X — i.e. the CHF they
     * actually committed per USD obtained.
     *
     * Acquisition events:
     *   - Non-trade positive flows in X (deposits, dividends, interest, FX
     *     conversions INTO X). Each contributes `amount` to native and
     *     `amount × FX_at_date` to committed cost.
     *   - The asset-side of cross-currency stock purchases (e.g. CHF
     *     account buying USD stock): contributes the converted USD amount
     *     to native, and the CHF cash leg to committed cost. This is what
     *     "I bought a USD stock 2 years ago" gives us — both the USD
     *     exposure acquired and the actual CHF paid.
     *
     * Release events (negative flows, sells, conversions out) reduce the
     * native balance but DON'T change the weighted average. This is standard
     * cost-basis accounting and means the figure is purely unrealized P&L on
     * the exposure still held.
     *
     * Why this matches the user's "buy USD stock 2 years ago" intuition:
     *   - The original $X of USD exposure was acquired at FX `avg_FX`.
     *   - If the stock has since appreciated, current exposure is now > $X
     *     in USD — and ALL of that USD revalues at today's FX. So a stock
     *     gain in a strong currency amplifies FX P&L.
     *   - This is what "current exposure × FX delta" captures.
     *
     * @return list<array{currency: string, exposureNativeMinor: string, exposureBaseMinor: string, acquiredNativeMinor: string, committedCostBaseMinor: string, avgFxRate: ?float, todayFxRate: ?float, fxPnlBaseMinor: string}>
     */
    public function fxGainByCurrency(User $user, string $baseCurrency): array
    {
        $ownerBin = $user->getId()->toBinary();
        $today = new \DateTimeImmutable('today');

        // === 1. Current native exposure per currency: cash + holdings native value. ===
        $balanceRows = $this->conn->fetchAllAssociative(
            "SELECT t.currency, COALESCE(SUM(t.amount_minor), 0) AS minor
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             GROUP BY t.currency",
            ['owner' => $ownerBin],
        );
        $exposureNative = [];
        foreach ($balanceRows as $r) {
            $minor = (int) $r['minor'];
            if ($minor !== 0) {
                $exposureNative[$r['currency']] = ($exposureNative[$r['currency']] ?? 0) + $minor;
            }
        }

        // Holdings (qty × latest price in price currency), bucketed by price currency.
        $holdingRows = $this->conn->fetchAllAssociative(
            "SELECT t.asset_isin, SUM(t.asset_quantity) AS qty
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
               AND t.asset_isin IS NOT NULL AND t.asset_quantity IS NOT NULL
             GROUP BY t.asset_isin
             HAVING qty <> 0",
            ['owner' => $ownerBin],
        );
        if ($holdingRows !== []) {
            $isins = array_column($holdingRows, 'asset_isin');
            $placeholders = implode(',', array_fill(0, count($isins), '?'));
            $priceRows = $this->conn->fetchAllAssociative(
                "SELECT a.isin, p.price_minor, p.currency
                 FROM prices p
                 INNER JOIN assets a ON a.id = p.asset_id
                 INNER JOIN (
                    SELECT asset_id, MAX(occurred_at) AS m FROM prices GROUP BY asset_id
                 ) latest ON latest.asset_id = p.asset_id AND latest.m = p.occurred_at
                 WHERE a.isin IN ($placeholders)",
                $isins,
            );
            $priceByIsin = [];
            foreach ($priceRows as $pr) $priceByIsin[$pr['isin']] = $pr;

            foreach ($holdingRows as $h) {
                $price = $priceByIsin[$h['asset_isin']] ?? null;
                if ($price === null) continue;
                $native = (int) round((float) $h['qty'] * (int) $price['price_minor']);
                $ccy = $price['currency'];
                $exposureNative[$ccy] = ($exposureNative[$ccy] ?? 0) + $native;
            }
        }

        // === 2. Acquisition tracking: positive-native legs only. ===
        $assetCcyByIsin = [];
        $assetRows = $this->conn->fetchAllAssociative(
            "SELECT a.isin, a.currency
             FROM assets a
             INNER JOIN (
                SELECT DISTINCT t.asset_isin
                FROM transactions t
                INNER JOIN accounts ac ON ac.id = t.account_id
                WHERE ac.owner_id = :owner AND t.asset_isin IS NOT NULL
             ) used ON used.asset_isin = a.isin",
            ['owner' => $ownerBin],
        );
        foreach ($assetRows as $a) {
            if ($a['currency'] !== null) {
                $assetCcyByIsin[$a['isin']] = $a['currency'];
            }
        }

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency, t.type, t.asset_isin
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             ORDER BY t.occurred_at ASC",
            ['owner' => $ownerBin],
        );

        $acquiredNative = [];   // ccy => positive native minor units acquired
        $committedCost = [];    // ccy => corresponding base cost (always positive)

        foreach ($rows as $r) {
            $type = $r['type'];
            $txCcy = $r['currency'];
            $amount = (int) $r['amount_minor'];
            $date = new \DateTimeImmutable($r['occurred_at']);
            $baseAmount = $this->fx->convertMinor($amount, $txCcy, $baseCurrency, $date) ?? $amount;

            $isTrade = in_array($type, ['trade_buy', 'trade_sell'], true);
            $assetCcy = $isTrade ? ($assetCcyByIsin[$r['asset_isin']] ?? null) : null;

            $legs = [];
            if ($isTrade && $assetCcy !== null && $assetCcy !== $txCcy) {
                // Cross-currency trade: cash leg + asset leg.
                $legs[] = ['ccy' => $txCcy, 'native' => $amount, 'base' => $baseAmount];
                $assetNative = $this->fx->convertMinor(-$amount, $txCcy, $assetCcy, $date);
                if ($assetNative !== null) {
                    $legs[] = ['ccy' => $assetCcy, 'native' => $assetNative, 'base' => -$baseAmount];
                }
            } elseif ($isTrade) {
                continue; // intra-currency trade — no FX event
            } else {
                $legs[] = ['ccy' => $txCcy, 'native' => $amount, 'base' => $baseAmount];
            }

            foreach ($legs as $leg) {
                if ($leg['native'] > 0) {
                    $acquiredNative[$leg['ccy']] = ($acquiredNative[$leg['ccy']] ?? 0) + $leg['native'];
                    $committedCost[$leg['ccy']] = ($committedCost[$leg['ccy']] ?? 0) + abs($leg['base']);
                }
                // Releases don't change the weighted average.
            }
        }

        // === 3. Compute FX P&L per currency. ===
        $out = [];
        $allCcys = array_unique(array_merge(array_keys($exposureNative), array_keys($acquiredNative)));
        foreach ($allCcys as $ccy) {
            $exposure = $exposureNative[$ccy] ?? 0;
            $aqNative = $acquiredNative[$ccy] ?? 0;
            $cost = $committedCost[$ccy] ?? 0;
            if ($exposure === 0 && $aqNative === 0) continue;

            $exposureBase = $ccy === $baseCurrency
                ? $exposure
                : ($exposure === 0 ? 0 : ($this->fx->convertMinor($exposure, $ccy, $baseCurrency, $today) ?? 0));

            // FX rates expressed as base-per-unit-native (e.g. CHF per USD).
            $avgFx = $aqNative > 0 ? $cost / $aqNative : null;
            $todayFx = $exposure !== 0 ? $exposureBase / $exposure : null;

            $fxPnl = 0;
            if ($ccy !== $baseCurrency && $avgFx !== null && $todayFx !== null && $exposure !== 0) {
                $fxPnl = (int) round($exposure * ($todayFx - $avgFx));
            }

            $out[] = [
                'currency' => $ccy,
                'exposureNativeMinor' => (string) $exposure,
                'exposureBaseMinor' => (string) $exposureBase,
                'acquiredNativeMinor' => (string) $aqNative,
                'committedCostBaseMinor' => (string) $cost,
                'avgFxRate' => $avgFx,
                'todayFxRate' => $todayFx,
                'fxPnlBaseMinor' => (string) $fxPnl,
            ];
        }
        usort($out, function ($a, $b) use ($baseCurrency) {
            if ($a['currency'] === $baseCurrency) return -1;
            if ($b['currency'] === $baseCurrency) return 1;
            return abs((int) $b['exposureBaseMinor']) <=> abs((int) $a['exposureBaseMinor']);
        });
        return $out;
    }

    /**
     * Monthly fee totals, in base currency, plus a per-account breakdown.
     *
     * @return array{
     *   monthly: list<array{month: string, amountBaseMinor: string}>,
     *   byAccount: list<array{accountId: string, accountName: string, amountBaseMinor: string, count: int}>,
     *   totalBaseMinor: string,
     *   ytdBaseMinor: string,
     *   lifetimeBaseMinor: string,
     *   baseCurrency: string
     * }
     */
    public function fees(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, string $baseCurrency): array
    {
        $ownerBin = $user->getId()->toBinary();

        $allRows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency, t.account_id, ac.name AS account_name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner AND t.type = 'fee'
             ORDER BY t.occurred_at ASC",
            ['owner' => $ownerBin],
        );

        $monthly = []; // YYYY-MM => base minor (int)
        $byAccount = []; // accountId rfc4122 => ['name'=>, 'minor'=>, 'count'=>]
        $lifetime = 0;
        $ytd = 0;
        $rangeTotal = 0;
        $year = (new \DateTimeImmutable('today'))->format('Y');
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        foreach ($allRows as $r) {
            $amount = (int) $r['amount_minor'];
            $baseAmount = $this->fx->convertMinor($amount, $r['currency'], $baseCurrency, new \DateTimeImmutable($r['occurred_at'])) ?? $amount;
            $lifetime += $baseAmount;
            if (substr($r['occurred_at'], 0, 4) === $year) {
                $ytd += $baseAmount;
            }

            // Only include in monthly/byAccount when within the requested range.
            $inRange = $r['occurred_at'] >= $fromStr && $r['occurred_at'] <= $toStr;
            if (!$inRange) continue;
            $rangeTotal += $baseAmount;

            $month = substr($r['occurred_at'], 0, 7);
            $monthly[$month] = ($monthly[$month] ?? 0) + $baseAmount;

            $accountId = \Symfony\Component\Uid\Uuid::fromBinary($r['account_id'])->toRfc4122();
            if (!isset($byAccount[$accountId])) {
                $byAccount[$accountId] = ['name' => $r['account_name'], 'minor' => 0, 'count' => 0];
            }
            $byAccount[$accountId]['minor'] += $baseAmount;
            $byAccount[$accountId]['count']++;
        }

        // Fill missing months in the range for a continuous axis.
        $monthlyFilled = [];
        $cursor = $from->modify('first day of this month');
        $end = $to->modify('first day of this month');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $monthlyFilled[] = ['month' => $key, 'amountBaseMinor' => (string) ($monthly[$key] ?? 0)];
            $cursor = $cursor->modify('first day of next month');
        }

        $byAccountList = [];
        foreach ($byAccount as $id => $a) {
            $byAccountList[] = [
                'accountId' => $id,
                'accountName' => $a['name'],
                'amountBaseMinor' => (string) $a['minor'],
                'count' => $a['count'],
            ];
        }
        // Most fees first (most-negative).
        usort($byAccountList, fn($a, $b) => (int) $a['amountBaseMinor'] <=> (int) $b['amountBaseMinor']);

        return [
            'monthly' => $monthlyFilled,
            'byAccount' => $byAccountList,
            'totalBaseMinor' => (string) $rangeTotal,
            'ytdBaseMinor' => (string) $ytd,
            'lifetimeBaseMinor' => (string) $lifetime,
            'baseCurrency' => $baseCurrency,
        ];
    }

    /**
     * Monthly dividend totals plus per-asset breakdown and a 12-month forward
     * projection based on each holding's past payment cadence and average size.
     *
     * @return array{
     *   monthly: list<array{month: string, amountBaseMinor: string}>,
     *   byAsset: list<array{isin: string, ticker: ?string, name: ?string, amountBaseMinor: string, count: int}>,
     *   forecast: list<array{month: string, amountBaseMinor: string}>,
     *   totalBaseMinor: string,
     *   ytdBaseMinor: string,
     *   lifetimeBaseMinor: string,
     *   forecastTotalBaseMinor: string,
     *   baseCurrency: string
     * }
     */
    public function dividends(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, string $baseCurrency): array
    {
        $ownerBin = $user->getId()->toBinary();

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency, t.asset_isin,
                    a.ticker, a.name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             LEFT JOIN assets a ON a.isin = t.asset_isin
             WHERE ac.owner_id = :owner AND t.type = 'dividend'
             ORDER BY t.occurred_at ASC",
            ['owner' => $ownerBin],
        );

        $monthly = []; // YYYY-MM => base minor
        $byAsset = []; // isin => ['ticker','name','minor','count','dates'=>list of YYYY-MM-DD,'amounts'=>list base minor]
        $lifetime = 0;
        $ytd = 0;
        $rangeTotal = 0;
        $year = (new \DateTimeImmutable('today'))->format('Y');
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        foreach ($rows as $r) {
            $amount = (int) $r['amount_minor'];
            $baseAmount = $this->fx->convertMinor($amount, $r['currency'], $baseCurrency, new \DateTimeImmutable($r['occurred_at'])) ?? $amount;
            $lifetime += $baseAmount;
            if (substr($r['occurred_at'], 0, 4) === $year) {
                $ytd += $baseAmount;
            }

            $isin = $r['asset_isin'] ?? 'UNKNOWN';
            if (!isset($byAsset[$isin])) {
                $byAsset[$isin] = [
                    'ticker' => $r['ticker'],
                    'name' => $r['name'],
                    'minor' => 0,
                    'count' => 0,
                    'dates' => [],
                    'amounts' => [],
                ];
            }
            $byAsset[$isin]['dates'][] = $r['occurred_at'];
            $byAsset[$isin]['amounts'][] = $baseAmount;

            $inRange = $r['occurred_at'] >= $fromStr && $r['occurred_at'] <= $toStr;
            if ($inRange) {
                $rangeTotal += $baseAmount;
                $month = substr($r['occurred_at'], 0, 7);
                $monthly[$month] = ($monthly[$month] ?? 0) + $baseAmount;
                $byAsset[$isin]['minor'] += $baseAmount;
                $byAsset[$isin]['count']++;
            }
        }

        // Fill missing months for a continuous axis.
        $monthlyFilled = [];
        $cursor = $from->modify('first day of this month');
        $end = $to->modify('first day of this month');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $monthlyFilled[] = ['month' => $key, 'amountBaseMinor' => (string) ($monthly[$key] ?? 0)];
            $cursor = $cursor->modify('first day of next month');
        }

        $byAssetList = [];
        foreach ($byAsset as $isin => $a) {
            if ($a['count'] === 0) continue; // none in range
            $byAssetList[] = [
                'isin' => $isin,
                'ticker' => $a['ticker'],
                'name' => $a['name'],
                'amountBaseMinor' => (string) $a['minor'],
                'count' => $a['count'],
            ];
        }
        usort($byAssetList, fn($a, $b) => (int) $b['amountBaseMinor'] <=> (int) $a['amountBaseMinor']);

        // Forecast: for each asset we still hold, project the next 12 months
        // using past payment cadence (median interval between consecutive
        // payments) and average payment size. Filter to assets where we still
        // hold a positive quantity.
        $heldIsins = $this->currentlyHeldIsins($user);
        [$forecast, $forecastTotal] = $this->forecastNext12($byAsset, $heldIsins);

        return [
            'monthly' => $monthlyFilled,
            'byAsset' => $byAssetList,
            'forecast' => $forecast,
            'totalBaseMinor' => (string) $rangeTotal,
            'ytdBaseMinor' => (string) $ytd,
            'lifetimeBaseMinor' => (string) $lifetime,
            'forecastTotalBaseMinor' => (string) $forecastTotal,
            'baseCurrency' => $baseCurrency,
        ];
    }

    /** @return array<string, true> */
    private function currentlyHeldIsins(User $user): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.asset_isin, SUM(t.asset_quantity) AS qty
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND t.asset_isin IS NOT NULL AND t.asset_quantity IS NOT NULL
             GROUP BY t.asset_isin
             HAVING qty <> 0",
            ['owner' => $user->getId()->toBinary()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['asset_isin']] = true;
        }
        return $out;
    }

    /**
     * @param array<string, array{ticker:?string,name:?string,minor:int,count:int,dates:list<string>,amounts:list<int>}> $byAsset
     * @param array<string, true> $heldIsins
     * @return array{0: list<array{month: string, amountBaseMinor: string}>, 1: int}
     */
    private function forecastNext12(array $byAsset, array $heldIsins): array
    {
        $today = new \DateTimeImmutable('today');
        $horizon = $today->modify('+12 months');

        $byMonth = [];
        $cursor = $today->modify('first day of next month');
        while ($cursor < $horizon) {
            $byMonth[$cursor->format('Y-m')] = 0;
            $cursor = $cursor->modify('first day of next month');
        }

        foreach ($byAsset as $isin => $a) {
            if (!isset($heldIsins[$isin])) continue;
            if (count($a['dates']) < 1) continue;

            // Average payment size — solid signal even with few data points.
            $avg = array_sum($a['amounts']) / count($a['amounts']);

            // Cadence: median interval between consecutive payments (in days).
            // If only one payment in history, assume annual (most common cadence
            // for dividends and a safer default than monthly).
            $cadenceDays = 365;
            if (count($a['dates']) >= 2) {
                $intervals = [];
                $prev = null;
                foreach ($a['dates'] as $d) {
                    $dt = new \DateTimeImmutable($d);
                    if ($prev !== null) {
                        $intervals[] = (int) $dt->diff($prev)->format('%a');
                    }
                    $prev = $dt;
                }
                if ($intervals !== []) {
                    sort($intervals);
                    $cadenceDays = $intervals[(int) (count($intervals) / 2)];
                }
            }
            // Guard against pathological cadences.
            $cadenceDays = max(7, $cadenceDays);

            // Project from the last payment + cadence into the 12-month window.
            $last = new \DateTimeImmutable($a['dates'][count($a['dates']) - 1]);
            $next = $last->modify('+' . $cadenceDays . ' days');
            while ($next < $today) {
                $next = $next->modify('+' . $cadenceDays . ' days');
            }
            while ($next < $horizon) {
                $key = $next->format('Y-m');
                if (isset($byMonth[$key])) {
                    $byMonth[$key] += (int) round($avg);
                }
                $next = $next->modify('+' . $cadenceDays . ' days');
            }
        }

        $out = [];
        $total = 0;
        foreach ($byMonth as $month => $minor) {
            $out[] = ['month' => $month, 'amountBaseMinor' => (string) $minor];
            $total += $minor;
        }
        return [$out, $total];
    }
}
