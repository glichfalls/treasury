<?php

namespace App\Controller\Api;

use App\Entity\Transaction;
use App\Entity\TransactionSource;
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
