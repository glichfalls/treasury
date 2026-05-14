<?php

namespace App\TimeSeries;

final class TimeSeriesPoint
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $cashMinor,
        public readonly string $holdingsMinor,
        public readonly string $totalMinor,
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'cashMinor' => $this->cashMinor,
            'holdingsMinor' => $this->holdingsMinor,
            'totalMinor' => $this->totalMinor,
        ];
    }
}
