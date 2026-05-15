<?php

namespace App\Fx;

use Doctrine\DBAL\Connection;

/**
 * Historical-FX converter. Given an amount in some currency on a specific date,
 * returns the equivalent in a base currency using the FX rate effective on that
 * date (or the closest earlier rate).
 *
 * For performance, currency pairs are loaded lazily and cached on the instance:
 * looking up "convert USD→CHF on 2024-06-15" only hits the DB once for the
 * whole USD→CHF history; subsequent lookups for the same pair are binary
 * searches in memory.
 *
 * Direct A→B is preferred; if missing, try the inverse B→A.
 */
final class FxConverter
{
    /** @var array<string, list<array{date: string, rate: float}>> */
    private array $cache = [];

    public function __construct(private readonly Connection $conn) {}

    /**
     * Convert $amountMinor (integer minor units) from $fromCurrency to $baseCurrency
     * using the rate effective on $date. Returns null if no rate is known.
     */
    public function convertMinor(int|string $amountMinor, string $fromCurrency, string $baseCurrency, \DateTimeImmutable $date): ?int
    {
        if (strtoupper($fromCurrency) === strtoupper($baseCurrency)) {
            return (int) $amountMinor;
        }
        $rate = $this->rate($fromCurrency, $baseCurrency, $date);
        if ($rate === null) {
            return null;
        }
        return (int) round((int) $amountMinor * $rate);
    }

    /** Try direct rate first, then the inverse if direct is missing. */
    public function rate(string $from, string $to, \DateTimeImmutable $date): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }
        $direct = $this->lookup($from, $to, $date);
        if ($direct !== null) {
            return $direct;
        }
        $inverse = $this->lookup($to, $from, $date);
        return $inverse === null ? null : 1.0 / $inverse;
    }

    private function lookup(string $from, string $to, \DateTimeImmutable $date): ?float
    {
        $series = $this->seriesFor($from, $to);
        if ($series === []) {
            return null;
        }
        $target = $date->format('Y-m-d');
        // Binary search for the latest entry whose date <= target.
        $lo = 0;
        $hi = count($series) - 1;
        if ($series[0]['date'] > $target) {
            return null;
        }
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($series[$mid]['date'] <= $target) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $series[$lo]['rate'];
    }

    /** @return list<array{date: string, rate: float}> */
    private function seriesFor(string $from, string $to): array
    {
        $key = $from . '|' . $to;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $rows = $this->conn->fetchAllAssociative(
            'SELECT occurred_at AS date, rate FROM fx_rates
             WHERE from_currency = :f AND to_currency = :t
             ORDER BY occurred_at ASC',
            ['f' => $from, 't' => $to],
        );
        $series = array_map(fn($r) => [
            'date' => $r['date'],
            'rate' => (float) $r['rate'],
        ], $rows);
        $this->cache[$key] = $series;
        return $series;
    }
}
