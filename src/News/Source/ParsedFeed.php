<?php

namespace App\News\Source;

use App\News\NewsArticle;

/**
 * A successfully parsed RSS/Atom feed: its channel title (used as a default
 * source label) plus the articles, newest first as the feed ordered them.
 */
final class ParsedFeed
{
    /** @param NewsArticle[] $items */
    public function __construct(
        public readonly ?string $title,
        public readonly array $items,
        /** 'rss' or 'atom' */
        public readonly string $type,
    ) {}
}
