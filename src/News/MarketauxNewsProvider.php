<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Marketaux multi-publisher aggregator (free tier). Surfaces Bloomberg/Reuters-
 * sourced headlines and ships a per-entity sentiment score we map straight to
 * bullish/bearish/neutral. No-ops without a configured API token.
 */
final class MarketauxNewsProvider implements NewsProvider
{
    private const NEWS_URL = 'https://api.marketaux.com/v1/news/all';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'marketaux';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $token = $this->settings->get(SettingsService::MARKETAUX_API_TOKEN);
        if ($token === null) {
            return [];
        }
        $symbol = $this->symbol($asset);
        if ($symbol === null) {
            return [];
        }

        try {
            $res = $this->http->request('GET', self::NEWS_URL, [
                'query' => [
                    'symbols' => $symbol,
                    'filter_entities' => 'true',
                    'language' => 'en',
                    // Free tier caps articles per request; don't ask for more.
                    'limit' => min($limit, 3),
                    'api_token' => $token,
                ],
                'timeout' => 10,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Marketaux news fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($data['data'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = $item['title'] ?? null;
            $url = $item['url'] ?? null;
            if (!is_string($title) || trim($title) === '' || !is_string($url) || trim($url) === '') {
                continue;
            }
            $description = $item['description'] ?? ($item['snippet'] ?? null);
            $out[] = new NewsArticle(
                title: trim($title),
                url: trim($url),
                publishedAt: $this->parseDate($item['published_at'] ?? null),
                publisher: isset($item['source']) && is_string($item['source']) ? $item['source'] : null,
                kind: NewsItem::KIND_HEADLINE,
                snippet: is_string($description) && trim($description) !== '' ? trim($description) : null,
                sentiment: $this->mapSentiment($item['entities'] ?? [], $symbol),
            );
        }
        return $out;
    }

    /**
     * Average the sentiment_score of entities matching our symbol and bucket it.
     * Marketaux scores run roughly -1 (very negative) … 1 (very positive).
     *
     * @param mixed $entities
     */
    private function mapSentiment(mixed $entities, string $symbol): ?string
    {
        if (!is_array($entities)) {
            return null;
        }
        $scores = [];
        foreach ($entities as $entity) {
            if (is_array($entity)
                && isset($entity['symbol'], $entity['sentiment_score'])
                && is_string($entity['symbol'])
                && strtoupper($entity['symbol']) === $symbol
                && is_numeric($entity['sentiment_score'])
            ) {
                $scores[] = (float) $entity['sentiment_score'];
            }
        }
        if ($scores === []) {
            return null;
        }
        $avg = array_sum($scores) / count($scores);
        return match (true) {
            $avg > 0.15 => NewsItem::SENTIMENT_BULLISH,
            $avg < -0.15 => NewsItem::SENTIMENT_BEARISH,
            default => NewsItem::SENTIMENT_NEUTRAL,
        };
    }

    private function parseDate(mixed $raw): \DateTimeImmutable
    {
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new \DateTimeImmutable();
    }

    /** Marketaux matches bare symbols; strip an exchange suffix like ".SW"/".L". */
    private function symbol(Asset $asset): ?string
    {
        $ticker = $asset->getTicker();
        if ($ticker === null || trim($ticker) === '') {
            return null;
        }
        $base = strtoupper(explode('.', trim($ticker))[0]);
        return $base !== '' ? $base : null;
    }
}
