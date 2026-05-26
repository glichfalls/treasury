<?php

namespace App\Price;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches pre-market prices from Yahoo Finance.
 *
 * Pre-market data is only surfaced when Yahoo reports marketState === "PRE".
 * Results are stored in Redis with a 5-minute TTL so every page load does
 * not fan out to Yahoo — the cache is shared across all containers.
 *
 * HTTP requests for cache misses are started in parallel (Symfony HTTP Client
 * is non-blocking on request(); toArray() resolves concurrently).
 */
final class PreMarketService
{
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';
    private const TTL = 300; // 5 minutes

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns a map of ticker → PreMarketQuote for tickers that are currently
     * in pre-market. Tickers with no pre-market activity (or outside pre-market
     * hours) are absent from the result.
     *
     * @param list<string> $tickers
     * @return array<string, PreMarketQuote>
     */
    public function getQuotes(array $tickers): array
    {
        if ($tickers === []) {
            return [];
        }

        $keyMap = []; // ticker → cache key
        foreach ($tickers as $ticker) {
            $keyMap[$ticker] = $this->cacheKey($ticker);
        }

        $items = $this->cache->getItems(array_values($keyMap));
        $keyToTicker = array_flip($keyMap);

        $result = [];
        $toFetch = []; // ticker → CacheItem (for saving after fetch)

        foreach ($items as $key => $item) {
            $ticker = $keyToTicker[$key];
            if ($item->isHit()) {
                $value = $item->get();
                if ($value instanceof PreMarketQuote) {
                    $result[$ticker] = $value;
                }
                // null cached hit = "no pre-market session right now", skip silently
            } else {
                $toFetch[$ticker] = $item;
            }
        }

        if ($toFetch !== []) {
            $fetched = $this->fetchParallel(array_keys($toFetch));
            foreach ($fetched as $ticker => $quote) {
                $item = $toFetch[$ticker];
                $item->set($quote)->expiresAfter(self::TTL);
                $this->cache->save($item);
                if ($quote !== null) {
                    $result[$ticker] = $quote;
                }
            }
        }

        return $result;
    }

    /**
     * Starts all HTTP requests immediately (non-blocking), then collects
     * responses. Symfony HTTP Client sends requests concurrently.
     *
     * @param list<string> $tickers
     * @return array<string, PreMarketQuote|null>
     */
    private function fetchParallel(array $tickers): array
    {
        $responses = [];
        foreach ($tickers as $ticker) {
            $responses[$ticker] = $this->http->request(
                'GET',
                self::CHART_URL . rawurlencode($ticker),
                [
                    'query' => ['interval' => '1d', 'range' => '5d'],
                    'headers' => $this->headers(),
                    'timeout' => 10,
                ],
            );
        }

        $result = [];
        foreach ($responses as $ticker => $response) {
            try {
                $data = $response->toArray(false);
                $result[$ticker] = $this->parsePreMarket($data);
            } catch (\Throwable $e) {
                $this->logger->warning('Pre-market fetch failed', [
                    'ticker' => $ticker,
                    'error' => $e->getMessage(),
                ]);
                $result[$ticker] = null;
            }
        }
        return $result;
    }

    private function parsePreMarket(array $data): ?PreMarketQuote
    {
        $chartResult = $data['chart']['result'][0] ?? null;
        if (!is_array($chartResult)) {
            return null;
        }

        $meta = $chartResult['meta'] ?? [];

        // Only expose pre-market data during the actual pre-market session.
        $marketState = $meta['marketState'] ?? '';
        if (!in_array($marketState, ['PRE', 'PREPRE'], true)) {
            return null;
        }

        $preMarketPrice = $meta['preMarketPrice'] ?? null;
        $preMarketChangePct = $meta['preMarketChangePercent'] ?? null;
        $preMarketTime = $meta['preMarketTime'] ?? null;
        $currency = (string) ($meta['currency'] ?? 'USD');

        if (!is_numeric($preMarketPrice) || !is_numeric($preMarketChangePct)) {
            return null;
        }

        [$normPrice, $normCcy] = $this->normalizeUnits((float) $preMarketPrice, $currency);

        return new PreMarketQuote(
            priceMinor: (int) round($normPrice * 100),
            currency: $normCcy,
            changePct: (float) $preMarketChangePct,
            asOf: $preMarketTime
                ? (new \DateTimeImmutable())->setTimestamp((int) $preMarketTime)
                : new \DateTimeImmutable(),
        );
    }

    /**
     * Normalize pence-quoted tickers (London .L, ZAc) to major currency units,
     * matching the same logic in YahooFinanceProvider.
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

    private function cacheKey(string $ticker): string
    {
        // PSR-6 reserved characters: {}()/\@:
        return 'premarket.' . preg_replace('/[{}()\/\\\@:]/', '_', $ticker);
    }

    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept' => 'application/json',
        ];
    }
}
