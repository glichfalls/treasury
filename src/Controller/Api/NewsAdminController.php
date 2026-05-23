<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\News\NewsProvider;
use App\Schedule\RefreshNewsMessage;
use App\Settings\SettingsService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/news')]
#[IsGranted('ROLE_ADMIN')]
class NewsAdminController extends AbstractController
{
    /**
     * @param iterable<NewsProvider> $providers
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly SettingsService $settings,
        private readonly Connection $conn,
        #[AutowireIterator('app.news_provider')]
        private readonly iterable $providers,
    ) {}

    #[Route('/refresh', name: 'api_admin_news_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        $this->bus->dispatch(new RefreshNewsMessage());
        return new JsonResponse(['queued' => true], 202);
    }

    /** Aggregator configuration: which sources are on, fetch volume, broad subs. */
    #[Route('/config', name: 'api_admin_news_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'sources' => array_map(fn(string $s) => [
                'key' => $s,
                'enabled' => $this->settings->isSourceEnabled($s),
            ], $this->sourceKeys()),
            'volume' => $this->settings->getNewsVolume(),
            'broadSubreddits' => implode(', ', $this->settings->getRedditBroadSubreddits()),
        ]);
    }

    #[Route('/config', name: 'api_admin_news_config_update', methods: ['PATCH'])]
    public function updateConfig(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid body'], 422);
        }

        if (array_key_exists('enabledSources', $body) && is_array($body['enabledSources'])) {
            $enabled = array_map('strval', $body['enabledSources']);
            $disabled = array_values(array_diff($this->sourceKeys(), $enabled));
            $this->settings->set(SettingsService::NEWS_DISABLED_SOURCES, implode(',', $disabled));
        }
        if (array_key_exists('volume', $body)) {
            $volume = (string) $body['volume'];
            if (!in_array($volume, ['low', 'medium', 'high'], true)) {
                return new JsonResponse(['error' => 'Volume must be low, medium or high'], 422);
            }
            $this->settings->set(SettingsService::NEWS_VOLUME, $volume);
        }
        if (array_key_exists('broadSubreddits', $body)) {
            $this->settings->set(SettingsService::REDDIT_BROAD_SUBREDDITS, (string) $body['broadSubreddits']);
        }

        return $this->config();
    }

    /** Held, tickered holdings with their per-asset news overrides. */
    #[Route('/assets', name: 'api_admin_news_assets', methods: ['GET'])]
    public function assets(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT a.isin, a.ticker, a.name, a.news_enabled, a.news_market_topic, a.reddit_subreddit
             FROM assets a
             INNER JOIN transactions t ON t.asset_isin = a.isin
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner AND a.ticker IS NOT NULL
             GROUP BY a.id, a.isin, a.ticker, a.name, a.news_enabled, a.news_market_topic, a.reddit_subreddit
             HAVING SUM(t.asset_quantity) <> 0
             ORDER BY a.ticker",
            ['owner' => $user->getId()->toBinary()],
            ['owner' => ParameterType::BINARY],
        );

        return new JsonResponse(array_map(fn(array $r) => [
            'isin' => $r['isin'],
            'ticker' => $r['ticker'],
            'name' => $r['name'],
            'newsEnabled' => (bool) $r['news_enabled'],
            'newsMarketTopic' => $r['news_market_topic'],
            'redditSubreddit' => $r['reddit_subreddit'],
        ], $rows));
    }

    /** @return string[] */
    private function sourceKeys(): array
    {
        $keys = [];
        foreach ($this->providers as $provider) {
            $keys[] = $provider->source();
        }
        sort($keys);
        return $keys;
    }
}
