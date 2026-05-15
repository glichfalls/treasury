<?php

namespace App\Controller\Api;

use App\Entity\Transaction;
use App\Entity\User;
use App\Fx\FxConverter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

class TagsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly EntityManagerInterface $em,
        private readonly FxConverter $fx,
    ) {}

    /**
     * Per-tag stats across all the user's transactions: count + signed total in
     * the user's base currency, converted at each transaction's historical FX.
     * Sorted by total absolute value desc so the highest-impact tags lead.
     */
    #[Route('/api/tags', name: 'api_tags_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $baseCurrency = $user->getBaseCurrency();
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.occurred_at, t.amount_minor, t.currency, t.tags
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND JSON_LENGTH(t.tags) > 0',
            ['owner' => $user->getId()->toBinary()],
        );

        $stats = [];
        foreach ($rows as $r) {
            $tags = json_decode($r['tags'] ?? '[]', true);
            if (!is_array($tags)) continue;
            $base = $this->convertToBase(
                (int) $r['amount_minor'],
                (string) $r['currency'],
                (string) $r['occurred_at'],
                $baseCurrency,
            );
            foreach ($tags as $tag) {
                if (!is_string($tag) || $tag === '') continue;
                $tag = strtolower($tag);
                if (!isset($stats[$tag])) {
                    $stats[$tag] = ['count' => 0, 'total' => 0];
                }
                $stats[$tag]['count']++;
                $stats[$tag]['total'] += $base;
            }
        }

        $out = [];
        foreach ($stats as $tag => $s) {
            $out[] = [
                'tag' => $tag,
                'count' => $s['count'],
                'totalMinor' => (string) $s['total'],
            ];
        }
        usort($out, function ($a, $b) {
            $diff = abs((int) $b['totalMinor']) - abs((int) $a['totalMinor']);
            return $diff !== 0 ? $diff : strcmp($a['tag'], $b['tag']);
        });

        return new JsonResponse([
            'baseCurrency' => $baseCurrency,
            'tags' => $out,
        ]);
    }

    /**
     * Detail view for a single tag: total + count, monthly time series, full
     * transaction list. Totals are in the user's base currency using historical
     * FX; raw native amounts are returned alongside.
     */
    #[Route('/api/tags/{tag}', name: 'api_tags_detail', methods: ['GET'], requirements: ['tag' => '[^/]+'])]
    public function detail(string $tag, #[CurrentUser] User $user): JsonResponse
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            return new JsonResponse(['error' => 'Tag is required'], 422);
        }
        $baseCurrency = $user->getBaseCurrency();
        $ownerBin = $user->getId()->toBinary();

        // JSON_CONTAINS(tags, '"netflix"') — second arg must be a JSON value.
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.occurred_at, t.amount_minor, t.currency, t.description,
                    t.type, t.category, t.tags,
                    t.account_id, ac.name AS account_name
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner AND JSON_CONTAINS(t.tags, :tag)
             ORDER BY t.occurred_at DESC, t.id DESC",
            ['owner' => $ownerBin, 'tag' => json_encode($tag)],
        );

        $totalSigned = 0;
        $totalSpent = 0;
        $totalIncome = 0;
        $byMonth = [];
        $transactions = [];

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

            $month = substr($r['occurred_at'], 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $base;

            $transactions[] = [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'accountId' => Uuid::fromBinary($r['account_id'])->toRfc4122(),
                'accountName' => $r['account_name'],
                'occurredAt' => $r['occurred_at'],
                'amountMinor' => $r['amount_minor'],
                'currency' => $r['currency'],
                'amountBaseMinor' => (string) $base,
                'description' => $r['description'],
                'type' => $r['type'],
                'category' => $r['category'],
                'tags' => json_decode($r['tags'] ?? '[]', true) ?: [],
            ];
        }

        ksort($byMonth);
        $monthly = [];
        foreach ($byMonth as $m => $sum) {
            $monthly[] = ['month' => $m, 'amountMinor' => (string) $sum];
        }

        return new JsonResponse([
            'tag' => $tag,
            'baseCurrency' => $baseCurrency,
            'count' => count($transactions),
            'totalSignedMinor' => (string) $totalSigned,
            'totalSpentMinor' => (string) $totalSpent,
            'totalIncomeMinor' => (string) $totalIncome,
            'monthly' => $monthly,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Walks every transaction the user owns and applies any of the user's
     * existing tags whose name appears as a substring of the description.
     * Idempotent — only adds, never removes.
     */
    #[Route('/api/tags/retag', name: 'api_tags_retag', methods: ['POST'])]
    public function retagAll(#[CurrentUser] User $user): JsonResponse
    {
        $ownerBin = $user->getId()->toBinary();

        $tagRows = $this->conn->fetchAllAssociative(
            'SELECT t.tags FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND JSON_LENGTH(t.tags) > 0',
            ['owner' => $ownerBin],
        );
        $corpus = [];
        foreach ($tagRows as $r) {
            $decoded = json_decode($r['tags'] ?? '[]', true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $t) {
                if (is_string($t) && $t !== '') $corpus[strtolower($t)] = true;
            }
        }
        if ($corpus === []) {
            return new JsonResponse(['tagged' => 0, 'examined' => 0, 'newTagInstances' => 0]);
        }
        $tagList = array_keys($corpus);

        $txs = $this->em->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->innerJoin('t.account', 'a')
            ->andWhere('a.owner = :owner')
            ->andWhere('t.description IS NOT NULL')
            ->setParameter('owner', $user->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        $touched = 0;
        $added = 0;
        foreach ($txs as $tx) {
            $haystack = strtolower((string) $tx->getDescription());
            $existing = $tx->getTags();
            $next = $existing;
            foreach ($tagList as $tag) {
                if (in_array($tag, $next, true)) continue;
                if (str_contains($haystack, $tag)) {
                    $next[] = $tag;
                }
            }
            if (count($next) !== count($existing)) {
                $added += count($next) - count($existing);
                $tx->setTags($next);
                $touched++;
            }
        }
        $this->em->flush();

        return new JsonResponse([
            'tagged' => $touched,
            'examined' => count($txs),
            'newTagInstances' => $added,
        ]);
    }

    private function convertToBase(int $amountMinor, string $currency, string $date, string $base): int
    {
        if (strtoupper($currency) === strtoupper($base)) return $amountMinor;
        return $this->fx->convertMinor($amountMinor, $currency, $base, new \DateTimeImmutable($date)) ?? $amountMinor;
    }
}
