<?php

namespace App\Plan;

enum PlanWindow: string
{
    case OneYear = '1y';
    case ThreeYears = '3y';
    case FiveYears = '5y';
    case Inception = 'inception';

    public static function fromQuery(?string $value): self
    {
        return match ($value) {
            '1y' => self::OneYear,
            '5y' => self::FiveYears,
            'inception' => self::Inception,
            default => self::ThreeYears,
        };
    }

    public function startDate(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return match ($this) {
            self::OneYear => $today->modify('-1 year'),
            self::ThreeYears => $today->modify('-3 years'),
            self::FiveYears => $today->modify('-5 years'),
            self::Inception => new \DateTimeImmutable('1970-01-01'),
        };
    }
}
