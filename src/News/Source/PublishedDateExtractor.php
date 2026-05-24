<?php

namespace App\News\Source;

/**
 * Recovers an article's *own* publication date from its page. Aggregators and
 * listing scrapers often only know when we fetched a story (Google News stamps
 * its index time; the DOM scraper falls back to "now"), so once we have the real
 * article HTML we can do better.
 *
 * Signals are tried most-trustworthy first and every candidate is validated, so
 * a busted CMS template (e.g. datePublished="2026-33-9TAD::Z") or a promotional
 * future date is discarded rather than trusted:
 *
 *   1. JSON-LD  datePublished / dateModified  (schema.org Article)
 *   2. <meta>   article:published_time, og, itemprop, dc.date, parsely, …
 *   3. <time datetime="…">
 *   4. visible-text dateline                   (the lossy last resort)
 */
final class PublishedDateExtractor
{
    /** Meta property/name/itemprop keys that carry a publish date, best first. */
    private const META_KEYS = [
        'article:published_time', 'og:published_time', 'datepublished',
        'article:modified_time', 'og:updated_time', 'datemodified',
        'date', 'pubdate', 'publishdate', 'publish-date', 'publication_date',
        'dc.date', 'dc.date.issued', 'dcterms.created', 'parsely-pub-date',
        'sailthru.date', 'article.published',
    ];

    public function extract(string $html): ?\DateTimeImmutable
    {
        if (trim($html) === '') {
            return null;
        }
        return $this->fromJsonLd($html)
            ?? $this->fromMeta($html)
            ?? $this->fromTimeTag($html)
            ?? $this->fromText($html);
    }

    private function fromJsonLd(string $html): ?\DateTimeImmutable
    {
        // Prefer any valid datePublished over dateModified, across every block.
        foreach (['datePublished', 'dateModified'] as $key) {
            if (preg_match_all('/"' . $key . '"\s*:\s*"([^"]*)"/i', $html, $m)) {
                foreach ($m[1] as $raw) {
                    $d = $this->normalize($raw);
                    if ($d !== null) {
                        return $d;
                    }
                }
            }
        }
        return null;
    }

    private function fromMeta(string $html): ?\DateTimeImmutable
    {
        if (!preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
            return null;
        }
        // First content seen per key wins; then pick keys in priority order.
        $found = [];
        foreach ($tags[0] as $tag) {
            $key = $this->attr($tag, 'property') ?? $this->attr($tag, 'name') ?? $this->attr($tag, 'itemprop');
            $content = $key !== null ? $this->attr($tag, 'content') : null;
            if ($content !== null) {
                $found[strtolower($key)] ??= $content;
            }
        }
        foreach (self::META_KEYS as $key) {
            if (isset($found[$key])) {
                $d = $this->normalize($found[$key]);
                if ($d !== null) {
                    return $d;
                }
            }
        }
        return null;
    }

    private function fromTimeTag(string $html): ?\DateTimeImmutable
    {
        if (preg_match_all('/<time\b[^>]*\bdatetime\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $raw) {
                $d = $this->normalize($raw);
                if ($d !== null) {
                    return $d;
                }
            }
        }
        return null;
    }

    /**
     * Last resort: the human dateline printed in the page. Datelines sit near the
     * top, so we only scan the opening of the body text and take the first date
     * that parses — which keeps us off "related stories" / footer dates.
     */
    private function fromText(string $html): ?\DateTimeImmutable
    {
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5);
        $text = mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 5000);

        $months = 'January|February|March|April|May|June|July|August|September|October|November|December'
            . '|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
        $re = '/\b(?:'
            . '(?:' . $months . ')\.?\s+\d{1,2},?\s+20\d{2}'  // April 9, 2026
            . '|\d{1,2}\s+(?:' . $months . ')\.?,?\s+20\d{2}' // 9 April 2026
            . '|20\d{2}-\d{2}-\d{2}'                           // 2026-04-09
            . ')\b/i';
        if (preg_match_all($re, $text, $m)) {
            foreach ($m[0] as $raw) {
                $d = $this->normalize($raw);
                if ($d !== null) {
                    return $d;
                }
            }
        }
        return null;
    }

    /** Read an attribute value (double- or single-quoted) from one tag string. */
    private function attr(string $tag, string $name): ?string
    {
        $n = preg_quote($name, '/');
        if (preg_match('/\b' . $n . '\s*=\s*"([^"]*)"/i', $tag, $m)
            || preg_match('/\b' . $n . "\\s*=\\s*'([^']*)'/i", $tag, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Parse a candidate and reject the implausible: anything that won't parse, is
     * pre-2000, or lies in the future (beyond a little clock skew) — those are
     * template junk or "coming soon" promos, not publication dates.
     */
    private function normalize(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
        if ((int) $d->format('Y') < 2000) {
            return null;
        }
        if ($d > (new \DateTimeImmutable())->modify('+2 days')) {
            return null;
        }
        return $d;
    }
}
