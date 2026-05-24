<?php

namespace App\News;

/**
 * Result of fetching a full article page: the extracted body text (for the AI
 * deep-brief) and the article's own publication date when the page exposes one.
 * Either field may be null — a paywalled page yields no text, a dateless page no
 * date — while the surrounding fetch still succeeded.
 */
final class FetchedArticle
{
    public function __construct(
        public readonly ?string $text,
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {}
}
