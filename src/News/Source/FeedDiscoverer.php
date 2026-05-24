<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;

/**
 * Finds the feed(s) a web page advertises via <link rel="alternate"
 * type="application/rss+xml|atom+xml">. This is how a normal human-readable
 * page exposes its machine feed, so a user can paste a site URL and we resolve
 * it to a clean feed instead of scraping. Hrefs are made absolute against the
 * page URL. Returns the candidates in document order (first is usually primary).
 */
final class FeedDiscoverer
{
    /**
     * @return array<int, array{url: string, title: ?string, type: string}>
     */
    public function discover(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The leading XML hint forces UTF-8 so entities decode correctly.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//link[@rel="alternate"][@href]');
        if ($links === false) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $type = strtolower(trim($link->getAttribute('type')));
            $kind = match (true) {
                str_contains($type, 'rss') => AssetNewsSource::TYPE_RSS,
                str_contains($type, 'atom') => AssetNewsSource::TYPE_ATOM,
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $href = SafeUrlFetcher::absolutize($baseUrl, $link->getAttribute('href'));
            if ($href === null || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $title = trim($link->getAttribute('title'));
            $out[] = ['url' => $href, 'title' => $title !== '' ? $title : null, 'type' => $kind];
        }

        return $out;
    }
}
