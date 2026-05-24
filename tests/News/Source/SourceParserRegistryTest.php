<?php

namespace App\Tests\News\Source;

use App\Entity\AssetNewsSource;
use App\News\Source\DefaultSourceParser;
use App\News\Source\FeedReader;
use App\News\Source\HtmlArticleScraper;
use App\News\Source\SafeUrlFetcher;
use App\News\Source\SourceParser;
use App\News\Source\SourceParserRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class SourceParserRegistryTest extends TestCase
{
    private function default(): DefaultSourceParser
    {
        return new DefaultSourceParser(new SafeUrlFetcher(new MockHttpClient()), new FeedReader(), new HtmlArticleScraper());
    }

    public function testBespokeParserWinsWhenItSupports(): void
    {
        $default = $this->default();
        $bespoke = new class implements SourceParser {
            public bool $claims = true;
            public function supports(AssetNewsSource $s): bool { return $this->claims; }
            public function parse(AssetNewsSource $s, SafeUrlFetcher $f): array { return []; }
            public function name(): string { return 'Bespoke'; }
        };

        // Default is also in the tagged iterable (as in real DI) and must be skipped.
        $registry = new SourceParserRegistry([$default, $bespoke], $default);
        $source = (new AssetNewsSource())->setUrl('https://x.com/feed');

        $this->assertSame($bespoke, $registry->resolve($source));
    }

    public function testFallsBackToDefaultWhenNoneSupport(): void
    {
        $default = $this->default();
        $bespoke = new class implements SourceParser {
            public function supports(AssetNewsSource $s): bool { return false; }
            public function parse(AssetNewsSource $s, SafeUrlFetcher $f): array { return []; }
            public function name(): string { return 'Bespoke'; }
        };

        $registry = new SourceParserRegistry([$default, $bespoke], $default);
        $source = (new AssetNewsSource())->setUrl('https://x.com/feed');

        $this->assertSame($default, $registry->resolve($source));
        $this->assertSame('Default', $registry->resolve($source)->name());
    }
}
