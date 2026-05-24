<?php

namespace App\Tests\News;

use App\News\NewsArticle;
use App\News\NewsQualityFilter;
use PHPUnit\Framework\TestCase;

final class NewsQualityFilterTest extends TestCase
{
    private function article(string $title, ?string $publisher): NewsArticle
    {
        return new NewsArticle($title, 'https://e.com/x', new \DateTimeImmutable(), $publisher);
    }

    public function testBlockedPublisherDroppedForUntrustedSource(): void
    {
        $filter = new NewsQualityFilter();
        $article = $this->article('Acme reports record quarterly revenue', 'MarketBeat');

        $this->assertFalse($filter->accepts($article));
    }

    public function testTrustedSourceKeepsItsChosenPublisher(): void
    {
        $filter = new NewsQualityFilter();
        $article = $this->article('Acme reports record quarterly revenue', 'MarketBeat');

        // A user-curated custom source is trusted: the publisher blocklist is skipped.
        $this->assertTrue($filter->accepts($article, trustedSource: true));
    }

    public function testClickbaitStillDroppedEvenWhenTrusted(): void
    {
        $filter = new NewsQualityFilter();
        $article = $this->article('7 stocks to buy now before they explode', 'Curated Feed');

        $this->assertFalse($filter->accepts($article, trustedSource: true));
    }
}
