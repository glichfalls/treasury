<?php

namespace App\Price;

final class PriceQuote
{
    public function __construct(
        public readonly float $price,
        public readonly string $currency,
        public readonly \DateTimeImmutable $asOf,
        /** Yahoo-style ticker that was actually queried (after ISIN resolution, etc.). */
        public readonly string $resolvedTicker,
        /** Human-readable name reported by the provider, when available. */
        public readonly ?string $name = null,
    ) {}
}
