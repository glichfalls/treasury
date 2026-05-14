<?php

namespace App\Controller\Api;

use App\Entity\AccountType;
use App\Entity\Transaction;
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
    public function list(string $accountId, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $transactions = $this->transactions->findByAccount($account);
        return new JsonResponse(array_map($this->serialize(...), $transactions));
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

        $this->em->persist($t);
        $this->em->flush();

        return new JsonResponse($this->serialize($t), 201);
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
        if ($account->getType() === AccountType::Pillar3a && $tx->getType() === TransactionType::Deposit) {
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
            'assetIsin' => $t->getAssetIsin(),
            'assetQuantity' => $t->getAssetQuantity(),
        ];
    }
}
