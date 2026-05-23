<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\AssetRepository;
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
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Paginated, filterable feed of news for the user's (un-muted) holdings,
     * newest first. Returns sentiment counts for the whole filtered set so the
     * UI can render Bullish / Bearish / Neutral grouping without a second call.
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

        [$where, $params, $types] = $this->buildFilter($request, $held);

        $counts = $this->sentimentCounts($where, $params, $types);
        $total = array_sum($counts);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.id, n.source, n.kind, n.title, n.url, n.publisher, n.summary, n.snippet,
                    n.sentiment, n.published_at, a.isin, a.ticker, a.name AS asset_name
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE {$where}
             ORDER BY n.published_at DESC, n.id DESC
             LIMIT {$pageSize} OFFSET " . (($page - 1) * $pageSize),
            $params,
            $types,
        );

        return new JsonResponse([
            'items' => array_map([$this, 'serializeItem'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'counts' => $counts,
            'sources' => $this->distinctSources($held),
        ]);
    }

    /**
     * Compact payload for the dashboard widget: the few latest items plus the
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

        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.id, n.source, n.kind, n.title, n.url, n.publisher, n.summary, n.snippet,
                    n.sentiment, n.published_at, a.isin, a.ticker, a.name AS asset_name
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             WHERE {$where}
             ORDER BY n.published_at DESC, n.id DESC
             LIMIT 6",
            $params,
            $types,
        );

        return new JsonResponse([
            'items' => array_map([$this, 'serializeItem'], $rows),
            'counts' => $this->sentimentCounts($where, $params, $types),
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
        $this->em->flush();

        return new JsonResponse([
            'isin' => $asset->getIsin(),
            'newsEnabled' => $asset->isNewsEnabled(),
            'newsMarketTopic' => $asset->getNewsMarketTopic(),
        ]);
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
        // Sentiment is null until the AI classifier runs, so 'unclassified' maps
        // to IS NULL rather than an equality match.
        $sentiment = trim((string) $request->query->get('sentiment', ''));
        if ($sentiment === 'unclassified') {
            $where .= ' AND n.sentiment IS NULL';
        } elseif ($sentiment !== '') {
            $where .= ' AND n.sentiment = :sentiment';
            $params['sentiment'] = $sentiment;
        }
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $where .= ' AND n.title LIKE :q';
            $params['q'] = '%' . $q . '%';
        }

        return [$where, $params, $types];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @return array{bullish: int, bearish: int, neutral: int, unclassified: int}
     */
    private function sentimentCounts(string $where, array $params, array $types): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.sentiment, COUNT(*) AS c
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

    /** @param array<string, mixed> $r */
    private function serializeItem(array $r): array
    {
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
            'asset' => [
                'isin' => $r['isin'],
                'ticker' => $r['ticker'],
                'name' => $r['asset_name'],
            ],
        ];
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
