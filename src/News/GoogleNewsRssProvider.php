<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google News RSS — keyless, and aggregates hundreds of publishers per query,
 * so it gives far broader and more company-specific coverage than Yahoo's
 * search. Queries are biased toward financial coverage (company name + stock /
 * earnings) to keep the feed on-topic.
 */
final class GoogleNewsRssProvider implements NewsProvider
{
    private const SEARCH_URL = 'https://news.google.com/rss/search';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'google';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $query = $this->query($asset);
        if ($query === null) {
            return [];
        }

        try {
            $content = $this->http->request('GET', self::SEARCH_URL, [
                'query' => ['q' => $query, 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                ],
                'timeout' => 12,
            ])->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Google News fetch failed', ['query' => $query, 'error' => $e->getMessage()]);
            return [];
        }

        $xml = @simplexml_load_string($content);
        if ($xml === false || !isset($xml->channel->item)) {
            return [];
        }

        $out = [];
        foreach ($xml->channel->item as $item) {
            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            if ($title === '' || $link === '') {
                continue;
            }
            // Drop 13F-filing spam (MarketBeat et al. republish institutional
            // holdings as "news") — pure noise, not company news.
            if ($this->looksLikeFilingSpam($title)) {
                continue;
            }
            $publisher = isset($item->source) ? trim((string) $item->source) : null;
            // The MarketBeat network of sites republishes 13F filings as "news";
            // dropping them at the publisher level is far more reliable than
            // chasing their endlessly-varied headline phrasings.
            if ($publisher !== null && $this->isBlockedPublisher($publisher)) {
                continue;
            }
            // Google appends " - Publisher" to titles; drop it when redundant.
            if ($publisher !== null && $publisher !== '' && str_ends_with($title, ' - ' . $publisher)) {
                $title = trim(substr($title, 0, -\strlen(' - ' . $publisher)));
            }
            $snippet = trim(strip_tags(html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5)));

            $out[] = new NewsArticle(
                title: $title,
                url: $link,
                publishedAt: $this->parseDate((string) $item->pubDate),
                publisher: $publisher !== '' ? $publisher : null,
                kind: NewsItem::KIND_HEADLINE,
                snippet: $snippet !== '' ? $snippet : null,
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Bias the query toward company-specific financial news. */
    private function query(Asset $asset): ?string
    {
        $topic = $asset->getNewsMarketTopic();
        if ($topic !== null && trim($topic) !== '') {
            return trim($topic);
        }
        $name = $asset->getName();
        if ($name !== null && trim($name) !== '') {
            return '"' . trim($name) . '" (stock OR shares OR earnings)';
        }
        $ticker = $asset->getTicker();
        return $ticker !== null && trim($ticker) !== '' ? trim($ticker) . ' stock' : null;
    }

    /** Known 13F-filing content mills (the MarketBeat network and friends). */
    private const BLOCKED_PUBLISHERS = [
        'marketbeat', 'defense world', 'etf daily news', 'american banking news',
        'modern readers', 'cerbat gem', 'zolmax', 'ticker report', 'tickerreport',
        'the markets daily', 'dakota financial news', 'transcript daily', 'mayfield recorder',
    ];

    private function isBlockedPublisher(string $publisher): bool
    {
        $p = strtolower($publisher);
        foreach (self::BLOCKED_PUBLISHERS as $blocked) {
            if (str_contains($p, $blocked)) {
                return true;
            }
        }
        return false;
    }

    /** Match the boilerplate headlines that 13F-filing aggregators churn out. */
    private function looksLikeFilingSpam(string $title): bool
    {
        $patterns = [
            '/\b(purchases|sells|buys|acquires|boosts|trims|lowers|raises|cuts|reduces|increases|grows|takes)\b.{0,30}\b(shares|stock position|holdings|stake|position)\b/i',
            '/\bshares?\b.{0,30}\b(purchased|sold|acquired|bought)\s+by\b/i',
            '/\bhas\s+\$[\d.]+\s+(million|billion)\b.{0,20}\b(stock\s+)?(position|holdings|stake)\b/i',
            '/\b(stock\s+)?(position|holdings|stake)\b.{0,30}\b(boosted|lowered|trimmed|raised|reduced|increased)\s+by\b/i',
            '/\b13F\b/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $title) === 1) {
                return true;
            }
        }
        return false;
    }

    private function parseDate(string $raw): \DateTimeImmutable
    {
        if (trim($raw) !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new \DateTimeImmutable();
    }
}
