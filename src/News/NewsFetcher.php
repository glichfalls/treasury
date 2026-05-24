<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Repository\AssetRepository;
use App\Repository\NewsItemRepository;
use App\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Orchestrates news aggregation: for each news-eligible asset, fans out across
 * every registered NewsProvider, de-duplicates per asset by content hash, and
 * persists new items. Idempotent — re-running only writes articles not already
 * stored, the same contract as the price refresh and CSV importers.
 */
final class NewsFetcher
{
    /**
     * @param iterable<NewsProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('app.news_provider')]
        private readonly iterable $providers,
        private readonly AssetRepository $assets,
        private readonly NewsItemRepository $newsItems,
        private readonly SettingsService $settings,
        private readonly NewsQualityFilter $quality,
        private readonly EtfTopicInferer $topicInferer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param Asset[]|null $assets  Defaults to all news-eligible held assets.
     * @param int|null $perAssetLimit  Defaults to the configured volume level.
     * @return array{inserted: int, skipped: int, errors: string[]}
     */
    public function refresh(?array $assets = null, ?int $perAssetLimit = null): array
    {
        $assets ??= $this->assets->findActiveForNews();
        // Give fund/ETF holdings a market topic to search against (prod/AI; no-op
        // without a key) before we build queries from it.
        $this->topicInferer->enrich($assets);
        $perAssetLimit ??= $this->settings->getNewsVolumeLimit();
        // Honour admin source toggles — skip providers switched off in config.
        $disabled = $this->settings->getDisabledSources();
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($assets as $asset) {
            // Collect across providers first, deduping within the run (an article
            // surfaced by two sources should be stored once). First source wins.
            /** @var array<string, array{0: string, 1: NewsArticle}> $collected */
            $collected = [];
            foreach ($this->providers as $provider) {
                if (in_array($provider->source(), $disabled, true)) {
                    continue;
                }
                try {
                    $articles = $provider->fetchForAsset($asset, $perAssetLimit);
                } catch (\Throwable $e) {
                    $this->logger->error('News provider failed', [
                        'source' => $provider->source(),
                        'isin' => $asset->getIsin(),
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = $provider->source() . '/' . $asset->getIsin() . ': ' . $e->getMessage();
                    continue;
                }
                foreach ($articles as $article) {
                    if (!$this->quality->accepts($article)) {
                        continue; // drop clickbait / filing spam / forum noise
                    }
                    // Headlines must actually reference the holding. Social is gated
                    // at the provider (broad-sub yes, dedicated-sub exempt).
                    if ($article->kind === NewsItem::KIND_HEADLINE
                        && !$this->quality->matchesAsset($article, $asset)) {
                        continue; // drop off-topic matches (e.g. unrelated ETF hits)
                    }
                    $hash = $this->hash($article);
                    $seen = $collected[$hash][1] ?? null;
                    // First source wins, except a later source carrying a price
                    // target supersedes an earlier one without it (FMP enriches
                    // the same analyst rating Yahoo reported with no target).
                    if ($seen === null || ($seen->priceTarget === null && $article->priceTarget !== null)) {
                        $collected[$hash] = [$provider->source(), $article];
                    }
                }
            }

            if ($collected === []) {
                continue;
            }

            $existing = $this->newsItems->existingHashesForAsset($asset->getId(), array_keys($collected));
            foreach ($collected as $hash => [$source, $article]) {
                if (isset($existing[$hash])) {
                    $skipped++;
                    continue;
                }
                $item = (new NewsItem())
                    ->setAsset($asset)
                    ->setSource($source)
                    ->setKind($article->kind)
                    ->setTitle($article->title)
                    ->setUrl($article->url)
                    ->setPublisher($article->publisher)
                    ->setSnippet($article->snippet)
                    ->setSentiment($article->sentiment)
                    ->setPublishedAt($article->publishedAt)
                    ->setContentHash($hash);
                $this->em->persist($item);
                $inserted++;
            }
        }

        $this->em->flush();
        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Per-asset dedup key: the article's explicit dedupKey when it carries one
     * (so the same event from two sources merges), else the sha256 of the URL
     * minus fragment and trailing slash.
     */
    private function hash(NewsArticle $article): string
    {
        if ($article->dedupKey !== null && trim($article->dedupKey) !== '') {
            return hash('sha256', $article->dedupKey);
        }
        $url = rtrim((string) strtok($article->url, '#'), '/');
        return hash('sha256', $url !== '' ? $url : $article->title);
    }
}
