<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;

/**
 * Probes a pasted URL to decide how Treasury will read it, used both for the
 * admin preview and to seed a new source's stored fields. The detection ladder:
 *
 *   0. a bespoke SourceParser claims it          → use that parser
 *   1. the URL itself parses as RSS/Atom          → feed, as-is
 *   2. the page advertises a feed (autodiscovery)  → feed, resolved
 *   3. neither                                     → website, scrape
 *
 * Throws UnsafeUrlException for a blocked URL and RuntimeException for a
 * fetch/HTTP failure, so the caller can report exactly why a URL was rejected.
 */
final class SourceProber
{
    private const SCRAPE_PREVIEW_LIMIT = 8;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly FeedReader $feedReader,
        private readonly FeedDiscoverer $discoverer,
        private readonly HtmlArticleScraper $scraper,
        private readonly SourceParserRegistry $registry,
    ) {}

    public function probe(string $url): SourcePreview
    {
        $url = trim($url);

        // (0) Let a bespoke parser claim the URL first.
        $transient = (new AssetNewsSource())->setUrl($url);
        $parser = $this->registry->resolve($transient);
        if (!$parser instanceof DefaultSourceParser) {
            $items = $parser->parse($transient, $this->fetcher);
            return new SourcePreview(
                type: AssetNewsSource::TYPE_WEBSITE,
                scrapeMode: AssetNewsSource::MODE_FEED,
                feedUrl: null,
                label: $parser->name(),
                parser: $parser->name(),
                items: array_slice($items, 0, self::SCRAPE_PREVIEW_LIMIT),
            );
        }

        $res = $this->fetcher->fetch($url);
        if (!$res->ok()) {
            throw new \RuntimeException('The URL returned HTTP ' . $res->status . '.');
        }

        // (1) The URL is itself a feed.
        $feed = $this->feedReader->read($res->body);
        if ($feed !== null) {
            return new SourcePreview(
                type: $feed->type,
                scrapeMode: AssetNewsSource::MODE_FEED,
                feedUrl: null,
                label: $feed->title,
                parser: 'Default',
                items: array_slice($feed->items, 0, self::SCRAPE_PREVIEW_LIMIT),
            );
        }

        // (2) The page advertises a feed — resolve and read it.
        foreach ($this->discoverer->discover($res->body, $res->finalUrl) as $candidate) {
            try {
                $feedRes = $this->fetcher->fetch($candidate['url']);
            } catch (\Throwable) {
                continue;
            }
            if (!$feedRes->ok()) {
                continue;
            }
            $discovered = $this->feedReader->read($feedRes->body);
            if ($discovered !== null) {
                return new SourcePreview(
                    type: $discovered->type,
                    scrapeMode: AssetNewsSource::MODE_FEED,
                    feedUrl: $candidate['url'],
                    label: $candidate['title'] ?? $discovered->title,
                    parser: 'Default',
                    items: array_slice($discovered->items, 0, self::SCRAPE_PREVIEW_LIMIT),
                );
            }
        }

        // (3) Feedless — heuristic scrape.
        $items = $this->scraper->scrape($res->body, $res->finalUrl, $this->fetcher, self::SCRAPE_PREVIEW_LIMIT);
        return new SourcePreview(
            type: AssetNewsSource::TYPE_WEBSITE,
            scrapeMode: AssetNewsSource::MODE_SCRAPE,
            feedUrl: null,
            label: $this->pageTitle($res->body),
            parser: 'Default',
            items: $items,
        );
    }

    private function pageTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            $title = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
            return $title !== '' ? mb_substr($title, 0, 200) : null;
        }
        return null;
    }
}
