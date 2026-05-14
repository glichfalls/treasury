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
        ];
    }
}
