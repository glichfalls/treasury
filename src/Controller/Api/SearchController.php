<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Global search across the user's data. One endpoint, four groups
 * (accounts / transactions / assets / recurring), capped per group.
 *
 * Implemented via DBAL with LIKE + LOWER() because the dataset is small
 * (personal app, single user) — a single user has thousands of rows at most,
 * not millions. If this ever grows, swap for a real full-text index.
 */
class SearchController extends AbstractController
{
    private const PER_GROUP_LIMIT = 6;
    private const MIN_QUERY_LENGTH = 2;

    public function __construct(private readonly Connection $conn) {}

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse([
                'accounts' => [], 'transactions' => [], 'assets' => [], 'recurring' => [],
            ]);
        }

        $like = '%' . strtolower($q) . '%';
        $isinCandidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        $ownerBin = $user->getId()->toBinary();

        return new JsonResponse([
            'accounts'     => $this->searchAccounts($ownerBin, $like),
            'transactions' => $this->searchTransactions($ownerBin, $like, $isinCandidate),
            'assets'       => $this->searchAssets($ownerBin, $like, $isinCandidate),
            'recurring'    => $this->searchRecurring($ownerBin, $like),
        ]);
    }

    private function searchAccounts(string $ownerBin, string $like): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, name, institution, type, currency
             FROM accounts
             WHERE owner_id = :owner
               AND (LOWER(name) LIKE :q OR LOWER(institution) LIKE :q)
             ORDER BY name ASC
             LIMIT ' . self::PER_GROUP_LIMIT,
            ['owner' => $ownerBin, 'q' => $like],
        );
        return array_map(fn($r) => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'name' => $r['name'],
            'institution' => $r['institution'],
            'type' => $r['type'],
            'currency' => $r['currency'],
        ], $rows);
    }

    private function searchTransactions(string $ownerBin, string $like, string $isinCandidate): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.occurred_at, t.amount_minor, t.currency, t.description,
                    t.type, t.category, t.asset_isin,
                    t.account_id, ac.name AS account_name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner
               AND (LOWER(t.description) LIKE :q OR t.asset_isin = :isin)
             ORDER BY t.occurred_at DESC
             LIMIT ' . self::PER_GROUP_LIMIT,
            ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
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

    private function searchAssets(string $ownerBin, string $like, string $isinCandidate): array
    {
        // Restrict to assets the user actually has touched (via transactions),
        // so we don't surface the whole catalog including stuff they never held.
        $rows = $this->conn->fetchAllAssociative(
            'SELECT DISTINCT a.isin, a.ticker, a.name, a.currency
             FROM assets a
             INNER JOIN transactions t ON t.asset_isin = a.isin
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner
               AND (LOWER(a.name) LIKE :q OR LOWER(a.ticker) LIKE :q OR a.isin = :isin)
             ORDER BY a.ticker IS NULL, a.ticker ASC
             LIMIT ' . self::PER_GROUP_LIMIT,
            ['owner' => $ownerBin, 'q' => $like, 'isin' => $isinCandidate],
        );
        return array_map(fn($r) => [
            'isin' => $r['isin'],
            'ticker' => $r['ticker'],
            'name' => $r['name'],
            'currency' => $r['currency'],
        ], $rows);
    }

    private function searchRecurring(string $ownerBin, string $like): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT r.id, r.description, r.amount_minor, r.currency, r.frequency, r.active,
                    r.account_id, ac.name AS account_name
             FROM recurring_transactions r
             INNER JOIN accounts ac ON ac.id = r.account_id
             WHERE ac.owner_id = :owner
               AND LOWER(r.description) LIKE :q
             ORDER BY r.active DESC, r.description ASC
             LIMIT ' . self::PER_GROUP_LIMIT,
            ['owner' => $ownerBin, 'q' => $like],
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
}
