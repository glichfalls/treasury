<?php

namespace App\Tests\News\Source;

use App\News\Source\SafeUrlFetcher;
use App\News\Source\UnsafeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafeUrlFetcherTest extends TestCase
{
    private function fetcher(MockHttpClient $http): SafeUrlFetcher
    {
        return new SafeUrlFetcher($http);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/x'];
        yield 'localhost' => ['http://localhost/x'];
        yield 'private 10' => ['http://10.0.0.5/x'];
        yield 'private 192.168' => ['http://192.168.1.1/x'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://192.0.2.10/x'];
        yield 'no host' => ['/relative/path'];
    }

    #[DataProvider('blockedUrls')]
    public function testAssertSafeRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->fetcher(new MockHttpClient())->assertSafe($url);
    }

    public function testAssertSafeAllowsPublicIpLiteral(): void
    {
        // 192.0.2.0/24 is TEST-NET (documentation) — a routable-looking public
        // literal that avoids a real DNS lookup in the test.
        $this->assertSame('http://192.0.2.10/feed.xml', $this->fetcher(new MockHttpClient())->assertSafe('http://192.0.2.10/feed.xml'));
    }

    public function testFetchReturnsBodyAndValidators(): void
    {
        $http = new MockHttpClient([
            new MockResponse('<rss/>', ['response_headers' => ['ETag' => '"v1"', 'Content-Type' => 'application/rss+xml']]),
        ]);
        $res = $this->fetcher($http)->fetch('http://192.0.2.10/feed.xml');

        $this->assertTrue($res->ok());
        $this->assertSame('<rss/>', $res->body);
        $this->assertSame('"v1"', $res->etag);
    }

    public function testConditionalGetReturnsNotModified(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 304])]);
        $res = $this->fetcher($http)->fetch('http://192.0.2.10/feed.xml', '"v1"');

        $this->assertTrue($res->notModified);
        $this->assertSame('', $res->body);
    }

    public function testFollowsValidatedRedirect(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'http://192.0.2.10/final']]),
            new MockResponse('final body'),
        ]);
        $res = $this->fetcher($http)->fetch('http://192.0.2.10/start');

        $this->assertSame('final body', $res->body);
        $this->assertSame('http://192.0.2.10/final', $res->finalUrl);
    }

    public function testRedirectToPrivateHostIsBlocked(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://169.254.169.254/']]),
        ]);
        $this->expectException(UnsafeUrlException::class);
        $this->fetcher($http)->fetch('http://192.0.2.10/start');
    }

    public function testAbsolutize(): void
    {
        $this->assertSame('https://x.com/a/b', SafeUrlFetcher::absolutize('https://x.com/news/', '/a/b'));
        $this->assertSame('https://x.com/news/story', SafeUrlFetcher::absolutize('https://x.com/news/index', 'story'));
        $this->assertSame('https://x.com/feed', SafeUrlFetcher::absolutize('https://x.com/a/b', '../feed'));
        $this->assertSame('https://cdn.com/f.xml', SafeUrlFetcher::absolutize('https://x.com/p', '//cdn.com/f.xml'));
        $this->assertSame('https://other.com/x', SafeUrlFetcher::absolutize('https://x.com/p', 'https://other.com/x'));
        $this->assertNull(SafeUrlFetcher::absolutize('https://x.com/p', '#frag'));
    }
}
