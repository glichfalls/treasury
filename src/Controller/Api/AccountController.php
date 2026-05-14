<?php

namespace App\Controller\Api;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\User;
use App\Holdings\HoldingsService;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/accounts')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntityManagerInterface $em,
        private readonly HoldingsService $holdings,
    ) {}

    #[Route('', name: 'api_accounts_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $accounts = $this->accounts->findByOwner($user);
        $balances = $this->accounts->sumBalancesMinor(array_map(fn($a) => $a->getId(), $accounts));

        return new JsonResponse(array_map(
            function (Account $a) use ($balances) {
                $cash = $balances[$a->getId()->toRfc4122()] ?? '0';
                $holdings = $this->holdings->forAccount($a);
                $holdingsValue = $this->holdings->totalValueMinor($a, $holdings);
                $total = bcadd($cash, $holdingsValue, 0);
                return $this->serializeAccount($a, $cash, $holdingsValue, $total);
            },
            $accounts,
        ));
    }

    #[Route('/{id}/holdings', name: 'api_accounts_holdings', methods: ['GET'])]
    public function holdings(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        return new JsonResponse(array_map(
            fn($h) => $h->toArray(),
            $this->holdings->forAccount($account),
        ));
    }

    #[Route('/coins/catalog', name: 'api_coins_catalog', methods: ['GET'])]
    public function coinCatalog(): JsonResponse
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT isin, name, currency, unit_weight_grams AS weight, price_premium_pct AS premium
             FROM assets WHERE unit_weight_grams IS NOT NULL ORDER BY name ASC",
        );
        return new JsonResponse(array_map(fn($r) => [
            'isin' => $r['isin'],
            'name' => $r['name'],
            'currency' => $r['currency'],
            'unitWeightGrams' => $r['weight'],
            'pricePremiumPct' => $r['premium'],
        ], $rows));
    }

    #[Route('', name: 'api_accounts_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $type = AccountType::tryFrom((string) ($body['type'] ?? ''));
        if ($type === null) {
            return new JsonResponse(['error' => 'Invalid account type'], 422);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if ($name === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return new JsonResponse(['error' => 'Name and 3-letter currency required'], 422);
        }

        $account = new Account();
        $account->setOwner($user);
        $account->setName($name);
        $account->setInstitution($body['institution'] ?? null);
        $account->setType($type);
        $account->setCurrency($currency);

        $this->em->persist($account);
        $this->em->flush();

        return new JsonResponse($this->serializeAccount($account, '0', '0', '0'), 201);
    }

    #[Route('/{id}', name: 'api_accounts_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($account);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function serializeAccount(
        Account $a,
        string $cashMinor,
        string $holdingsMinor = '0',
        ?string $totalMinor = null,
    ): array {
        return [
            'id' => $a->getId()->toRfc4122(),
            'name' => $a->getName(),
            'institution' => $a->getInstitution(),
            'type' => $a->getType()->value,
            'currency' => $a->getCurrency(),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'cashMinor' => $cashMinor,
            'holdingsMinor' => $holdingsMinor,
            'balanceMinor' => $totalMinor ?? $cashMinor,
        ];
    }
}
