<?php

namespace App\TimeSeries;

final class TimeSeriesPoint
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $cashMinor,
        public readonly string $holdingsMinor,
        public readonly string $totalMinor,
        /** Cumulative net external cash flow (income − withdrawals, excluding trade
         *  legs/FX). For an account chart, the gap between $totalMinor and this is
         *  investment gain/loss. */
        public readonly string $netDepositsMinor = '0',
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'cashMinor' => $this->cashMinor,
            'holdingsMinor' => $this->holdingsMinor,
            'totalMinor' => $this->totalMinor,
            'netDepositsMinor' => $this->netDepositsMinor,
        ];
    }
}
