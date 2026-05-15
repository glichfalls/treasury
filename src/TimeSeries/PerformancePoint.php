<?php

namespace App\TimeSeries;

final class PerformancePoint
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        /** Null until cumulative net deposits become > 0 (the ratio is undefined). */
        public readonly ?float $returnVsDepositsPct,
        public readonly float $twrPct,
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'returnVsDepositsPct' => $this->returnVsDepositsPct === null
                ? null
                : round($this->returnVsDepositsPct, 4),
            'twrPct' => round($this->twrPct, 4),
        ];
    }
}
