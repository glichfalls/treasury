<?php

namespace App\TimeSeries;

use App\Entity\Account;
use App\Entity\User;
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
    public function __construct(private readonly Connection $conn) {}

    /** Expose the connection so sibling services can run ad-hoc queries through us. */
    public function getConnection(): Connection
    {
        return $this->conn;
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

        // Collect ISINs we'll need to look up prices for.
        $isins = [];
        foreach ($tx as $r) {
            if ($r['asset_isin'] !== null) {
                $isins[$r['asset_isin']] = true;
            }
        }

        $pricesByIsin = $this->loadPrices(array_keys($isins));
        $fxByPair = $this->loadFxRates($displayCurrency, $pricesByIsin);

        $dates = $this->sampleDates($from, $to, $granularity);
        $points = [];

        $cashCum = 0;
        $qtyByIsin = [];   // isin => cumulative qty (decimal string)
        $txIdx = 0;
        $txCount = count($tx);

        foreach ($dates as $date) {
            $dateStr = $date->format('Y-m-d');

            while ($txIdx < $txCount && $tx[$txIdx]['occurred_at'] <= $dateStr) {
                $row = $tx[$txIdx];
                $cashCum += (int) $row['amount_minor'];
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
            );
        }

        return $points;
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
