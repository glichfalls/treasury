<?php

namespace App\Holdings;

final class Holding
{
    public function __construct(
        public readonly string $isin,
        public readonly ?string $ticker,
        public readonly ?string $name,
        /** Signed decimal string. */
        public readonly string $quantity,
        public readonly ?string $priceCurrency,
        public readonly ?string $priceMinor,
        public readonly ?string $priceAsOf,
        public readonly ?string $valueBaseMinor,
        public readonly string $baseCurrency,
        /** Price the day before $priceAsOf, in $priceCurrency. Null if no prior price exists. */
        public readonly ?string $previousPriceMinor = null,
        /** (latest − previous) / previous × 100. Null if either side is missing. */
        public readonly ?float $dayChangePct = null,
        /** Pre-market price in minor units, or null when not in a pre-market session. */
        public readonly ?string $preMarketPriceMinor = null,
        /** Pre-market % change vs. previous close. Null when not in pre-market. */
        public readonly ?float $preMarketChangePct = null,
    ) {}

    public function toArray(): array
    {
        return [
            'isin' => $this->isin,
            'ticker' => $this->ticker,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'priceCurrency' => $this->priceCurrency,
            'priceMinor' => $this->priceMinor,
            'priceAsOf' => $this->priceAsOf,
            'valueBaseMinor' => $this->valueBaseMinor,
            'baseCurrency' => $this->baseCurrency,
            'previousPriceMinor' => $this->previousPriceMinor,
            'dayChangePct' => $this->dayChangePct,
            'preMarketPriceMinor' => $this->preMarketPriceMinor,
            'preMarketChangePct' => $this->preMarketChangePct,
        ];
    }
}
