<?php

namespace App\News\Source;

use App\Entity\NewsItem;
use App\News\NewsArticle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort extraction of recent articles from a feedless web page. "Unknown
 * HTML" is only tractable because most pages aren't actually structureless, so
 * this cascades from the most reliable structured signal to dumb heuristics:
 *
 *   1. JSON-LD  (schema.org NewsArticle / ItemList) — structured, no guessing
 *   2. news sitemap (/sitemap_news.xml &c.)         — structured, dated
 *   3. DOM repetition heuristic                      — the lossy fallback
 *
 * Each rung is independently testable. Rungs 1–2 carry most feedless sites
 * cleanly; rung 3 is the long tail and is expected to be imperfect.
 */
final class HtmlArticleScraper
{
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const NEWS_NS = 'http://www.google.com/schemas/sitemap-news/0.9';
    private const SITEMAP_CANDIDATES = ['/sitemap_news.xml', '/news-sitemap.xml', '/sitemap.xml'];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return NewsArticle[]
     */
    public function scrape(string $html, string $baseUrl, ?SafeUrlFetcher $fetcher = null, int $limit = 20): array
    {
        $items = $this->fromJsonLd($html, $baseUrl, $limit);
        if ($items !== []) {
            return $items;
        }
        if ($fetcher !== null) {
            $items = $this->fromNewsSitemap($baseUrl, $fetcher, $limit);
            if ($items !== []) {
                return $items;
            }
        }
        return $this->fromDomHeuristic($html, $baseUrl, $limit);
    }

    // ---- Rung 1: JSON-LD ---------------------------------------------------

