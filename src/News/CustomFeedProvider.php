<?php

namespace App\News;

use App\Entity\Asset;
use App\News\Source\SafeUrlFetcher;
use App\News\Source\SourceParserRegistry;
use App\News\Source\UnsafeUrlException;
use App\Repository\AssetNewsSourceRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * News from the user-curated, per-asset sources (RSS feeds / websites). Unlike
 * the other providers it derives nothing from the ticker — it reads the asset's
 * configured AssetNewsSource rows and dispatches each to the parser the registry
 * picks (a bespoke one, else the default cascade). Each source's fetch outcome
 * (etag, last status/time) is recorded for the admin panel; the surrounding
 * NewsFetcher flush persists those mutations along with the new items.
 *
 * Items are tagged with their originating source via NewsArticle::withSource so
 * NewsFetcher can link the FK and the AI gate can honour per-source toggles.
 */
final class CustomFeedProvider implements NewsProvider
{
    public const SOURCE = 'custom';

    public function __construct(
        private readonly AssetNewsSourceRepository $sources,
        private readonly SourceParserRegistry $registry,
        private readonly SafeUrlFetcher $fetcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return self::SOURCE;
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $out = [];
        foreach ($this->sources->enabledForAsset($asset) as $source) {
            $parser = $this->registry->resolve($source);
            try {
                $articles = $parser->parse($source, $this->fetcher);
                $source->setLastStatus($articles === [] ? 'no items found' : 'ok');
            } catch (UnsafeUrlException $e) {
                $source->setLastStatus('error: ' . $e->getMessage());
                $articles = [];
            } catch (\Throwable $e) {
                $this->logger->warning('Custom source failed', [
                    'url' => $source->fetchTarget(),
                    'parser' => $parser->name(),
                    'error' => $e->getMessage(),
                ]);
                $source->setLastStatus('error: ' . mb_substr($e->getMessage(), 0, 200));
                $articles = [];
            }
            $source->setLastFetchedAt(new \DateTimeImmutable());

            $ref = $source->getId()->toRfc4122();
            foreach (array_slice($articles, 0, $limit) as $article) {
                $out[] = $article->withSource($ref, $source->getLabel());
            }
        }
        return $out;
    }
}
