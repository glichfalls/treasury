<?php

namespace App\News\Source;

use App\News\NewsArticle;

/**
 * The result of probing a pasted URL: how it'll be ingested (type + mode, the
 * resolved feed if any), a suggested label, which parser will handle it, and a
 * few sample items — so an admin can confirm before saving.
 */
final class SourcePreview
{
    /** @param NewsArticle[] $items */
    public function __construct(
        public readonly string $type,
        public readonly string $scrapeMode,
        public readonly ?string $feedUrl,
        public readonly ?string $label,
        public readonly string $parser,
        public readonly array $items,
    ) {}
}
