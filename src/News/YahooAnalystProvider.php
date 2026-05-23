<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Analyst rating changes (upgrades/downgrades/initiations) from Yahoo's
 * quoteSummary — structured, high-signal, never clickbait. Keyless, but Yahoo
 * now gates quoteSummary behind a cookie+crumb handshake, so we fetch those
 * once per run. Each rating change becomes an `analyst_action` item with a
 * derived sentiment (upgrade = bullish, downgrade = bearish).
 */
final class YahooAnalystProvider implements NewsProvider
{
    private const QUOTE_SUMMARY = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/';
    private const CRUMB_URL = 'https://query1.finance.yahoo.com/v1/test/getcrumb';
    // fc.yahoo.com mints the A1 cookie without the EU consent redirect that
    // finance.yahoo.com triggers from datacenter IPs (it 404s but sets the cookie).
    private const COOKIE_URL = 'https://fc.yahoo.com/';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /** @var array{cookie: string, crumb: string}|null|false null=untried, false=failed */
    private array|null|false $creds = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'yahoo';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $ticker = $asset->getTicker();
        // Analyst ratings exist for single equities; funds/ETFs have none.
        if ($ticker === null || trim($ticker) === '' || $asset->getNewsMarketTopic() !== null) {
            return [];
        }
        $creds = $this->credentials();
        if ($creds === false) {
            return [];
        }

        try {
            $data = $this->http->request('GET', self::QUOTE_SUMMARY . rawurlencode(trim($ticker)), [
                'query' => ['modules' => 'upgradeDowngradeHistory', 'crumb' => $creds['crumb']],
                'headers' => ['User-Agent' => self::UA, 'Cookie' => $creds['cookie']],
                'timeout' => 12,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->info('Yahoo analyst fetch failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);
            return [];
        }

        $history = $data['quoteSummary']['result'][0]['upgradeDowngradeHistory']['history'] ?? null;
        if (!is_array($history)) {
            return [];
        }

        $out = [];
        foreach ($history as $row) {
            $firm = $row['firm'] ?? null;
            $to = $row['toGrade'] ?? null;
            if (!is_string($firm) || trim($firm) === '' || !is_string($to) || trim($to) === '') {
                continue;
            }
            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            // Only real rating changes — upgrades, downgrades, new coverage. Plain
            // "maintains"/"reiterates" are low-signal noise.
            if (!in_array($action, ['up', 'down', 'init'], true)) {
                continue;
            }
            $from = is_string($row['fromGrade'] ?? null) && trim($row['fromGrade']) !== '' ? trim($row['fromGrade']) : null;
            $epoch = (int) ($row['epochGradeDate'] ?? time());

            $out[] = new NewsArticle(
                title: $this->title($firm, $from, trim($to), $action),
                // Unique per rating so the per-asset dedup keeps each change.
                url: 'https://finance.yahoo.com/quote/' . rawurlencode(trim($ticker)) . '/analysis?rating=' . $epoch . '-' . rawurlencode($firm),
                publishedAt: (new \DateTimeImmutable())->setTimestamp($epoch),
                publisher: trim($firm),
                kind: NewsItem::KIND_ANALYST_ACTION,
                snippet: $this->actionLabel($action),
                sentiment: $this->sentiment($action),
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function title(string $firm, ?string $from, string $to, string $action): string
    {
        if (in_array($action, ['init', 'reit', 'main'], true) || $from === null || $from === '' || $from === $to) {
            $verb = match ($action) {
                'init' => 'initiated at',
                'main' => 'maintains',
                default => 'reiterates',
            };
            return sprintf('%s: %s %s', $firm, $verb, $to);
        }
        return sprintf('%s: %s → %s', $firm, $from, $to);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'up' => 'Upgrade',
            'down' => 'Downgrade',
            'init' => 'Initiated coverage',
            'reit' => 'Reiterated',
            default => 'Rating change',
        };
    }

    private function sentiment(string $action): string
    {
        return match ($action) {
            'up' => NewsItem::SENTIMENT_BULLISH,
            'down' => NewsItem::SENTIMENT_BEARISH,
            default => NewsItem::SENTIMENT_NEUTRAL,
        };
    }

    /**
     * Yahoo cookie + crumb, fetched once per run. The cookie comes from a normal
     * page load; the crumb endpoint then mints a token valid with that cookie.
     *
     * @return array{cookie: string, crumb: string}|false
     */
    private function credentials(): array|false
    {
        if ($this->creds !== null) {
            return $this->creds;
        }
        try {
            $resp = $this->http->request('GET', self::COOKIE_URL, [
                'headers' => ['User-Agent' => self::UA, 'Accept' => 'text/html'],
                'timeout' => 10,
            ]);
            $resp->getStatusCode();
            $setCookies = $resp->getHeaders(false)['set-cookie'] ?? [];
            $cookie = $this->cookieHeader($setCookies);
            if ($cookie === '') {
                return $this->creds = false;
            }
            $crumb = $this->http->request('GET', self::CRUMB_URL, [
                'headers' => ['User-Agent' => self::UA, 'Cookie' => $cookie],
                'timeout' => 10,
            ])->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->info('Yahoo crumb handshake failed', ['error' => $e->getMessage()]);
            return $this->creds = false;
        }

        $crumb = trim($crumb);
        if ($crumb === '' || str_contains($crumb, '<')) {
            return $this->creds = false; // got an error page, not a crumb
        }
        return $this->creds = ['cookie' => $cookie, 'crumb' => $crumb];
    }

    /** @param string[] $setCookies */
    private function cookieHeader(array $setCookies): string
    {
        $pairs = [];
        foreach ($setCookies as $sc) {
            $pair = explode(';', $sc, 2)[0];
            if (str_contains($pair, '=')) {
                $pairs[] = trim($pair);
            }
        }
        return implode('; ', $pairs);
    }
}
