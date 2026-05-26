<?php

namespace App\Price;

final class PreMarketQuote
{
    public function __construct(
        /** Price in minor units (× 100). */
        public readonly int $priceMinor,
        public readonly string $currency,
        /** Percentage change vs. previous close (e.g. 1.23 means +1.23%). */
        public readonly float $changePct,
        public readonly \DateTimeImmutable $asOf,
    ) {}
}
