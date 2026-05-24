<?php

namespace App\Tests\News\Source;

use App\Entity\AssetNewsSource;
use App\News\Source\FeedDiscoverer;
use PHPUnit\Framework\TestCase;

final class FeedDiscovererTest extends TestCase
{
    public function testDiscoversAndAbsolutizesFeedLinks(): void
    {
        $html = <<<'HTML'
            <html><head>
              <link rel="alternate" type="application/rss+xml" title="Site RSS" href="/feed.xml">
              <link rel="alternate" type="application/atom+xml" href="https://cdn.site.com/atom">
              <link rel="stylesheet" href="/style.css">
            </head><body></body></html>
            HTML;

        $found = (new FeedDiscoverer())->discover($html, 'https://site.com/news/');

        $this->assertCount(2, $found);
        $this->assertSame('https://site.com/feed.xml', $found[0]['url']);
        $this->assertSame('Site RSS', $found[0]['title']);
        $this->assertSame(AssetNewsSource::TYPE_RSS, $found[0]['type']);
        $this->assertSame('https://cdn.site.com/atom', $found[1]['url']);
        $this->assertSame(AssetNewsSource::TYPE_ATOM, $found[1]['type']);
    }

    public function testNoFeedsReturnsEmpty(): void
    {
        $this->assertSame([], (new FeedDiscoverer())->discover('<html><head></head></html>', 'https://site.com'));
    }
}
