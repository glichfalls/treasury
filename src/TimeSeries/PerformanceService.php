<?php

namespace App\TimeSeries;

use App\Entity\Account;
use App\Entity\User;

/**
 * Derives return-over-time metrics on top of a TimeSeriesPoint series.
 *
 *  - returnVsDepositsPct: lifetime (value − netDeposits) / netDeposits × 100.
 *    Cumulative "how much is my money up vs what I put in" number — does NOT
 *    represent return inside the chart window. Distorted by withdrawals.
 *
 *  - twrPct: Time-weighted return, compounded across periods inside the chart
 *    window with external cash flows stripped out per period. Comparable
 *    across portfolios regardless of contribution timing and is the right
 *    metric for "performance during this period".
 *      r_i = (V_i − C_i) / V_{i-1}      where C_i = netDeposits_i − netDeposits_{i-1}
 *      cumTWR = Π(1 + r_i) − 1
 *
 * Earlier this service rebased vsDeposits to 0% at the window start so short
 * windows would look "alive". That was wrong: vsDeposits is a ratio against
 * deposits, so its delta over a window inflates the true period return by
 * (V/D) — the carry of unrealized gains. TWR is the correct period metric.
 */
final class PerformanceService
{
    public function __construct(private readonly TimeSeriesService $series) {}

    /** @return PerformancePoint[] */
    public function forUser(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity = 'daily',
    ): array {
        return $this->compute($this->series->netWorthSeries($user, $from, $to, $granularity));
    }

    /** @return PerformancePoint[] */
    public function forAccount(
        Account $account,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity = 'daily',
    ): array {
        return $this->compute($this->series->accountSeries($account, $from, $to, $granularity));
    }

    /**
     * @param TimeSeriesPoint[] $series
     * @return PerformancePoint[]
     */
    private function compute(array $series): array
    {
        $cumTwr = 1.0;
        $prev = null;
        $out = [];

        foreach ($series as $p) {
            $total = (float) $p->totalMinor;
            $deposits = (float) $p->netDepositsMinor;

            $vsDeposits = $deposits > 0
                ? (($total - $deposits) / $deposits) * 100
                : null;

            if ($prev !== null) {
                $prevTotal = (float) $prev->totalMinor;
                $periodFlow = $deposits - (float) $prev->netDepositsMinor;
                if ($prevTotal > 0) {
                    $periodReturn = ($total - $periodFlow - $prevTotal) / $prevTotal;
                    $cumTwr *= (1 + $periodReturn);
                }
                // If prevTotal <= 0 we can't compute a return for this period; leave cumTwr unchanged.
                $twrPct = ($cumTwr - 1) * 100;
            } else {
                $twrPct = 0.0;
            }

            $out[] = new PerformancePoint(
                date: $p->date,
                returnVsDepositsPct: $vsDeposits,
                twrPct: $twrPct,
            );
            $prev = $p;
        }

        return $out;
    }
}
