<?php

namespace App\Price;

interface PriceProvider
{
    /** Resolve a Yahoo-style ticker from an ISIN. Returns null if not found. */
    public function resolveTickerByIsin(string $isin): ?string;

    /** Latest price for a ticker. Returns null on miss (404, network, etc.). */
    public function fetchLatestPrice(string $ticker): ?PriceQuote;

    /** Latest FX rate from→to (e.g. USD→CHF). Returns null on miss. */
    public function fetchLatestFx(string $from, string $to): ?float;

    /**
     * Historical daily closes for a ticker, going back $range (e.g. "1y","5y","max").
     * Returns an empty array on error.
     *
     * @return list<PriceQuote>
     */
    public function fetchPriceHistory(string $ticker, string $range = 'max'): array;

    /**
     * Historical daily FX rates for a pair. Same convention.
     *
     * @return list<array{date: \DateTimeImmutable, rate: float}>
     */
    public function fetchFxHistory(string $from, string $to, string $range = 'max'): array;
}
