<?php

namespace App\Controller\Api;

use App\Entity\AssetNewsSource;
use App\Entity\User;
use App\News\DigestService;
use App\News\NewsProvider;
use App\News\Source\SourceParserRegistry;
use App\News\Source\SourceProber;
use App\News\Source\UnsafeUrlException;
use App\Repository\AssetNewsSourceRepository;
use App\Repository\AssetRepository;
use App\Schedule\RefreshNewsMessage;
use App\Settings\SettingsService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

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
        private readonly DigestService $digests,
        private readonly AssetRepository $assets,
        private readonly AssetNewsSourceRepository $sources,
        private readonly SourceProber $prober,
        private readonly SourceParserRegistry $parsers,
        private readonly EntityManagerInterface $em,
        #[AutowireIterator('app.news_provider')]
        private readonly iterable $providers,
    ) {}

    #[Route('/refresh', name: 'api_admin_news_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        $this->bus->dispatch(new RefreshNewsMessage());
        return new JsonResponse(['queued' => true], 202);
    }

    /** Generate the 24h briefing for the current user on demand. */
    #[Route('/digest', name: 'api_admin_news_digest', methods: ['POST'])]
    public function generateDigest(#[CurrentUser] User $user): JsonResponse
    {
        $digest = $this->digests->generate($user);
        if ($digest === null) {
            return new JsonResponse(['error' => 'Nothing to summarise, or no OpenAI key configured.'], 422);
        }
        return new JsonResponse(['generated' => true, 'itemCount' => $digest->getItemCount()], 201);
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
            'customAiEnabled' => $this->settings->isCustomNewsAiEnabled(),
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
        if (array_key_exists('customAiEnabled', $body)) {
            $this->settings->set(SettingsService::NEWS_CUSTOM_AI, $body['customAiEnabled'] ? '1' : '0');
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

    /** Custom news sources configured for a holding. */
    #[Route('/assets/{isin}/sources', name: 'api_admin_news_sources_list', methods: ['GET'])]
    public function listSources(string $isin): JsonResponse
    {
        $asset = $this->assets->findByIsin($isin);
        if ($asset === null) {
            throw new NotFoundHttpException();
        }
        return new JsonResponse(array_map(
            fn(AssetNewsSource $s) => $this->serializeSource($s),
            $this->sources->forAsset($asset),
        ));
    }

    /**
     * Probe a pasted URL without saving: report how it'll be ingested (feed vs
     * scrape, resolved feed, active parser) and a few sample items, so the admin
     * can confirm before committing.
     */
    #[Route('/assets/{isin}/sources/preview', name: 'api_admin_news_sources_preview', methods: ['POST'])]
    public function previewSource(string $isin, Request $request): JsonResponse
    {
        if ($this->assets->findByIsin($isin) === null) {
            throw new NotFoundHttpException();
        }
        $url = $this->urlFromBody($request);
        if ($url === null) {
            return new JsonResponse(['error' => 'A URL is required.'], 422);
        }
        try {
            $preview = $this->prober->probe($url);
        } catch (UnsafeUrlException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Could not read that URL: ' . $e->getMessage()], 422);
        }

        return new JsonResponse([
            'type' => $preview->type,
            'scrapeMode' => $preview->scrapeMode,
            'feedUrl' => $preview->feedUrl,
            'label' => $preview->label,
            'parser' => $preview->parser,
            'items' => array_map(fn($a) => [
                'title' => $a->title,
                'url' => $a->url,
                'publisher' => $a->publisher,
                'publishedAt' => $a->publishedAt->format(\DateTimeInterface::ATOM),
            ], $preview->items),
        ]);
    }

    /** Add a custom source to a holding (probes the URL to detect its type). */
    #[Route('/assets/{isin}/sources', name: 'api_admin_news_sources_create', methods: ['POST'])]
    public function createSource(string $isin, Request $request): JsonResponse
    {
        $asset = $this->assets->findByIsin($isin);
        if ($asset === null) {
            throw new NotFoundHttpException();
        }
        $url = $this->urlFromBody($request);
        if ($url === null) {
            return new JsonResponse(['error' => 'A URL is required.'], 422);
        }
        try {
            $preview = $this->prober->probe($url);
        } catch (UnsafeUrlException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Could not read that URL: ' . $e->getMessage()], 422);
        }

        $body = $this->jsonBody($request);
        $label = isset($body['label']) && is_string($body['label']) && trim($body['label']) !== ''
            ? trim($body['label'])
            : $preview->label;

        $source = (new AssetNewsSource())
            ->setAsset($asset)
            ->setUrl($url)
            ->setType($preview->type)
            ->setScrapeMode($preview->scrapeMode)
            ->setFeedUrl($preview->feedUrl)
            ->setLabel($label)
            ->setAiEnabled(!array_key_exists('aiEnabled', $body) || (bool) $body['aiEnabled']);
        $this->em->persist($source);
        $this->em->flush();

        return new JsonResponse($this->serializeSource($source), 201);
    }

    /** Toggle enabled/aiEnabled or rename a custom source. */
    #[Route('/sources/{id}', name: 'api_admin_news_sources_update', methods: ['PATCH'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function updateSource(string $id, Request $request): JsonResponse
    {
        $source = $this->findSource($id);
        $body = $this->jsonBody($request);

        if (array_key_exists('enabled', $body)) {
            $source->setEnabled((bool) $body['enabled']);
        }
        if (array_key_exists('aiEnabled', $body)) {
            $source->setAiEnabled((bool) $body['aiEnabled']);
        }
        if (array_key_exists('label', $body)) {
            $source->setLabel(is_string($body['label']) ? $body['label'] : null);
        }
        $this->em->flush();

        return new JsonResponse($this->serializeSource($source));
    }

    #[Route('/sources/{id}', name: 'api_admin_news_sources_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function deleteSource(string $id): JsonResponse
    {
        $this->em->remove($this->findSource($id));
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function findSource(string $id): AssetNewsSource
    {
        try {
            $source = $this->sources->find(Uuid::fromString($id));
        } catch (\Throwable) {
            throw new NotFoundHttpException();
        }
        if ($source === null) {
            throw new NotFoundHttpException();
        }
        return $source;
    }

    /** @return array<string, mixed> */
    private function serializeSource(AssetNewsSource $s): array
    {
        return [
            'id' => $s->getId()->toRfc4122(),
            'url' => $s->getUrl(),
            'type' => $s->getType(),
            'scrapeMode' => $s->getScrapeMode(),
            'feedUrl' => $s->getFeedUrl(),
            'label' => $s->getLabel(),
            'enabled' => $s->isEnabled(),
            'aiEnabled' => $s->isAiEnabled(),
            'parser' => $this->parsers->resolve($s)->name(),
            'lastStatus' => $s->getLastStatus(),
            'lastFetchedAt' => $s->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function urlFromBody(Request $request): ?string
    {
        $url = $this->jsonBody($request)['url'] ?? null;
        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        return is_array($body) ? $body : [];
    }

    /** @return string[] */
    private function sourceKeys(): array
    {
        $keys = [];
        foreach ($this->providers as $provider) {
            $keys[] = $provider->source();
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }
}
