<?php

namespace App\TimeSeries;

use App\Entity\Account;
use App\Entity\User;

/**
 * Derives return-over-time metrics on top of a TimeSeriesPoint series.
 *
 *  - returnVsDepositsPct: (value − netDeposits) / netDeposits × 100. Intuitive
 *    "how much is my money up" number. Distorted by withdrawals (denominator shrinks).
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
