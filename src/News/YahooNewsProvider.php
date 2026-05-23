<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pulls headlines from Yahoo Finance's public search endpoint — the same host
 * the price provider uses, no API key. The `newsCount` param (which the price
 * lookup deliberately sets to 0) returns recent articles for a query term.
 */
final class YahooNewsProvider implements NewsProvider
{
    private const SEARCH_URL = 'https://query1.finance.yahoo.com/v1/finance/search';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'yahoo';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        // ETFs/funds get sparse name-specific coverage, so search the market the
        // fund tracks when we have one; otherwise the ticker is the best handle.
        $query = $asset->getNewsMarketTopic() ?? $asset->getTicker() ?? $asset->getName() ?? $asset->getIsin();
        if ($query === null || trim($query) === '') {
            return [];
        }

        try {
            $res = $this->http->request('GET', self::SEARCH_URL, [
                'query' => ['q' => $query, 'quotesCount' => 0, 'newsCount' => max(1, $limit)],
                'headers' => $this->headers(),
                'timeout' => 10,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo news fetch failed', ['query' => $query, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($data['news'] ?? [] as $item) {
            $title = $item['title'] ?? null;
            $link = $item['link'] ?? null;
            if (!is_string($title) || trim($title) === '' || !is_string($link) || trim($link) === '') {
                continue;
            }
            $ts = (int) ($item['providerPublishTime'] ?? time());
            $out[] = new NewsArticle(
                title: trim($title),
                url: trim($link),
                publishedAt: (new \DateTimeImmutable())->setTimestamp($ts),
                publisher: isset($item['publisher']) && is_string($item['publisher']) ? $item['publisher'] : null,
                kind: NewsItem::KIND_HEADLINE,
            );
        }
        return $out;
    }

    private function headers(): array
    {
        // Yahoo blocks the default cURL UA; a browser UA gets past the gate.
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept' => 'application/json',
        ];
    }
}
