<?php

namespace App\Tests\News\Source;

use App\Entity\AssetNewsSource;
use App\News\Source\FeedReader;
use PHPUnit\Framework\TestCase;

final class FeedReaderTest extends TestCase
{
    public function testReadsRss(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Example Feed</title>
                <item>
                  <title>First &amp; best story</title>
                  <link>https://e.com/1</link>
                  <pubDate>Mon, 19 May 2025 10:00:00 GMT</pubDate>
                  <description>A description long enough to keep as a snippet.</description>
                </item>
                <item>
                  <title>Second story here</title>
                  <link>https://e.com/2</link>
                </item>
              </channel>
            </rss>
            XML;

        $feed = (new FeedReader())->read($xml, 'Fallback');
        $this->assertNotNull($feed);
        $this->assertSame('Example Feed', $feed->title);
        $this->assertSame(AssetNewsSource::TYPE_RSS, $feed->type);
        $this->assertCount(2, $feed->items);
        $this->assertSame('First & best story', $feed->items[0]->title);
        $this->assertSame('https://e.com/1', $feed->items[0]->url);
        $this->assertSame('2025-05-19', $feed->items[0]->publishedAt->format('Y-m-d'));
        $this->assertSame('Fallback', $feed->items[1]->publisher);
    }

    public function testReadsAtom(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Atom Example</title>
              <entry>
                <title>Atom one</title>
                <link href="https://a.com/x" rel="alternate"/>
                <published>2025-05-18T08:00:00Z</published>
                <summary>Summary text that is plenty long enough here.</summary>
                <author><name>Jane Doe</name></author>
              </entry>
            </feed>
            XML;

        $feed = (new FeedReader())->read($xml);
        $this->assertNotNull($feed);
        $this->assertSame(AssetNewsSource::TYPE_ATOM, $feed->type);
        $this->assertCount(1, $feed->items);
        $this->assertSame('Atom one', $feed->items[0]->title);
        $this->assertSame('https://a.com/x', $feed->items[0]->url);
        $this->assertSame('Jane Doe', $feed->items[0]->publisher);
    }

    public function testReturnsNullForNonFeed(): void
    {
        $this->assertNull((new FeedReader())->read('<html><body>not a feed</body></html>'));
        $this->assertNull((new FeedReader())->read('garbage'));
        $this->assertNull((new FeedReader())->read(''));
    }
}
