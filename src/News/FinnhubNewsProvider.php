<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finnhub company-news (free tier). Best signal-to-noise of the keyed sources,
 * and supplies a per-article summary we keep as the snippet. No-ops when no key
 * is configured, so it simply doesn't contribute until set up in admin settings.
 */
final class FinnhubNewsProvider implements NewsProvider
{
    private const NEWS_URL = 'https://finnhub.io/api/v1/company-news';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'finnhub';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $token = $this->settings->get(SettingsService::FINNHUB_API_KEY);
        if ($token === null) {
            return [];
        }
        $symbol = $this->symbol($asset);
        if ($symbol === null) {
            return [];
        }

        $to = new \DateTimeImmutable('today');
        $from = $to->modify('-7 days');
        try {
            $res = $this->http->request('GET', self::NEWS_URL, [
                'query' => [
                    'symbol' => $symbol,
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                    'token' => $token,
                ],
                'timeout' => 10,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Finnhub news fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $headline = $item['headline'] ?? null;
            $url = $item['url'] ?? null;
            if (!is_string($headline) || trim($headline) === '' || !is_string($url) || trim($url) === '') {
                continue;
            }
            $summary = $item['summary'] ?? null;
            $out[] = new NewsArticle(
                title: trim($headline),
                url: trim($url),
                publishedAt: (new \DateTimeImmutable())->setTimestamp((int) ($item['datetime'] ?? time())),
                publisher: isset($item['source']) && is_string($item['source']) ? $item['source'] : null,
                kind: NewsItem::KIND_HEADLINE,
                snippet: is_string($summary) && trim($summary) !== '' ? trim($summary) : null,
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Finnhub uses bare symbols; strip an exchange suffix like ".SW"/".L". */
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
