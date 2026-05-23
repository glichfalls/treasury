<?php

namespace App\News;

use App\Entity\Asset;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A source of news for an asset. Implementations are auto-tagged and fanned
 * out by NewsFetcher, mirroring how PriceProvider abstracts price sources —
 * add a source by adding one class, no wiring changes.
 */
#[AutoconfigureTag('app.news_provider')]
interface NewsProvider
{
    /** Stable source key persisted on each item, e.g. 'yahoo'. */
    public function source(): string;

    /**
     * Latest articles relevant to the asset, newest first. Returns an empty
     * array on any miss (no ticker, network error, nothing found) — never throws
     * for an expected miss.
     *
     * @return NewsArticle[]
     */
    public function fetchForAsset(Asset $asset, int $limit = 10): array;
}
