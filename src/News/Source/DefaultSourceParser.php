<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;
use App\News\NewsArticle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The fallback parser used when no bespoke {@see SourceParser} claims a source.
 * Fetches the source's target and, based on its detected mode, either reads it
 * as a feed (RSS/Atom, possibly via autodiscovery already resolved at add time)
 * or runs the HTML scraper cascade. Also updates the source's conditional-GET
 * validators so subsequent refreshes skip unchanged feeds.
 *
 * It implements SourceParser so the registry can treat it uniformly, but it is
 * injected explicitly as the fallback rather than matched via supports().
 */
final class DefaultSourceParser implements SourceParser
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly FeedReader $feedReader,
        private readonly HtmlArticleScraper $scraper,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function name(): string
    {
        return 'Default';
    }

    public function supports(AssetNewsSource $source): bool
    {
        return true; // claims everything; the registry only uses it as fallback
    }

    public function parse(AssetNewsSource $source, SafeUrlFetcher $fetcher): array
    {
        $res = $fetcher->fetch($source->fetchTarget(), $source->getEtag(), $source->getLastModified());

        if ($res->notModified) {
            return []; // unchanged since last fetch — nothing new to ingest
        }
        if (!$res->ok()) {
            throw new \RuntimeException('HTTP ' . $res->status);
        }

        // Persist validators for the next conditional GET.
        $source->setEtag($res->etag);
        $source->setLastModified($res->lastModified);

        if ($source->getScrapeMode() === AssetNewsSource::MODE_SCRAPE) {
            return $this->scraper->scrape($res->body, $res->finalUrl, $fetcher);
        }

        $feed = $this->feedReader->read($res->body, $source->getLabel());
        if ($feed !== null) {
            return $feed->items;
        }
        // The configured feed stopped being a feed (site redesign?) — degrade to
        // scraping the response rather than silently returning nothing.
        $this->logger->info('Configured feed no longer parses; scraping instead', ['url' => $source->fetchTarget()]);
        return $this->scraper->scrape($res->body, $res->finalUrl, $fetcher);
    }
}
