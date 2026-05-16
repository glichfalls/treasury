<?php

namespace App\Controller\Api;

use App\Entity\TransactionType;
use App\Entity\User;
use App\Fx\FxConverter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Global search across the user's data. Five groups (accounts / transactions /
 * assets / recurring / tags), capped per group via `limit` (default 6 for the
 * header dropdown; up to 100 for the full /search page).
 *
 * Optional filters narrow the transaction universe used to compute every
 * group: `accountId`, `dateFrom`, `dateTo`, `type`. Filters that don't apply
 * to a given group (e.g. dateFrom on the accounts list) are simply ignored
 * for that group.
 */
class SearchController extends AbstractController
{
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 100;
    private const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private readonly Connection $conn,
        private readonly FxConverter $fx,
    ) {}

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));

        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse([
                'accounts' => [], 'transactions' => [], 'assets' => [], 'recurring' => [], 'tags' => [],
            ]);
        }

        $like = '%' . strtolower($q) . '%';
        $isinCandidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        $ownerBin = $user->getId()->toBinary();
        $filters = $this->parseFilters($request);

        return new JsonResponse([
            'accounts'     => $this->searchAccounts($ownerBin, $like, $limit, $filters),
            'transactions' => $this->searchTransactions($ownerBin, $like, $isinCandidate, $limit, $filters),
            'assets'       => $this->searchAssets($ownerBin, $like, $isinCandidate, $limit, $filters),
            'recurring'    => $this->searchRecurring($ownerBin, $like, $limit, $filters),
            'tags'         => $this->searchTags($ownerBin, strtolower($q), $limit, $filters),
        ]);
    }

    /**
     * Paginated, sortable transaction list for the search-results view.
     *
     * The unified /api/search endpoint also returns transactions, but capped
     * at `limit` for the header dropdown. This endpoint exists so the search
     * results page can show the full set with proper pagination.
     */
    #[Route('/api/search/transactions', name: 'api_search_transactions', methods: ['GET'])]
    public function transactions(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse(['items' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25]);
        }

        $like = '%' . strtolower($q) . '%';
        $isinCandidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        $ownerBin = $user->getId()->toBinary();
        $filters = $this->parseFilters($request);
        $filterSql = $this->txFilterSql($filters);

        $params = array_merge(
            ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
            $filterSql['params'],
        );
        $whereSql =
            'WHERE ac.owner_id = :owner
               AND (LOWER(t.description) LIKE :q OR t.asset_isin = :isin)' . $filterSql['sql'];

        $totalRow = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS c
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow['c'] ?? 0);

        // Sort column → DB column whitelist. Frontend sends e.g. "occurredAt:desc".
        [$col, $dir] = array_pad(explode(':', (string) $request->query->get('sort', ''), 2), 2, '');
        $sortMap = [
            'occurredAt' => 't.occurred_at',
            'amount' => 't.amount_minor',
            'type' => 't.type',
            'description' => 't.description',
        ];
        $sortField = $sortMap[$col] ?? 't.occurred_at';
        $sortDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = max(1, min(200, (int) $request->query->get('pageSize', '25')));
        $offset = ($page - 1) * $pageSize;

        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.occurred_at, t.amount_minor, t.currency, t.description,
                    t.type, t.category, t.asset_isin,
                    t.account_id, ac.name AS account_name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             ' . $whereSql . '
             ORDER BY ' . $sortField . ' ' . $sortDir . ', t.id DESC
             LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $params,
        );

        return new JsonResponse([
            'items' => array_map(fn($r) => [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'accountId' => Uuid::fromBinary($r['account_id'])->toRfc4122(),
                'accountName' => $r['account_name'],
                'occurredAt' => $r['occurred_at'],
                'amountMinor' => $r['amount_minor'],
                'currency' => $r['currency'],
                'description' => $r['description'],
                'type' => $r['type'],
                'category' => $r['category'],
                'assetIsin' => $r['asset_isin'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * Spending stats for transactions matching the query and filters. Amounts
     * are converted to the user's base currency at each transaction's
     * historical FX rate so the totals are honest across mixed currencies.
     */
    #[Route('/api/search/stats', name: 'api_search_stats', methods: ['GET'])]
    public function stats(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $baseCurrency = $user->getBaseCurrency();

        $empty = [
            'query' => $q,
            'baseCurrency' => $baseCurrency,
            'count' => 0,
            'totalSignedMinor' => '0',
            'totalSpentMinor' => '0',
            'totalIncomeMinor' => '0',
            'monthly' => [],
        ];
        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse($empty);
        }

        $like = '%' . strtolower($q) . '%';
        $isinCandidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        $ownerBin = $user->getId()->toBinary();
        $filters = $this->parseFilters($request);

        $filterSql = $this->txFilterSql($filters);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.occurred_at, t.amount_minor, t.currency
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner
               AND (LOWER(t.description) LIKE :q
                    OR t.asset_isin = :isin
                    OR (t.tags IS NOT NULL AND JSON_SEARCH(t.tags, 'one', :q) IS NOT NULL))"
             . $filterSql['sql'],
            array_merge(
                ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
                $filterSql['params'],
            ),
        );

        $totalSigned = 0;
        $totalSpent = 0;
        $totalIncome = 0;
        $byMonth = [];

        foreach ($rows as $r) {
            $base = $this->convertToBase(
                (int) $r['amount_minor'],
                (string) $r['currency'],
                (string) $r['occurred_at'],
                $baseCurrency,
            );
            $totalSigned += $base;
            if ($base >= 0) $totalIncome += $base;
            else $totalSpent += $base;

            $month = substr((string) $r['occurred_at'], 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $base;
        }

        ksort($byMonth);
        $monthly = [];
        foreach ($byMonth as $m => $sum) {
            $monthly[] = ['month' => $m, 'amountMinor' => (string) $sum];
        }

        return new JsonResponse([
            'query' => $q,
            'baseCurrency' => $baseCurrency,
            'count' => count($rows),
            'totalSignedMinor' => (string) $totalSigned,
            'totalSpentMinor' => (string) $totalSpent,
            'totalIncomeMinor' => (string) $totalIncome,
            'monthly' => $monthly,
        ]);
    }

    /**
     * Validates the user-supplied filter values into a normalised array.
     * Bad values silently fall back to null rather than 4xx — filters are a
     * UI affordance, not a contract.
     *
     * @return array{accountIdBin: ?string, dateFrom: ?string, dateTo: ?string, type: ?string}
     */
    private function parseFilters(Request $request): array
    {
        $accountId = trim((string) $request->query->get('accountId', ''));
        $accountIdBin = null;
        if ($accountId !== '' && Uuid::isValid($accountId)) {
            $accountIdBin = Uuid::fromString($accountId)->toBinary();
        }

        $dateFrom = trim((string) $request->query->get('dateFrom', ''));
        $dateTo = trim((string) $request->query->get('dateTo', ''));
        // Accept yyyy-mm-dd only — anything else is dropped.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

        $typeRaw = trim((string) $request->query->get('type', ''));
        $type = null;
        if ($typeRaw !== '' && TransactionType::tryFrom($typeRaw) !== null) {
            $type = $typeRaw;
        }

        return [
            'accountIdBin' => $accountIdBin,
            'dateFrom' => $dateFrom !== '' ? $dateFrom : null,
            'dateTo' => $dateTo !== '' ? $dateTo : null,
            'type' => $type,
        ];
    }

    /**
     * Builds the filter SQL + bound params for queries against the
     * `transactions t` table. Returns an empty fragment when no filters apply.
     *
     * @param array{accountIdBin: ?string, dateFrom: ?string, dateTo: ?string, type: ?string} $filters
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function txFilterSql(array $filters): array
    {
        $clauses = [];
        $params = [];
        if ($filters['accountIdBin'] !== null) {
            $clauses[] = 't.account_id = :fAccount';
            $params['fAccount'] = $filters['accountIdBin'];
        }
        if ($filters['dateFrom'] !== null) {
            $clauses[] = 't.occurred_at >= :fDateFrom';
            $params['fDateFrom'] = $filters['dateFrom'];
        }
        if ($filters['dateTo'] !== null) {
            $clauses[] = 't.occurred_at <= :fDateTo';
            $params['fDateTo'] = $filters['dateTo'];
        }
        if ($filters['type'] !== null) {
            $clauses[] = 't.type = :fType';
            $params['fType'] = $filters['type'];
        }
        return [
            'sql' => $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    private function searchAccounts(string $ownerBin, string $like, int $limit, array $filters): array
    {
        // Only accountId applies. Date/type are transaction-specific.
        $extraSql = '';
        $extraParams = [];
        if ($filters['accountIdBin'] !== null) {
            $extraSql = ' AND id = :fAccount';
            $extraParams['fAccount'] = $filters['accountIdBin'];
        }
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, name, institution, type, currency
             FROM accounts
             WHERE owner_id = :owner
               AND (LOWER(name) LIKE :q OR LOWER(institution) LIKE :q)' . $extraSql . '
             ORDER BY name ASC
             LIMIT ' . $limit,
            array_merge(['owner' => $ownerBin, 'q' => $like], $extraParams),
        );
        return array_map(fn($r) => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'name' => $r['name'],
            'institution' => $r['institution'],
            'type' => $r['type'],
            'currency' => $r['currency'],
        ], $rows);
    }

    private function searchTransactions(string $ownerBin, string $like, string $isinCandidate, int $limit, array $filters): array
    {
        $filterSql = $this->txFilterSql($filters);
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.occurred_at, t.amount_minor, t.currency, t.description,
                    t.type, t.category, t.asset_isin,
                    t.account_id, ac.name AS account_name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner
               AND (LOWER(t.description) LIKE :q OR t.asset_isin = :isin)' . $filterSql['sql'] . '
             ORDER BY t.occurred_at DESC
             LIMIT ' . $limit,
            array_merge(
                ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
                $filterSql['params'],
            ),
        );
        return array_map(fn($r) => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'accountId' => Uuid::fromBinary($r['account_id'])->toRfc4122(),
            'accountName' => $r['account_name'],
            'occurredAt' => $r['occurred_at'],
            'amountMinor' => $r['amount_minor'],
            'currency' => $r['currency'],
            'description' => $r['description'],
            'type' => $r['type'],
            'category' => $r['category'],
            'assetIsin' => $r['asset_isin'],
        ], $rows);
    }

    private function searchAssets(string $ownerBin, string $like, string $isinCandidate, int $limit, array $filters): array
    {
        // Asset list is derived from transactions, so all tx filters narrow it.
        $filterSql = $this->txFilterSql($filters);
        $rows = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT a.isin, a.ticker, a.name, a.currency
             FROM assets a
             INNER JOIN transactions t ON t.asset_isin = a.isin
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner
               AND (LOWER(a.name) LIKE :q OR LOWER(a.ticker) LIKE :q OR a.isin = :isin)' . $filterSql['sql'] . '
             ORDER BY a.ticker IS NULL, a.ticker ASC
             LIMIT ' . $limit,
            array_merge(
                ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
                $filterSql['params'],
            ),
        );
        return array_map(fn($r) => [
            'isin' => $r['isin'],
            'ticker' => $r['ticker'],
            'name' => $r['name'],
            'currency' => $r['currency'],
        ], $rows);
    }

    private function searchRecurring(string $ownerBin, string $like, int $limit, array $filters): array
    {
        // accountId + type apply; date doesn't (recurring rules aren't dated).
        $extra = [];
        $extraParams = [];
        if ($filters['accountIdBin'] !== null) {
            $extra[] = 'r.account_id = :fAccount';
            $extraParams['fAccount'] = $filters['accountIdBin'];
        }
        if ($filters['type'] !== null) {
            $extra[] = 'r.type = :fType';
            $extraParams['fType'] = $filters['type'];
        }
        $extraSql = $extra === [] ? '' : ' AND ' . implode(' AND ', $extra);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT r.id, r.description, r.amount_minor, r.currency, r.frequency, r.active,
                    r.account_id, ac.name AS account_name
             FROM recurring_transactions r
             INNER JOIN accounts ac ON ac.id = r.account_id
             WHERE ac.owner_id = :owner
               AND LOWER(r.description) LIKE :q' . $extraSql . '
             ORDER BY r.active DESC, r.description ASC
             LIMIT ' . $limit,
            array_merge(['owner' => $ownerBin, 'q' => $like], $extraParams),
        );
        return array_map(fn($r) => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'accountId' => Uuid::fromBinary($r['account_id'])->toRfc4122(),
            'accountName' => $r['account_name'],
            'description' => $r['description'],
            'amountMinor' => $r['amount_minor'],
            'currency' => $r['currency'],
            'frequency' => $r['frequency'],
            'active' => (bool) $r['active'],
        ], $rows);
    }

    /**
     * Tags whose name contains the query, restricted to transactions that
     * match the active filters. Counts are based on filtered transactions —
     * so "groceries" with a 2025-only date filter shows tag counts from
     * 2025 only.
     */
    private function searchTags(string $ownerBin, string $needle, int $limit, array $filters): array
    {
        if ($needle === '') return [];

        $filterSql = $this->txFilterSql($filters);
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.tags FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND JSON_LENGTH(t.tags) > 0' . $filterSql['sql'],
            array_merge(['owner' => $ownerBin], $filterSql['params']),
        );
        $counts = [];
        foreach ($rows as $r) {
            $decoded = json_decode($r['tags'] ?? '[]', true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $tag) {
                if (!is_string($tag) || $tag === '') continue;
                $tag = strtolower($tag);
                if (!str_contains($tag, $needle)) continue;
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counts as $tag => $count) {
            $out[] = ['tag' => $tag, 'count' => $count];
        }
        usort($out, fn($a, $b) => $b['count'] - $a['count'] ?: strcmp($a['tag'], $b['tag']));
        return array_slice($out, 0, $limit);
    }

    private function convertToBase(int $amountMinor, string $currency, string $date, string $base): int
    {
        if (strtoupper($currency) === strtoupper($base)) return $amountMinor;
        return $this->fx->convertMinor($amountMinor, $currency, $base, new \DateTimeImmutable($date)) ?? $amountMinor;
    }
}
