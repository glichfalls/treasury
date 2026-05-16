<?php

namespace App\Controller\Api;

use App\Entity\AccountType;
use App\Entity\Transaction;
use App\Entity\TransactionCategory;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/accounts/{accountId}/transactions')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_transactions_list', methods: ['GET'])]
    public function list(string $accountId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = (int) $request->query->get('pageSize', '25');

        $typeParam = $request->query->get('type');
        $type = $typeParam !== null && $typeParam !== ''
            ? TransactionType::tryFrom($typeParam)
            : null;

        $catParam = $request->query->get('category');
        $category = $catParam !== null && $catParam !== ''
            ? TransactionCategory::tryFrom($catParam)
            : null;

        $fromParam = $request->query->get('from');
        $from = null;
        if ($fromParam !== null && $fromParam !== '') {
            try { $from = new \DateTimeImmutable($fromParam); } catch (\Exception) {}
        }
        $toParam = $request->query->get('to');
        $to = null;
        if ($toParam !== null && $toParam !== '') {
            try { $to = new \DateTimeImmutable($toParam); } catch (\Exception) {}
        }

        $q = $request->query->get('q');
        $q = is_string($q) ? trim($q) : null;

        // Sort format: "column:direction" e.g. "occurredAt:desc".
        $sortRaw = (string) $request->query->get('sort', '');
        [$sortColumn, $sortDir] = array_pad(explode(':', $sortRaw, 2), 2, '');

        $result = $this->transactions->findPage(
            $account, $page, $pageSize, $type, $from, $to, $q ?: null, $category,
            $sortColumn ?: null, $sortDir ?: null,
        );

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => max(1, min(200, $pageSize)),
        ]);
    }

    #[Route('', name: 'api_transactions_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $occurredAt = $body['occurredAt'] ?? null;
        $amountMinor = $body['amountMinor'] ?? null;
        if (!is_string($occurredAt) || !is_string($amountMinor) || !preg_match('/^-?\d+$/', $amountMinor)) {
            return new JsonResponse(['error' => 'occurredAt (date) and amountMinor (integer string) required'], 422);
        }

        try {
            $date = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid occurredAt'], 422);
        }

        $t = new Transaction();
        $t->setAccount($account);
        $t->setOccurredAt($date);
        $t->setAmountMinor($amountMinor);
        $t->setCurrency($body['currency'] ?? $account->getCurrency());
        $t->setDescription($body['description'] ?? null);
        $t->setSource(TransactionSource::Manual);

        // Optional asset linkage (used by the coin-purchase form, and possibly other
        // manual trades). For coin purchases the client will send type=trade_buy,
        // assetIsin (catalog id), assetQuantity, and a negative amountMinor.
        if (!empty($body['assetIsin']) && is_string($body['assetIsin'])) {
            $t->setAssetIsin($body['assetIsin']);
        }
        if (isset($body['assetQuantity']) && (is_string($body['assetQuantity']) || is_numeric($body['assetQuantity']))) {
            $t->setAssetQuantity((string) $body['assetQuantity']);
        }
        if (!empty($body['type']) && is_string($body['type'])) {
            $typeEnum = \App\Entity\TransactionType::tryFrom($body['type']);
            if ($typeEnum !== null) {
                $t->setType($typeEnum);
            }
        }
        if (!empty($body['category']) && is_string($body['category'])) {
            $catEnum = TransactionCategory::tryFrom($body['category']);
            if ($catEnum !== null) {
                $t->setCategory($catEnum);
            }
        }
        if (isset($body['tags']) && is_array($body['tags'])) {
            $t->setTags($body['tags']);
        }

        // Auto-tag: any existing tag whose name appears as a substring of the
        // description gets applied. Lets the user tag "Netflix" once and have
        // future bank rows for "NETFLIX.COM 866-579-7117" pick it up.
        $t->setTags(array_merge($t->getTags(), $this->autoTagsFor($user, $t->getDescription(), $t->getTags())));

        $this->em->persist($t);
        $this->em->flush();

        return new JsonResponse($this->serialize($t), 201);
    }

    /**
     * Suggest tags for a description by matching against the user's existing
     * distinct tags. Case-insensitive substring match — simple, predictable,
     * no surprise auto-tagging from unrelated transactions.
     *
     * @param list<string> $existing Tags already on the transaction (won't re-suggest these).
     * @return list<string>
     */
    private function autoTagsFor(User $user, ?string $description, array $existing): array
    {
        if ($description === null || $description === '') return [];
        $haystack = strtolower($description);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(t.tags, '$[*]')) AS tag_blob, t.tags
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND JSON_LENGTH(t.tags) > 0
             LIMIT 500",
            ['owner' => $user->getId()->toBinary()],
        );

        $allTags = [];
        foreach ($rows as $r) {
            $decoded = json_decode($r['tags'] ?? '[]', true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    if (is_string($t) && $t !== '') $allTags[strtolower($t)] = true;
                }
            }
        }

        $matches = [];
        foreach (array_keys($allTags) as $tag) {
            if (in_array($tag, $existing, true)) continue;
            if (str_contains($haystack, $tag)) {
                $matches[] = $tag;
            }
        }
        return $matches;
    }

    #[Route('/{transactionId}', name: 'api_transactions_update', methods: ['PATCH'], requirements: ['transactionId' => '[0-9a-f-]+'])]
    public function update(string $accountId, string $transactionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($transactionId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $tx = $this->transactions->findOneBy(['id' => $uuid, 'account' => $account]);
        if ($tx === null) {
            throw new NotFoundHttpException();
        }

        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        // Remember the date BEFORE we mutate it — the trade-cascade below needs
        // to find sibling trades by their old date.
        $previousOccurredAt = $tx->getOccurredAt();
        $cascadedTradeDates = 0;
        if (array_key_exists('occurredAt', $body)) {
            try {
                $newDate = new \DateTimeImmutable((string) $body['occurredAt']);
            } catch (\Exception) {
                return new JsonResponse(['error' => 'Invalid occurredAt'], 422);
            }
            $tx->setOccurredAt($newDate);

            // For Pillar 3a contributions/opening balances, the trade rows on the
            // SAME old date are auto-generated siblings of this deposit — keep
            // their date in lockstep so reports and the delete cascade stay
            // coherent. Without this, editing a deposit's date orphans the
            // trades on the original date (and they then show up as today's
            // performance instead of the user's chosen date).
            $isContribution = $account->getType() === AccountType::Pillar3a
                && in_array($tx->getType(), [TransactionType::Deposit, TransactionType::OpeningBalance], true);
            if ($isContribution && $newDate->format('Y-m-d') !== $previousOccurredAt->format('Y-m-d')) {
                $cascadedTradeDates = (int) $this->em->getConnection()->executeStatement(
                    "UPDATE transactions
                     SET occurred_at = :new
                     WHERE account_id = :a AND occurred_at = :old
                       AND type IN ('trade_buy', 'trade_sell')",
                    [
                        'a' => $account->getId()->toBinary(),
                        'old' => $previousOccurredAt->format('Y-m-d'),
                        'new' => $newDate->format('Y-m-d'),
                    ],
                );
            }
        }
        if (array_key_exists('amountMinor', $body)) {
            if (!is_string($body['amountMinor']) || !preg_match('/^-?\d+$/', $body['amountMinor'])) {
                return new JsonResponse(['error' => 'amountMinor must be an integer string'], 422);
            }
            $tx->setAmountMinor($body['amountMinor']);
        }
        if (array_key_exists('description', $body)) {
            $desc = $body['description'];
            $tx->setDescription(is_string($desc) && trim($desc) !== '' ? trim($desc) : null);
        }
        if (array_key_exists('type', $body)) {
            $typeEnum = TransactionType::tryFrom((string) $body['type']);
            if ($typeEnum === null) {
                return new JsonResponse(['error' => 'Invalid transaction type'], 422);
            }
            $tx->setType($typeEnum);
        }
        if (array_key_exists('currency', $body)) {
            $currency = strtoupper(trim((string) $body['currency']));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                return new JsonResponse(['error' => 'Currency must be a 3-letter code'], 422);
            }
            $tx->setCurrency($currency);
        }
        if (array_key_exists('category', $body)) {
            $value = $body['category'];
            if ($value === null || $value === '') {
                $tx->setCategory(null);
            } else {
                $cat = TransactionCategory::tryFrom((string) $value);
                if ($cat === null) {
                    return new JsonResponse(['error' => 'Invalid category'], 422);
                }
                $tx->setCategory($cat);
            }
        }
        if (array_key_exists('tags', $body)) {
            if (!is_array($body['tags'])) {
                return new JsonResponse(['error' => 'tags must be an array of strings'], 422);
            }
            $tx->setTags($body['tags']);
        }

        $this->em->flush();

        return new JsonResponse([
            ...$this->serialize($tx),
            'cascadedTradeDates' => $cascadedTradeDates,
        ]);
    }

    #[Route('/{transactionId}', name: 'api_transactions_get', methods: ['GET'], requirements: ['transactionId' => '[0-9a-f-]+'])]
    public function get(string $accountId, string $transactionId, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($transactionId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $tx = $this->transactions->findOneBy(['id' => $uuid, 'account' => $account]);
        if ($tx === null) {
            throw new NotFoundHttpException();
        }
        return new JsonResponse([
            ...$this->serialize($tx),
            'accountName' => $account->getName(),
            'accountType' => $account->getType()->value,
        ]);
    }

    /**
     * Delete a transaction. For Pillar 3a accounts, removing a deposit also drops the
     * auto-generated trade rows on the same day so the 3a stays coherent — the trades
     * only exist because the deposit was logged via the contribution flow.
     */
    #[Route('/{transactionId}', name: 'api_transactions_delete', methods: ['DELETE'], requirements: ['transactionId' => '[0-9a-f-]+'])]
    public function delete(string $accountId, string $transactionId, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($transactionId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $tx = $this->transactions->findOneBy(['id' => $uuid, 'account' => $account]);
        if ($tx === null) {
            throw new NotFoundHttpException();
        }

        $cascaded = 0;
        if ($account->getType() === AccountType::Pillar3a
            && in_array($tx->getType(), [TransactionType::Deposit, TransactionType::OpeningBalance], true)
        ) {
            $cascaded = (int) $this->em->getConnection()->executeStatement(
                "DELETE FROM transactions
                 WHERE account_id = :a AND occurred_at = :d AND type IN ('trade_buy', 'trade_sell')",
                [
                    'a' => $account->getId()->toBinary(),
                    'd' => $tx->getOccurredAt()->format('Y-m-d'),
                ],
            );
        }

        $this->em->remove($tx);
        $this->em->flush();

        return new JsonResponse(['deletedId' => $transactionId, 'cascadedTradeCount' => $cascaded], 200);
    }

    private function serialize(Transaction $t): array
    {
        return [
            'id' => $t->getId()->toRfc4122(),
            'accountId' => $t->getAccount()->getId()->toRfc4122(),
            'occurredAt' => $t->getOccurredAt()->format('Y-m-d'),
            'amountMinor' => $t->getAmountMinor(),
            'currency' => $t->getCurrency(),
            'description' => $t->getDescription(),
            'type' => $t->getType()->value,
            'source' => $t->getSource()->value,
            'category' => $t->getCategory()?->value,
            'tags' => $t->getTags(),
            'assetIsin' => $t->getAssetIsin(),
            'assetQuantity' => $t->getAssetQuantity(),
        ];
    }
}
