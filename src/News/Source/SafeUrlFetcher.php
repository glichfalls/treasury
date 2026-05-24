<?php

namespace App\News\Source;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP fetcher for user-supplied URLs, with an SSRF guard. Because the news
 * sources are URLs an admin pastes and the server fetches, an unguarded fetch
 * would let someone reach internal hosts (localhost, the Docker network, the
 * cloud metadata endpoint at 169.254.169.254). So every URL — and every
 * redirect hop — is validated to be http(s) and to resolve only to public IPs.
 *
 * Redirects are followed manually (max_redirects=0) so each Location can be
 * re-checked; the HTTP client's own redirect handling would skip that.
 *
 * Note: this resolves DNS at validation time, so a determined attacker could in
 * theory rebind between check and connect. For a single-user personal app the
 * risk is negligible; harden with a pinned-IP transport if that ever changes.
 */
final class SafeUrlFetcher
{
    private const UA = 'treasury-news/1.0 (personal net-worth tracker)';
    private const ACCEPT = 'application/rss+xml, application/atom+xml, application/xml, text/xml, text/html;q=0.9, */*;q=0.5';
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 4_000_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * GET a URL, following validated redirects. Pass the stored validators to
     * make it a conditional request (a 304 returns notModified with no body).
     *
     * @throws UnsafeUrlException when the URL (or a redirect target) is blocked
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult
    {
        $current = $this->assertSafe($url);
        $headers = ['User-Agent' => self::UA, 'Accept' => self::ACCEPT];
        if ($etag !== null && trim($etag) !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && trim($lastModified) !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $hops = 0;
        while (true) {
            $response = $this->http->request('GET', $current, [
                'headers' => $headers,
                'max_redirects' => 0,
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                if ($location === null || ++$hops > self::MAX_REDIRECTS) {
                    throw new UnsafeUrlException('Too many redirects or missing Location.');
                }
                $next = self::absolutize($current, $location);
                if ($next === null) {
                    throw new UnsafeUrlException('Unfollowable redirect target.');
                }
                $current = $this->assertSafe($next);
                continue;
            }

            $respHeaders = $response->getHeaders(false);
            $body = '';
            if ($status !== 304) {
                // Cap the body so a hostile/huge response can't exhaust memory.
                $body = mb_substr($response->getContent(false), 0, self::MAX_BYTES);
            }

            return new FetchResult(
                status: $status,
                body: $body,
                notModified: $status === 304,
                finalUrl: $current,
                etag: $respHeaders['etag'][0] ?? null,
                lastModified: $respHeaders['last-modified'][0] ?? null,
                contentType: $respHeaders['content-type'][0] ?? null,
            );
        }
    }

    /**
     * Validate scheme + host, returning the URL unchanged. Throws when the URL
     * is not http(s) or resolves to a non-public address.
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('URL must be absolute with a host.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UnsafeUrlException('Only http and https URLs are allowed.');
        }
        $host = $parts['host'];
        if (strcasecmp($host, 'localhost') === 0) {
            throw new UnsafeUrlException('Host is not allowed.');
        }

        foreach ($this->resolveIps($host) as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new UnsafeUrlException(sprintf('Host %s resolves to a non-public address.', $host));
            }
        }
        return $url;
    }

    /** @return string[] resolved IPs (the literal itself if host is already an IP) */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $a = @dns_get_record($host, DNS_A);
        foreach (is_array($a) ? $a : [] as $rec) {
            if (isset($rec['ip'])) {
                $ips[] = $rec['ip'];
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $rec) {
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        // A host that won't resolve can't be fetched anyway; treat as unsafe so
        // we fail closed rather than handing an unresolved name to the client.
        if ($ips === []) {
            throw new UnsafeUrlException(sprintf('Host %s did not resolve.', $host));
        }
        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        // NO_PRIV_RANGE rejects 10/8, 172.16/12, 192.168/16, fc00::/7;
        // NO_RES_RANGE rejects loopback, link-local (incl. 169.254.169.254), and other reserved blocks.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Resolve a possibly-relative URL against a base. Handles absolute,
     * protocol-relative (//host/x), root-relative (/x) and relative (x/y) forms.
     * Returns null when it can't be resolved to an absolute http(s) URL.
     */
    public static function absolutize(string $base, string $rel): ?string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return null;
        }
        // Already absolute.
        if (preg_match('#^https?://#i', $rel) === 1) {
            return $rel;
        }
        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $scheme = $b['scheme'];
        $host = $b['host'];
        $port = isset($b['port']) ? ':' . $b['port'] : '';
        $authority = $scheme . '://' . $host . $port;

        // Protocol-relative.
        if (str_starts_with($rel, '//')) {
            return $scheme . ':' . $rel;
        }
        // Root-relative.
        if (str_starts_with($rel, '/')) {
            return $authority . $rel;
        }
        // Fragment/query only.
        if (str_starts_with($rel, '#') || str_starts_with($rel, '?')) {
            return null;
        }
        // Relative to the base path's directory.
        $path = $b['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '/';
        $resolved = $dir . $rel;
        // Collapse ../ and ./ segments.
        $segments = [];
        foreach (explode('/', $resolved) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.' && $seg !== '') {
                $segments[] = $seg;
            }
        }
        return $authority . '/' . implode('/', $segments);
    }
}