    /** @return NewsArticle[] */
    public function fromJsonLd(string $html, string $baseUrl, int $limit = 20): array
    {
        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($scripts === false) {
            return [];
        }

        $out = [];
        foreach ($scripts as $script) {
            $data = json_decode(trim($script->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            $this->collectJsonLdNodes($data, $out, $baseUrl);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $this->dedupeByUrl($out, $limit);
    }

    /**
     * Walk an arbitrarily-nested JSON-LD structure (objects, @graph, ItemList)
     * collecting any article-shaped node.
     *
     * @param array<mixed> $data
     * @param NewsArticle[] $out
     */
    private function collectJsonLdNodes(array $data, array &$out, string $baseUrl): void
    {
        // A list of nodes.
        if (array_is_list($data)) {
            foreach ($data as $node) {
                if (is_array($node)) {
                    $this->collectJsonLdNodes($node, $out, $baseUrl);
                }
            }
            return;
        }

        $type = $data['@type'] ?? null;
        $types = is_array($type) ? array_map('strval', $type) : [is_string($type) ? $type : ''];
        $isArticle = (bool) array_intersect(
            array_map('strtolower', $types),
            ['newsarticle', 'article', 'blogposting', 'report', 'analysisnewsarticle'],
        );

        if ($isArticle) {
            $article = $this->jsonLdToArticle($data, $baseUrl);
            if ($article !== null) {
                $out[] = $article;
            }
        }

        // Recurse into containers that hold article nodes.
        foreach (['@graph', 'itemListElement', 'item', 'mainEntity', 'hasPart'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->collectJsonLdNodes($data[$key], $out, $baseUrl);
            }
        }
    }

    /** @param array<mixed> $node */
    private function jsonLdToArticle(array $node, string $baseUrl): ?NewsArticle
    {
        $title = $this->str($node['headline'] ?? $node['name'] ?? null);
        $url = $this->str($node['url'] ?? (is_array($node['mainEntityOfPage'] ?? null) ? ($node['mainEntityOfPage']['@id'] ?? null) : ($node['mainEntityOfPage'] ?? null)));
        if ($title === null || $url === null) {
            return null;
        }
        $abs = SafeUrlFetcher::absolutize($baseUrl, $url);
        if ($abs === null) {
            return null;
        }
        return new NewsArticle(
            title: $title,
            url: $abs,
            publishedAt: $this->date($this->str($node['datePublished'] ?? $node['dateModified'] ?? null)),
            publisher: $this->publisherName($node),
            kind: NewsItem::KIND_HEADLINE,
            snippet: $this->str($node['description'] ?? null),
        );
    }

    /** @param array<mixed> $node */
    private function publisherName(array $node): ?string
    {
        $pub = $node['publisher'] ?? $node['author'] ?? null;
        if (is_array($pub)) {
            return $this->str($pub['name'] ?? null);
        }
        return $this->str($pub);
    }

    // ---- Rung 2: news sitemap ----------------------------------------------

    /** @return NewsArticle[] */
    public function fromNewsSitemap(string $baseUrl, SafeUrlFetcher $fetcher, int $limit = 20): array
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return [];
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        foreach (self::SITEMAP_CANDIDATES as $path) {
            try {
                $res = $fetcher->fetch($origin . $path);
            } catch (\Throwable $e) {
                $this->logger->debug('Sitemap fetch failed', ['url' => $origin . $path, 'error' => $e->getMessage()]);
                continue;
            }
            if (!$res->ok()) {
                continue;
            }
            $items = $this->parseSitemap($res->body, $limit);
            if ($items !== []) {
                return $items;
            }
        }
        return [];
    }

    /** @return NewsArticle[] */
    private function parseSitemap(string $body, int $limit): array
    {
        $xml = @simplexml_load_string(trim($body));
        if ($xml === false || !isset($xml->url)) {
            return [];
        }
        $out = [];
        foreach ($xml->url as $url) {
            $loc = trim((string) ($url->children(self::SITEMAP_NS)->loc ?? $url->loc));
            if ($loc === '') {
                continue;
            }
            $news = $url->children(self::NEWS_NS)->news;
            // A plain (non-news) sitemap entry has no title/date — skip it; we
            // only want news sitemaps, not a wall of every page on the site.
            if ($news === null || !isset($news->title)) {
                continue;
            }
            $title = $this->clean((string) $news->title);
            if ($title === '') {
                continue;
            }
            $out[] = new NewsArticle(
                title: $title,
                url: $loc,
                publishedAt: $this->date((string) ($news->publication_date ?? '')),
                publisher: isset($news->publication->name) ? $this->clean((string) $news->publication->name) : null,
                kind: NewsItem::KIND_HEADLINE,
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    // ---- Rung 3: DOM repetition heuristic ----------------------------------

    /** @return NewsArticle[] */
    public function fromDomHeuristic(string $html, string $baseUrl, int $limit = 20): array
    {
        $dom = $this->loadDom($html);
        if ($dom === null) {
            return [];
        }
        $xpath = new \DOMXPath($dom);

        // Drop site chrome so we don't harvest nav/menu/footer links.
        foreach ($xpath->query('//nav | //header | //footer | //aside | //script | //style | //form') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $anchors = $xpath->query('//a[@href]');
        if ($anchors === false) {
            return [];
        }

        /** @var array<string, NewsArticle> $byUrl */
        $byUrl = [];
        foreach ($anchors as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $text = $this->clean($a->textContent);
            // Headlines sit in a sweet spot; nav labels are short, blurbs are long.
            if (mb_strlen($text) < 30 || mb_strlen($text) > 180) {
                continue;
            }
            $href = SafeUrlFetcher::absolutize($baseUrl, $a->getAttribute('href'));
            if ($href === null || isset($byUrl[$href])) {
                continue;
            }
            // Same-site only — outbound links on a listing are rarely its articles.
            if ($baseHost !== '' && strtolower((string) parse_url($href, PHP_URL_HOST)) !== $baseHost) {
                continue;
            }
            if ($this->score($a, $href) < 2) {
                continue;
            }
            $byUrl[$href] = new NewsArticle(
                title: $text,
                url: $href,
                publishedAt: $this->nearbyDate($a) ?? new \DateTimeImmutable(),
                publisher: null,
                kind: NewsItem::KIND_HEADLINE,
            );
            if (count($byUrl) >= $limit) {
                break;
            }
        }
        return array_values($byUrl);
    }

    /** Heuristic confidence that an anchor points to a real article. */
    private function score(\DOMElement $a, string $href): int
    {
        $score = 0;
        $path = (string) parse_url($href, PHP_URL_PATH);
        // A dated path or a hyphenated slug with depth looks like an article.
        if (preg_match('#/(19|20)\d{2}/#', $path) === 1) {
            $score += 2;
        }
        if (preg_match('#/[a-z0-9]+(?:-[a-z0-9]+){2,}#i', $path) === 1) {
            $score += 1;
        }
        if (substr_count(trim($path, '/'), '/') >= 1) {
            $score += 1;
        }
        // A date near the link is a strong signal it's a story, not a menu item.
        if ($this->nearbyDate($a) !== null) {
            $score += 2;
        }
        return $score;
    }

    /** Look for a <time datetime> within the anchor's card (up to 4 ancestors). */
    private function nearbyDate(\DOMElement $a): ?\DateTimeImmutable
    {
        $node = $a;
        for ($depth = 0; $depth < 4 && $node !== null; $depth++) {
            foreach ($node->getElementsByTagName('time') as $time) {
                $dt = $time->getAttribute('datetime');
                if ($dt === '') {
                    $dt = $this->clean($time->textContent);
                }
                if ($dt !== '') {
                    try {
                        return new \DateTimeImmutable($dt);
                    } catch (\Throwable) {
                        // not a parseable date; keep looking
                    }
                }
            }
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }
        return null;
    }

    // ---- shared helpers ----------------------------------------------------

    private function loadDom(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    /** @param NewsArticle[] $items @return NewsArticle[] */
    private function dedupeByUrl(array $items, int $limit): array
    {
        $byUrl = [];
        foreach ($items as $item) {
            $byUrl[$item->url] ??= $item;
            if (count($byUrl) >= $limit) {
                break;
            }
        }
        return array_values($byUrl);
    }

    private function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = $this->clean($v);
        return $v !== '' ? $v : null;
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5)));
    }

    private function date(?string $raw): \DateTimeImmutable
    {
        if ($raw !== null && trim($raw) !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new \DateTimeImmutable();
    }
}
