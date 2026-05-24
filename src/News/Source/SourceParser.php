<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;
use App\News\NewsArticle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A bespoke parser for a specific news source. The generic cascade (feeds,
 * autodiscovery, JSON-LD, scraping) can't handle every site well, so this is
 * the escape hatch: drop in one tagged class and it auto-registers, mirroring
 * how ImportService dispatches to format-specific importers. The registry picks
 * the first parser whose supports() matches; if none do, the default is used.
 *
 * Implementations get the SafeUrlFetcher so a custom parser can hit a hidden
 * JSON endpoint or paginate, while SSRF protection stays centralized.
 */
#[AutoconfigureTag('app.news_source_parser')]
interface SourceParser
{
    /** Does this parser claim the given source? (typically by host/URL pattern) */
    public function supports(AssetNewsSource $source): bool;

    /**
     * Latest articles for the source, newest first. Returns an empty array on a
     * miss; throws UnsafeUrlException only for a blocked URL.
     *
     * @return NewsArticle[]
     */
    public function parse(AssetNewsSource $source, SafeUrlFetcher $fetcher): array;

    /** Short human name for the admin UI, e.g. "Apple Newsroom". */
    public function name(): string;
}
