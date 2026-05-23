<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reddit chatter as `social` news via Reddit's public RSS/Atom feeds — no API
 * key, app, or OAuth. For each holding it reads the dedicated subreddit (when
 * one is set on the asset) and searches the broad market subreddits for the
 * ticker/company. Feeds are rate-limited by IP and carry less metadata than the
 * official API; failures degrade to an empty result. Sentiment is left to the AI.
 */
final class RedditProvider implements NewsProvider
{
    private const BASE = 'https://www.reddit.com';
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const UA = 'treasury-news/1.0 (personal net-worth tracker)';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'reddit';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        /** @var array<string, NewsArticle> $byUrl */
        $byUrl = [];

        // Dedicated company subreddit, if set on the asset — its hottest posts.
        $sub = $asset->getRedditSubreddit();
        if ($sub !== null && $sub !== '') {
            foreach ($this->feed('/r/' . rawurlencode($sub) . '/.rss', ['limit' => min($limit, 8)]) as $a) {
                $byUrl[$a->url] = $a;
            }
        }

        // Broad market subreddits, searched for this holding.
        $query = $this->query($asset);
        $broad = $this->settings->getRedditBroadSubreddits();
        if ($query !== null && $broad !== []) {
            $multi = implode('+', array_map('rawurlencode', $broad));
            $articles = $this->feed('/r/' . $multi . '/search.rss', [
                'q' => $query,
                'restrict_sr' => 'on',
                'sort' => 'new',
                'limit' => $limit,
            ]);
            foreach ($articles as $a) {
                $byUrl[$a->url] = $a;
            }
        }

        return array_values($byUrl);
    }

    /**
     * @param array<string, scalar> $query
     * @return NewsArticle[]
     */
    private function feed(string $path, array $query): array
    {
        try {
            $body = $this->http->request('GET', self::BASE . $path, [
                'query' => $query,
                'headers' => ['User-Agent' => self::UA, 'Accept' => 'application/atom+xml,application/xml'],
                'timeout' => 12,
            ])->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Reddit feed failed', ['path' => $path, 'error' => $e->getMessage()]);
            return [];
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }
        $entries = $xml->children(self::ATOM_NS)->entry;
        if ($entries === null) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            $atom = $entry->children(self::ATOM_NS);
            $title = trim((string) $atom->title);
            $url = $this->linkHref($entry);
            if ($title === '' || $url === '') {
                continue;
            }
            $when = (string) ($atom->published ?? '') ?: (string) ($atom->updated ?? '');

            $out[] = new NewsArticle(
                title: $title,
                url: $url,
                publishedAt: $this->parseDate($when),
                publisher: $this->subredditFromUrl($url),
                kind: NewsItem::KIND_SOCIAL,
                snippet: $this->snippet((string) $atom->content),
            );
        }
        return $out;
    }

    private function linkHref(\SimpleXMLElement $entry): string
    {
        foreach ($entry->children(self::ATOM_NS)->link as $link) {
            $href = (string) ($link->attributes()->href ?? '');
            if ($href !== '') {
                return $href;
            }
        }
        return '';
    }

    /** "r/stocks" from a permalink, for display as the publisher. */
    private function subredditFromUrl(string $url): string
    {
        return preg_match('#/r/([^/]+)/#', $url, $m) === 1 ? 'r/' . $m[1] : 'reddit';
    }

    /** Reddit Atom content is HTML with a "submitted by … to …" footer; trim it. */
    private function snippet(string $contentHtml): ?string
    {
        if (trim($contentHtml) === '') {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($contentHtml, ENT_QUOTES | ENT_HTML5))));
        $text = (string) preg_replace('/\s*submitted by.*$/i', '', $text);
        if (mb_strlen($text) < 20) {
            return null;
        }
        return mb_strlen($text) > 280 ? mb_substr($text, 0, 277) . '…' : $text;
    }

    private function query(Asset $asset): ?string
    {
        $terms = [];
        $name = $asset->getName();
        if ($name !== null && trim($name) !== '') {
            $terms[] = '"' . trim($name) . '"';
        }
        $ticker = $asset->getTicker();
        if ($ticker !== null && trim($ticker) !== '') {
            $terms[] = strtoupper(explode('.', trim($ticker))[0]);
        }
        return $terms !== [] ? implode(' OR ', array_unique($terms)) : null;
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
