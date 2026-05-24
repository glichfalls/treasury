<?php

namespace App\Tests\News\Source;

use App\News\Source\PublishedDateExtractor;
use PHPUnit\Framework\TestCase;

final class PublishedDateExtractorTest extends TestCase
{
    private PublishedDateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PublishedDateExtractor();
    }

    public function testReadsJsonLdDatePublished(): void
    {
        $html = <<<'HTML'
            <html><head><script type="application/ld+json">
            {"@type":"NewsArticle","headline":"X","datePublished":"2024-03-18T09:30:00Z"}
            </script></head><body></body></html>
            HTML;

        $d = $this->extractor->extract($html);

        $this->assertNotNull($d);
        $this->assertSame('2024-03-18', $d->format('Y-m-d'));
    }

    public function testReadsOpenGraphPublishedTime(): void
    {
        $html = '<html><head><meta property="article:published_time" content="2023-11-02T07:00:00+00:00"></head></html>';

        $d = $this->extractor->extract($html);

        $this->assertNotNull($d);
        $this->assertSame('2023-11-02', $d->format('Y-m-d'));
    }

    public function testReadsTimeTagDatetime(): void
    {
        $html = '<article><time datetime="2022-06-15">June 15</time> body</article>';

        $d = $this->extractor->extract($html);

        $this->assertNotNull($d);
        $this->assertSame('2022-06-15', $d->format('Y-m-d'));
    }

    /**
     * The real-world case that prompted this: a CMS that emits a broken JSON-LD
     * template (month 33) and no meta/time date, with only a visible dateline.
     * The garbage must be rejected and the printed date recovered.
     */
    public function testRejectsBrokenJsonLdAndFallsBackToTextDateline(): void
    {
        $html = <<<'HTML'
            <html><head>
            <script type="application/ld+json">
            {"@type":"NewsArticle","datePublished":"2024-33-9TAD::Z","dateModified":"2024-33-9TAD::Z",
             "headline":"iQPS Books Three New Launches on Electron"}
            </script>
            <meta property="og:type" content="Website">
            </head>
            <body><main><article>
              <h1>iQPS Books Three New Launches on Electron</h1>
              <p>April 9, 2024</p>
              <p>Long Beach, Calif. — iQPS today announced three new launches.</p>
              <p>The prior contract closed December 31, 2023.</p>
            </article></main></body></html>
            HTML;

        $d = $this->extractor->extract($html);

        $this->assertNotNull($d);
        $this->assertSame('2024-04-09', $d->format('Y-m-d'));
    }

    public function testRejectsFutureDate(): void
    {
        $html = '<html><head><meta property="article:published_time" content="2099-01-01T00:00:00Z"></head></html>';

        $this->assertNull($this->extractor->extract($html));
    }

    public function testReturnsNullWhenNoDateAnywhere(): void
    {
        $html = '<html><body><p>Just some prose with no dates at all.</p></body></html>';

        $this->assertNull($this->extractor->extract($html));
    }

    public function testPrefersStructuredDateOverText(): void
    {
        $html = <<<'HTML'
            <html><head><meta property="article:published_time" content="2021-07-04"></head>
            <body><p>Updated August 9, 2022 in this paragraph.</p></body></html>
            HTML;

        $d = $this->extractor->extract($html);

        $this->assertNotNull($d);
        $this->assertSame('2021-07-04', $d->format('Y-m-d'));
    }
}
