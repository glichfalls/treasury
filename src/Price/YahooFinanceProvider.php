<?php

namespace App\Price;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to Yahoo Finance's public (unofficial) JSON endpoints.
 *
 * Endpoints used:
 *   - https://query1.finance.yahoo.com/v1/finance/search?q=<ISIN>  → ticker lookup
 *   - https://query1.finance.yahoo.com/v8/finance/chart/<TICKER>   → latest price
 *   - https://query1.finance.yahoo.com/v8/finance/chart/<FROM><TO>=X → FX rate
 */
final class YahooFinanceProvider implements PriceProvider
{
    private const SEARCH_URL = 'https://query1.finance.yahoo.com/v1/finance/search';
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function resolveTickerByIsin(string $isin): ?string
    {
        try {
            $res = $this->http->request('GET', self::SEARCH_URL, [
                'query' => ['q' => $isin, 'quotesCount' => 5, 'newsCount' => 0],
                'headers' => $this->headers(),
                'timeout' => 8,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo ISIN lookup failed', ['isin' => $isin, 'error' => $e->getMessage()]);
            return null;
        }

        foreach ($data['quotes'] ?? [] as $quote) {
            $symbol = $quote['symbol'] ?? null;
            if (is_string($symbol) && $symbol !== '') {
                return $symbol;
            }
        }
        return null;
    }

    public function fetchLatestPrice(string $ticker): ?PriceQuote
    {
        $data = $this->fetchChart($ticker);
        if ($data === null) {
            return null;
        }

        $meta = $data['meta'] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        $price = $meta['regularMarketPrice'] ?? null;
        if (!is_numeric($price)) {
            return null;
        }

        $ts = $meta['regularMarketTime'] ?? time();
        [$normPrice, $normCcy] = $this->normalizeUnits((float) $price, (string) ($meta['currency'] ?? 'USD'));

        return new PriceQuote(
            price: $normPrice,
            currency: $normCcy,
            asOf: (new \DateTimeImmutable())->setTimestamp((int) $ts)->setTime(0, 0),
            resolvedTicker: (string) ($meta['symbol'] ?? $ticker),
            name: $this->pickName($meta),
        );
    }

    /** Yahoo gives us shortName / longName depending on the asset; prefer the shorter one. */
    private function pickName(array $meta): ?string
    {
        foreach (['shortName', 'longName'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }

    public function fetchLatestFx(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }
        $data = $this->fetchChart($from . $to . '=X');
        if ($data === null) {
            return null;
        }
        $price = $data['meta']['regularMarketPrice'] ?? null;
        return is_numeric($price) ? (float) $price : null;
    }

    public function fetchPriceHistory(string $ticker, string $range = 'max'): array
    {
        $data = $this->fetchChart($ticker, $range);
        if ($data === null) {
            return [];
        }
        $rawCurrency = (string) ($data['meta']['currency'] ?? 'USD');
        $resolved = (string) ($data['meta']['symbol'] ?? $ticker);
        $name = $this->pickName(is_array($data['meta'] ?? null) ? $data['meta'] : []);
        return $this->parseHistory($data, function (float $price, \DateTimeImmutable $date) use ($rawCurrency, $resolved, $name) {
            [$normPrice, $normCcy] = $this->normalizeUnits($price, $rawCurrency);
            return new PriceQuote(
                price: $normPrice,
                currency: $normCcy,
                asOf: $date,
                resolvedTicker: $resolved,
                name: $name,
            );
        });
    }

    /**
     * Normalize Yahoo unit quirks: London listings (.L tickers) often return prices in
     * pence (GBp / GBX) rather than pounds, with the currency string in any case.
     * South African Rand cents (ZAc) work the same way. Convert pence-style values to
     * the major unit so the rest of the system can treat them like any other currency.
     *
     * @return array{0: float, 1: string}
     */
    private function normalizeUnits(float $price, string $currencyRaw): array
    {
        return match ($currencyRaw) {
            'GBp', 'GBX', 'gbx' => [$price / 100, 'GBP'],
            'ZAc' => [$price / 100, 'ZAR'],
            default => [$price, strtoupper($currencyRaw)],
        };
    }

    public function fetchFxHistory(string $from, string $to, string $range = 'max'): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return [];
        }
        $data = $this->fetchChart($from . $to . '=X', $range);
        if ($data === null) {
            return [];
        }
        return $this->parseHistory($data, fn(float $rate, \DateTimeImmutable $date) => [
            'date' => $date,
            'rate' => $rate,
        ]);
    }

    /**
     * @template T
     * @param callable(float, \DateTimeImmutable): T $build
     * @return list<T>
     */
    private function parseHistory(array $data, callable $build): array
    {
        $timestamps = $data['timestamp'] ?? null;
        $closes = $data['indicators']['quote'][0]['close'] ?? null;
        if (!is_array($timestamps) || !is_array($closes)) {
            return [];
        }

        $out = [];
        $count = min(count($timestamps), count($closes));
        for ($i = 0; $i < $count; $i++) {
            $price = $closes[$i];
            $ts = $timestamps[$i];
            if (!is_numeric($price) || !is_numeric($ts)) {
                continue; // Yahoo emits null for non-trading or missing days
            }
            $date = (new \DateTimeImmutable())->setTimestamp((int) $ts)->setTime(0, 0);
            $out[] = $build((float) $price, $date);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function fetchChart(string $symbol, string $range = '5d'): ?array
    {
        try {
            $res = $this->http->request('GET', self::CHART_URL . rawurlencode($symbol), [
                'query' => ['interval' => '1d', 'range' => $range],
                'headers' => $this->headers(),
                'timeout' => 15,
            ]);
            $data = $res->toArray(false);
        } catch (HttpExceptionInterface | \Throwable $e) {
            $this->logger->warning('Yahoo chart fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return null;
        }

        $result = $data['chart']['result'][0] ?? null;
        return is_array($result) ? $result : null;
    }

    private function headers(): array
    {
        // Yahoo blocks default cURL UA. A plain browser UA gets past the gate.
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept' => 'application/json',
        ];
    }
}
