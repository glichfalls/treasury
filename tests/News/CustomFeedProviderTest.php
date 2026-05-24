<?php

namespace App\Tests\News;

use App\Entity\Asset;
use App\Entity\AssetNewsSource;
use App\News\CustomFeedProvider;
use App\News\Source\DefaultSourceParser;
use App\News\Source\FeedReader;
use App\News\Source\HtmlArticleScraper;
use App\News\Source\SafeUrlFetcher;
use App\News\Source\SourceParserRegistry;
use App\Repository\AssetNewsSourceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CustomFeedProviderTest extends TestCase
{
    private const RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <title>Curated</title>
          <item><title>Holding-specific update one</title><link>https://e.com/1</link></item>
          <item><title>Holding-specific update two</title><link>https://e.com/2</link></item>
        </channel></rss>
        XML;

    public function testReadsAssetSourcesAndTagsArticles(): void
    {
        $asset = (new Asset())->setIsin('US0378331005');
        $source = (new AssetNewsSource())
            ->setAsset($asset)
            ->setUrl('http://192.0.2.10/feed.xml')
            ->setType(AssetNewsSource::TYPE_RSS)
            ->setScrapeMode(AssetNewsSource::MODE_FEED)
            ->setLabel('My Feed')
            ->setEnabled(true);

        $repo = $this->createStub(AssetNewsSourceRepository::class);
        $repo->method('enabledForAsset')->willReturn([$source]);

        $http = new MockHttpClient([new MockResponse(self::RSS)]);
        $fetcher = new SafeUrlFetcher($http);
        $default = new DefaultSourceParser($fetcher, new FeedReader(), new HtmlArticleScraper());
        $registry = new SourceParserRegistry([$default], $default);

        $provider = new CustomFeedProvider($repo, $registry, $fetcher);
        $articles = $provider->fetchForAsset($asset, 10);

        $this->assertCount(2, $articles);
        $ref = $source->getId()->toRfc4122();
        foreach ($articles as $article) {
            $this->assertSame($ref, $article->sourceRef);
            $this->assertSame('My Feed', $article->publisher);
        }
        $this->assertSame('ok', $source->getLastStatus());
        $this->assertNotNull($source->getLastFetchedAt());
    }

    public function testRecordsErrorStatusOnUnsafeUrl(): void
    {
        $asset = (new Asset())->setIsin('US0378331005');
        $source = (new AssetNewsSource())
            ->setAsset($asset)
            ->setUrl('http://169.254.169.254/meta')
            ->setScrapeMode(AssetNewsSource::MODE_FEED)
            ->setEnabled(true);

        $repo = $this->createStub(AssetNewsSourceRepository::class);
        $repo->method('enabledForAsset')->willReturn([$source]);

        $fetcher = new SafeUrlFetcher(new MockHttpClient());
        $default = new DefaultSourceParser($fetcher, new FeedReader(), new HtmlArticleScraper());
        $registry = new SourceParserRegistry([$default], $default);

        $provider = new CustomFeedProvider($repo, $registry, $fetcher);
        $articles = $provider->fetchForAsset($asset, 10);

        $this->assertSame([], $articles);
        $this->assertStringStartsWith('error:', (string) $source->getLastStatus());
    }
}
