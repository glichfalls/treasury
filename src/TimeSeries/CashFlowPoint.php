<?php

namespace App\TimeSeries;

final class CashFlowPoint
{
    public function __construct(
        public readonly \DateTimeImmutable $month,
        public readonly string $incomeMinor,
        public readonly string $expenseMinor,
    ) {}

    public function toArray(): array
    {
        return [
            'month' => $this->month->format('Y-m'),
            'incomeMinor' => $this->incomeMinor,
            'expenseMinor' => $this->expenseMinor,
        ];
    }
}
