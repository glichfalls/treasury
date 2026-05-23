<?php

namespace App\Controller\Api;

use App\Entity\NewsItem;
use App\Entity\User;
use App\News\DigestService;
use App\News\Sentiment\NewsClassificationService;
use App\Repository\AssetRepository;
use App\Repository\NewsItemRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api/news')]
class NewsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly AssetRepository $assets,
        private readonly NewsItemRepository $newsItems,
        private readonly NewsClassificationService $classifier,
        private readonly DigestService $digestService,
        private readonly EntityManagerInterface $em,
    ) {}

    /** The latest 24h AI briefing for the user's holdings, or null if none yet. */
    #[Route('/digest', name: 'api_news_digest', methods: ['GET'])]
    public function digest(#[CurrentUser] User $user): JsonResponse
    {
        $digest = $this->digestService->latest($user);
        if ($digest === null) {
            return new JsonResponse(null, 204); // no briefing yet
        }
        return new JsonResponse([
            'id' => $digest->getId()->toRfc4122(),
            'content' => $digest->getContent(),
            'generatedAt' => $digest->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'periodStart' => $digest->getPeriodStart()->format(\DateTimeInterface::ATOM),
            'periodEnd' => $digest->getPeriodEnd()->format(\DateTimeInterface::ATOM),
            'itemCount' => $digest->getItemCount(),
        ]);
    }

    /**
     * Paginated, filterable feed of news for the user's (un-muted) holdings,
     * newest first. Articles fetched against multiple holdings (same URL, different
     * tickers) are collapsed into a single entry with every relevant ticker listed
     * under `assets`. Tab counts ignore the active sentiment filter so they stay
     * accurate when switching tabs.
     */
    #[Route('', name: 'api_news_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = max(1, min(100, (int) $request->query->get('pageSize', '30')));

        $held = $this->heldIsins($user);
        if ($held === []) {
            return new JsonResponse($this->emptyFeed($page, $pageSize));
        }

        // Base WHERE excludes sentiment so the per-sentiment counts stay correct
        // when a sentiment tab is active.
        [$where, $params, $types] = $this->buildFilter($request, $held);

        $counts = $this->sentimentCounts($where, $params, $types);

        $sentiment = trim((string) $request->query->get('sentiment', ''));
        $listWhere = $where;
        $listParams = $params;
        $listTypes = $types;
        if ($sentiment === 'unclassified') {
            $listWhere .= ' AND n.sentiment IS NULL';
        } elseif ($sentiment !== '') {
            $listWhere .= ' AND n.sentiment = :sentiment';
            $listParams['sentiment'] = $sentiment;
        }

        $total = match (true) {
            $sentiment === 'unclassified' => $counts['unclassified'],
            in_array($sentiment, ['bullish', 'bearish', 'neutral'], true) => $counts[$sentiment],
            default => array_sum($counts),
        };

        $items = $this->fetchGroupedArticles(
            $listWhere,
            $listParams,
            $listTypes,
            $held,
            limit: $pageSize,
            offset: ($page - 1) * $pageSize,
        );

        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'counts' => $counts,
            'sources' => $this->distinctSources($held),
        ]);
    }

    /**
     * Compact payload for the dashboard widget: the few latest articles plus the
     * overall sentiment tilt across the user's holdings.
     */
    #[Route('/dashboard', name: 'api_news_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user): JsonResponse
    {
        $held = $this->heldIsins($user);
        if ($held === []) {
            return new JsonResponse(['items' => [], 'counts' => $this->zeroCounts()]);
        }

        $where = 'a.isin IN (:isins) AND a.news_enabled = 1';
        $params = ['isins' => $held];
        $types = ['isins' => ArrayParameterType::STRING];

        $items = $this->fetchGroupedArticles($where, $params, $types, $held, limit: 6, offset: 0);

        return new JsonResponse([
            'items' => $items,
            'counts' => $this->sentimentCounts($where, $params, $types),
        ]);
    }

    /**
     * Single-article detail. Returns the article with every held asset it touches,
     * and lazy-classifies on first read so AI summaries are produced only when an
     * article is actually opened — not for every fetched item.
     */
    #[Route('/{id}', name: 'api_news_get', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\Throwable) {
            throw new NotFoundHttpException();
        }

        $item = $this->newsItems->find($uuid);
        if ($item === null) {
            throw new NotFoundHttpException();
        }

        $held = $this->heldIsins($user);
        if (!in_array($item->getAsset()->getIsin(), $held, true)) {
            throw new NotFoundHttpException();
        }

        // Lazy classify: if the article hasn't been processed yet, do it now while
        // the user is reading. Failures fall back silently to the existing snippet.
        if ($item->getSummary() === null) {
            try {
                $this->classifier->classifyOne($item);
            } catch (\Throwable) {
                // Swallow — the detail view still works without a summary.
            }
        }

        // Pull every held + enabled asset that shares this article (same content
        // hash), so the UI can show "AAPL · NVDA · MSFT" on one card.
        $relatedRows = $this->conn->fetchAllAssociative(
            'SELECT a.isin, a.ticker, a.name AS asset_name
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE n.content_hash = :hash AND a.isin IN (:isins) AND a.news_enabled = 1
             ORDER BY a.ticker IS NULL, a.ticker, a.name',
            ['hash' => $item->getContentHash(), 'isins' => $held],
            ['isins' => ArrayParameterType::STRING],
        );

        return new JsonResponse([
            'id' => $item->getId()->toRfc4122(),
            'source' => $item->getSource(),
            'kind' => $item->getKind(),
            'title' => $item->getTitle(),
            'url' => $item->getUrl(),
            'publisher' => $item->getPublisher(),
            'summary' => $item->getSummary(),
            'brief' => $item->getBrief(),
            'snippet' => $item->getSnippet(),
            'sentiment' => $item->getSentiment(),
            'publishedAt' => $item->getPublishedAt()->format(\DateTimeInterface::ATOM),
            'assets' => array_map(fn($r) => [
                'isin' => $r['isin'],
                'ticker' => $r['ticker'],
                'name' => $r['asset_name'],
            ], $relatedRows),
        ]);
    }

    /**
     * Mute/un-mute news for a single holding, or override the market topic used
     * to search news for an ETF. The per-item disable the feature calls for.
     */
    #[Route('/assets/{isin}/preferences', name: 'api_news_asset_prefs', methods: ['PATCH'])]
    public function updateAssetPreferences(string $isin, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $asset = $this->assets->findByIsin($isin);
        if ($asset === null || !in_array(strtoupper($isin), $this->heldIsins($user), true)) {
            throw new NotFoundHttpException();
        }

        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        if (array_key_exists('enabled', $body)) {
            $asset->setNewsEnabled((bool) $body['enabled']);
        }
        if (array_key_exists('marketTopic', $body)) {
            $topic = $body['marketTopic'];
            $asset->setNewsMarketTopic(is_string($topic) && trim($topic) !== '' ? trim($topic) : null);
        }
        if (array_key_exists('redditSubreddit', $body)) {
            $sub = $body['redditSubreddit'];
            $asset->setRedditSubreddit(is_string($sub) ? $sub : null);
        }
        $this->em->flush();

        return new JsonResponse([
            'isin' => $asset->getIsin(),
            'newsEnabled' => $asset->isNewsEnabled(),
            'newsMarketTopic' => $asset->getNewsMarketTopic(),
            'redditSubreddit' => $asset->getRedditSubreddit(),
        ]);
    }

    /**
     * Fetch articles grouped by content_hash: one canonical row per article, with
     * its full asset list inlined. The canonical row is the oldest news_item per
     * group (UUIDv7 → MIN(id) is time-ordered). Two queries: paginate the groups,
     * then load asset metadata for the page's hashes.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @param string[] $held
     * @return list<array<string, mixed>>
     */
    private function fetchGroupedArticles(string $where, array $params, array $types, array $held, int $limit, int $offset): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id, source, kind, title, url, publisher, summary, snippet,
                    sentiment, published_at, content_hash
             FROM (
                 SELECT n.id, n.source, n.kind, n.title, n.url, n.publisher, n.summary,
                        n.snippet, n.sentiment, n.published_at, n.content_hash,
                        ROW_NUMBER() OVER (PARTITION BY n.content_hash ORDER BY n.id ASC) AS rn,
                        MAX(n.published_at) OVER (PARTITION BY n.content_hash) AS group_published_at
                 FROM news_items n
                 INNER JOIN assets a ON a.id = n.asset_id
                 WHERE {$where}
             ) ranked
             WHERE rn = 1
             ORDER BY group_published_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
            $types,
        );

        if ($rows === []) {
            return [];
        }

        // Second pass: resolve all held assets that share each hash.
        $hashes = array_column($rows, 'content_hash');
        $assetRows = $this->conn->fetchAllAssociative(
            'SELECT n.content_hash, a.isin, a.ticker, a.name AS asset_name
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE n.content_hash IN (:hashes) AND a.isin IN (:isins) AND a.news_enabled = 1',
            ['hashes' => $hashes, 'isins' => $held],
            ['hashes' => ArrayParameterType::STRING, 'isins' => ArrayParameterType::STRING],
        );

        $assetsByHash = [];
        foreach ($assetRows as $r) {
            $assetsByHash[$r['content_hash']][] = [
                'isin' => $r['isin'],
                'ticker' => $r['ticker'],
                'name' => $r['asset_name'],
            ];
        }
        // Stable per-hash ordering so the UI doesn't shuffle assets on refresh.
        foreach ($assetsByHash as &$assets) {
            usort($assets, fn($a, $b) => strcmp(
                (string) ($a['ticker'] ?? $a['isin']),
                (string) ($b['ticker'] ?? $b['isin']),
            ));
        }
        unset($assets);

        return array_map(function (array $r) use ($assetsByHash): array {
            return [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'source' => $r['source'],
                'kind' => $r['kind'],
                'title' => $r['title'],
                'url' => $r['url'],
                'publisher' => $r['publisher'],
                'summary' => $r['summary'],
                'snippet' => $r['snippet'],
                'sentiment' => $r['sentiment'],
                'publishedAt' => (new \DateTimeImmutable($r['published_at']))->format(\DateTimeInterface::ATOM),
                'assets' => $assetsByHash[$r['content_hash']] ?? [],
            ];
        }, $rows);
    }

    /**
     * Build the shared WHERE clause + bound params from the request filters.
     *
     * @param string[] $held
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildFilter(Request $request, array $held): array
    {
        $where = 'a.isin IN (:isins) AND a.news_enabled = 1';
        $params = ['isins' => $held];
        $types = ['isins' => ArrayParameterType::STRING];

        $isin = strtoupper(trim((string) $request->query->get('isin', '')));
        if ($isin !== '' && in_array($isin, $held, true)) {
            $where .= ' AND a.isin = :isin';
            $params['isin'] = $isin;
        }
        foreach (['source', 'kind'] as $field) {
            $val = trim((string) $request->query->get($field, ''));
            if ($val !== '') {
                $where .= " AND n.{$field} = :{$field}";
                $params[$field] = $val;
            }
        }
        // Sentiment is applied by the caller (list endpoint) so the counts query
        // can use this base WHERE and stay accurate across tab selections.
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $where .= ' AND n.title LIKE :q';
            $params['q'] = '%' . $q . '%';
        }

        return [$where, $params, $types];
    }

    /**
     * Distinct-article counts per sentiment. Counts content hashes (not rows) so
     * articles that touch multiple held assets only count once.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @return array{bullish: int, bearish: int, neutral: int, unclassified: int}
     */
    private function sentimentCounts(string $where, array $params, array $types): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.sentiment, COUNT(DISTINCT n.content_hash) AS c
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE {$where}
             GROUP BY n.sentiment",
            $params,
            $types,
        );
        $counts = $this->zeroCounts();
        foreach ($rows as $r) {
            $key = $r['sentiment'] ?? 'unclassified';
            if (!array_key_exists($key, $counts)) {
                $key = 'unclassified';
            }
            $counts[$key] += (int) $r['c'];
        }
        return $counts;
    }

    /** @param string[] $held */
    private function distinctSources(array $held): array
    {
        return $this->conn->fetchFirstColumn(
            'SELECT DISTINCT n.source
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE a.isin IN (?) AND a.news_enabled = 1
             ORDER BY n.source',
            [$held],
            [ArrayParameterType::STRING],
        );
    }

    /** @return string[] ISINs the user has any transaction for. */
    private function heldIsins(User $user): array
    {
        return $this->conn->fetchFirstColumn(
            'SELECT DISTINCT t.asset_isin
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = ? AND t.asset_isin IS NOT NULL',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }

    private function emptyFeed(int $page, int $pageSize): array
    {
        return [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
            'counts' => $this->zeroCounts(),
            'sources' => [],
        ];
    }

    /** @return array{bullish: int, bearish: int, neutral: int, unclassified: int} */
    private function zeroCounts(): array
    {
        return ['bullish' => 0, 'bearish' => 0, 'neutral' => 0, 'unclassified' => 0];
    }
}
