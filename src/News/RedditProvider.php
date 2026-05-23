<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reddit chatter as `social` news. Uses app-only OAuth (a free "script" app's
 * client ID + secret via the client_credentials grant). For each holding it
 * pulls the dedicated subreddit (when one is set on the asset) and searches the
 * broad market subreddits (wallstreetbets, stocks, …) for the ticker/company.
 * No-ops without credentials. Sentiment is left to the AI classifier.
 */
final class RedditProvider implements NewsProvider
{
    private const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';
    private const API_BASE = 'https://oauth.reddit.com';
    private const UA = 'treasury-news/1.0 (personal net-worth tracker)';

    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'reddit';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $token = $this->token();
        if ($token === null) {
            return [];
        }

        /** @var array<string, NewsArticle> $byPermalink */
        $byPermalink = [];

        // Dedicated company subreddit, if the asset has one — its hottest posts.
        $sub = $asset->getRedditSubreddit();
        if ($sub !== null && $sub !== '') {
            foreach ($this->listing('/r/' . rawurlencode($sub) . '/hot', ['limit' => min($limit, 8)], $token) as $a) {
                $byPermalink[$a->url] = $a;
            }
        }

        // Broad market subreddits, searched for this holding.
        $query = $this->query($asset);
        $broad = $this->settings->getRedditBroadSubreddits();
        if ($query !== null && $broad !== []) {
            $multi = implode('+', array_map('rawurlencode', $broad));
            $articles = $this->listing(
                '/r/' . $multi . '/search',
                ['q' => $query, 'restrict_sr' => 'on', 'sort' => 'new', 'limit' => $limit, 'type' => 'link'],
                $token,
            );
            foreach ($articles as $a) {
                $byPermalink[$a->url] = $a;
            }
        }

        return array_values($byPermalink);
    }

    /**
     * @param array<string, scalar> $query
     * @return NewsArticle[]
     */
    private function listing(string $path, array $query, string $token): array
    {
        try {
            $data = $this->http->request('GET', self::API_BASE . $path, [
                'query' => $query + ['raw_json' => 1],
                'headers' => ['Authorization' => 'Bearer ' . $token, 'User-Agent' => self::UA],
                'timeout' => 12,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Reddit listing failed', ['path' => $path, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($data['data']['children'] ?? [] as $child) {
            $post = $child['data'] ?? null;
            if (!is_array($post)) {
                continue;
            }
            $title = $post['title'] ?? null;
            $permalink = $post['permalink'] ?? null;
            if (!is_string($title) || trim($title) === '' || !is_string($permalink) || $permalink === '') {
                continue;
            }
            // Skip pinned megathreads and NSFW noise.
            if (($post['stickied'] ?? false) === true || ($post['over_18'] ?? false) === true) {
                continue;
            }

            $score = (int) ($post['score'] ?? 0);
            $comments = (int) ($post['num_comments'] ?? 0);
            $selftext = is_string($post['selftext'] ?? null) ? trim($post['selftext']) : '';
            $snippet = $selftext !== ''
                ? (mb_strlen($selftext) > 280 ? mb_substr($selftext, 0, 277) . '…' : $selftext)
                : sprintf('%d upvotes · %d comments', $score, $comments);

            $out[] = new NewsArticle(
                title: trim($title),
                url: 'https://www.reddit.com' . $permalink,
                publishedAt: (new \DateTimeImmutable())->setTimestamp((int) ($post['created_utc'] ?? time())),
                publisher: is_string($post['subreddit_name_prefixed'] ?? null) ? $post['subreddit_name_prefixed'] : 'reddit',
                kind: NewsItem::KIND_SOCIAL,
                snippet: $snippet,
            );
        }
        return $out;
    }

    private function query(Asset $asset): ?string
    {
        $terms = [];
        $name = $asset->getName();
        if ($name !== null && trim($name) !== '') {
            $terms[] = '"' . trim($name) . '"';
        }
        $ticker = $asset->getTicker();
        if ($ticker !== null && trim($ticker) !== '') {
            $terms[] = strtoupper(explode('.', trim($ticker))[0]);
        }
        if ($terms === []) {
            return null;
        }
        return implode(' OR ', array_unique($terms));
    }

    /** Fetch (and cache for the run) an app-only access token. */
    private function token(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $id = $this->settings->get(SettingsService::REDDIT_CLIENT_ID);
        $secret = $this->settings->get(SettingsService::REDDIT_CLIENT_SECRET);
        if ($id === null || $secret === null) {
            return null;
        }
        try {
            $data = $this->http->request('POST', self::TOKEN_URL, [
                'auth_basic' => [$id, $secret],
                'headers' => ['User-Agent' => self::UA],
                'body' => ['grant_type' => 'client_credentials'],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Reddit auth failed', ['error' => $e->getMessage()]);
            return null;
        }
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        return $this->token = $token;
    }
}
