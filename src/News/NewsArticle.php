<?php

namespace App\News;

use App\Entity\NewsItem;

/**
 * Source-agnostic news item as returned by a NewsProvider, before it's
 * persisted as a NewsItem. Carries only what every source can supply;
 * sentiment and summary are filled in later by the classifier.
 */
final class NewsArticle
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly ?string $publisher = null,
        public readonly string $kind = NewsItem::KIND_HEADLINE,
        /** Raw excerpt/description from the source, used to seed the AI summary. */
        public readonly ?string $snippet = null,
        /** Provider-supplied sentiment (bullish/bearish/neutral), if any. */
        public readonly ?string $sentiment = null,
    ) {}
}
