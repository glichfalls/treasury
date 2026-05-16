<?php

namespace App\TimeSeries;

use App\Entity\Account;
use App\Entity\User;

/**
 * Derives return-over-time metrics on top of a TimeSeriesPoint series.
 *
 * Both metrics are rebased to 0% at the first point of the requested window, so
 * the chart always shows performance *during* the window (a 1-week chart shows
 * that week's return, not lifetime return).
 *
 *  - returnVsDepositsPct: lifetime (value − netDeposits) / netDeposits × 100,
 *    minus the same ratio at the window's start. Intuitive "how much is my money
 *    up over this period" number. Distorted by deposits/withdrawals during the
 *    window (denominator shifts) — prefer TWR when external flows occur.
 *
 *  - twrPct: Time-weighted return, compounded across periods, with external
 *    cash flows stripped out per period. Comparable across portfolios regardless
 *    of contribution timing.
 *      r_i = (V_i − C_i) / V_{i-1}      where C_i = netDeposits_i − netDeposits_{i-1}
 *      cumTWR = Π(1 + r_i) − 1
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
        $vsDepositsBaseline = null;
        $out = [];

        foreach ($series as $p) {
            $total = (float) $p->totalMinor;
            $deposits = (float) $p->netDepositsMinor;

            $vsDepositsLifetime = $deposits > 0
                ? (($total - $deposits) / $deposits) * 100
                : null;

            if ($vsDepositsLifetime !== null) {
                if ($vsDepositsBaseline === null) {
                    $vsDepositsBaseline = $vsDepositsLifetime;
                }
                $vsDeposits = $vsDepositsLifetime - $vsDepositsBaseline;
            } else {
                $vsDeposits = null;
            }

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
