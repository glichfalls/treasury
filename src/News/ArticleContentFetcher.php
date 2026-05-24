<?php

namespace App\News;

use App\News\Source\PublishedDateExtractor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Best-effort fetch of an article page: plain-text body for the AI deep-brief,
 * plus the article's own publication date (aggregator/listing dates are often
 * the index or fetch time). Follows redirects (Google News links bounce to the
 * publisher) and strips boilerplate. Returns null only when the page can't be
 * fetched at all, so callers degrade to the snippet and the stored date.
 */
final class ArticleContentFetcher
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly PublishedDateExtractor $dateExtractor = new PublishedDateExtractor(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function fetch(string $url): ?FetchedArticle
    {
        try {
            $res = $this->http->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
                'timeout' => 15,
                'max_redirects' => 10,
            ]);
            if ($res->getStatusCode() >= 400) {
                return null;
            }
            $html = $res->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->info('Article fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        if (trim($html) === '') {
            return null;
        }
        return new FetchedArticle(
            text: $this->extract($html),
            publishedAt: $this->dateExtractor->extract($html),
        );
    }

    /** Pull the meaningful paragraph text out of a page; crude but AI-tolerant. */
    private function extract(string $html): ?string
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//header|//footer|//nav|//aside|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Prefer paragraphs inside <article>/<main>; fall back to all paragraphs.
        $paras = $xpath->query('//article//p|//main//p');
        if ($paras === false || $paras->length === 0) {
            $paras = $xpath->query('//p');
        }

        $text = '';
        if ($paras !== false) {
            foreach ($paras as $p) {
                $t = trim(preg_replace('/\s+/', ' ', $p->textContent) ?? '');
                // Skip nav/cookie-banner fragments; keep sentence-length paragraphs.
                if (mb_strlen($t) >= 40) {
                    $text .= $t . "\n\n";
                }
                if (mb_strlen($text) > 6000) {
                    break;
                }
            }
        }

        $text = trim($text);
        return $text !== '' ? mb_substr($text, 0, 6000) : null;
    }
}
