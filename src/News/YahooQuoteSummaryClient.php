<?php

namespace App\News;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared access to Yahoo's quoteSummary endpoint (analyst ratings, earnings, …).
 * Yahoo gates it behind a cookie+crumb handshake; we mint the A1 cookie from
 * fc.yahoo.com (which sidesteps the EU consent redirect that finance.yahoo.com
 * triggers from datacenter IPs), then fetch the crumb. Credentials are cached
 * for the run. Keyless.
 */
final class YahooQuoteSummaryClient
{
    private const QUOTE_SUMMARY = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/';
    private const CRUMB_URL = 'https://query1.finance.yahoo.com/v1/test/getcrumb';
    private const COOKIE_URL = 'https://fc.yahoo.com/';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /** @var array{cookie: string, crumb: string}|null|false null=untried, false=failed */
    private array|null|false $creds = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Fetch the given comma-separated modules for a ticker; returns the first
     * result object, or null on any failure.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $ticker, string $modules): ?array
    {
        $creds = $this->credentials();
        if ($creds === false) {
            return null;
        }
        try {
            $data = $this->http->request('GET', self::QUOTE_SUMMARY . rawurlencode($ticker), [
                'query' => ['modules' => $modules, 'crumb' => $creds['crumb']],
                'headers' => ['User-Agent' => self::UA, 'Cookie' => $creds['cookie']],
                'timeout' => 12,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->info('Yahoo quoteSummary failed', ['ticker' => $ticker, 'modules' => $modules, 'error' => $e->getMessage()]);
            return null;
        }
        $result = $data['quoteSummary']['result'][0] ?? null;
        return is_array($result) ? $result : null;
    }

    /**
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
            $setCookies = $resp->getHeaders(false)['set-cookie'] ?? [];
            $cookie = $this->cookieHeader($setCookies);
            if ($cookie === '') {
                return $this->creds = false;
            }
            $crumb = trim($this->http->request('GET', self::CRUMB_URL, [
                'headers' => ['User-Agent' => self::UA, 'Cookie' => $cookie],
                'timeout' => 10,
            ])->getContent(false));
        } catch (\Throwable $e) {
            $this->logger->info('Yahoo crumb handshake failed', ['error' => $e->getMessage()]);
            return $this->creds = false;
        }

        if ($crumb === '' || str_contains($crumb, '<') || str_contains($crumb, '{')) {
            return $this->creds = false; // error page / JSON error, not a crumb
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
