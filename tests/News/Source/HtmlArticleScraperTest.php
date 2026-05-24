<?php

namespace App\Tests\News\Source;

use App\News\Source\HtmlArticleScraper;
use PHPUnit\Framework\TestCase;

final class HtmlArticleScraperTest extends TestCase
{
    public function testExtractsFromJsonLdItemList(): void
    {
        $html = <<<'HTML'
            <html><head>
            <script type="application/ld+json">
            {"@context":"https://schema.org","@type":"ItemList","itemListElement":[
              {"@type":"NewsArticle","headline":"Big news today","url":"/story/big","datePublished":"2025-05-20T00:00:00Z","publisher":{"name":"The Wire"}},
              {"@type":"NewsArticle","headline":"Another development","url":"https://site.com/story/two","datePublished":"2025-05-19"}
            ]}
            </script>
            </head><body></body></html>
            HTML;

        $items = (new HtmlArticleScraper())->fromJsonLd($html, 'https://site.com/news');

        $this->assertCount(2, $items);
        $this->assertSame('Big news today', $items[0]->title);
        $this->assertSame('https://site.com/story/big', $items[0]->url);
        $this->assertSame('The Wire', $items[0]->publisher);
        $this->assertSame('https://site.com/story/two', $items[1]->url);
    }

    public function testDomHeuristicHarvestsDatedArticleLinksAndSkipsChrome(): void
    {
        $html = <<<'HTML'
            <html><body>
              <nav><a href="/about">About</a><a href="/contact">Contact us today</a></nav>
              <main>
                <article>
                  <a href="/2025/news/markets-rally-on-strong-earnings-report">Markets rally on strong earnings as tech leads gains</a>
                  <time datetime="2025-05-20">May 20</time>
                </article>
                <article>
                  <a href="/2025/news/central-bank-holds-rates-steady-again">Central bank holds rates steady amid mixed signals</a>
                  <time datetime="2025-05-19">May 19</time>
                </article>
              </main>
              <footer><a href="/privacy">Privacy policy and legal terms here</a></footer>
            </body></html>
            HTML;

        $items = (new HtmlArticleScraper())->fromDomHeuristic($html, 'https://site.com/news');

        $this->assertCount(2, $items);
        $this->assertStringContainsString('Markets rally', $items[0]->title);
        foreach ($items as $item) {
            $this->assertStringContainsString('site.com/2025/news/', $item->url);
        }
    }

    public function testScrapePrefersJsonLdOverHeuristic(): void
    {
        $html = <<<'HTML'
            <html><head>
            <script type="application/ld+json">
            {"@type":"NewsArticle","headline":"Structured headline wins","url":"/s"}
            </script>
            </head><body>
              <main><article><a href="/2025/x/some-long-heuristic-headline-link">A heuristic headline that is long enough</a></article></main>
            </body></html>
            HTML;

        $items = (new HtmlArticleScraper())->scrape($html, 'https://site.com');
        $this->assertCount(1, $items);
        $this->assertSame('Structured headline wins', $items[0]->title);
    }
}
