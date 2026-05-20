<?php

namespace App\Controller\Api;

use App\Entity\Account;
use App\Entity\AccountProvider;
use App\Entity\AccountType;
use App\Entity\User;
use App\Holdings\HoldingsService;
use App\Repository\AccountRepository;
use App\Sync\IbkrFlexService;
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
        private readonly IbkrFlexService $ibkrFlex,
    ) {}

    #[Route('', name: 'api_accounts_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $accounts = $this->accounts->findByOwner($user);
        $ids = array_map(fn($a) => $a->getId(), $accounts);
        $balances = $this->accounts->sumBalancesMinor($ids);
        $hasOpening = $this->accounts->hasOpeningBalanceMap($ids);

        return new JsonResponse(array_map(
            function (Account $a) use ($balances, $hasOpening) {
                $cash = $balances[$a->getId()->toRfc4122()] ?? '0';
                $holdings = $this->holdings->forAccount($a);
                $holdingsValue = $this->holdings->totalValueMinor($a, $holdings);
                $total = bcadd($cash, $holdingsValue, 0);
                return $this->serializeAccount(
                    $a,
                    $cash,
                    $holdingsValue,
                    $total,
                    $hasOpening[$a->getId()->toRfc4122()] ?? false,
                );
            },
            $accounts,
        ));
    }

    #[Route('/{id}/holdings', name: 'api_accounts_holdings', methods: ['GET'])]
    public function holdings(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        return new JsonResponse(array_map(
            fn($h) => $h->toArray(),
            $this->holdings->forAccount($account),
        ));
    }

    #[Route('/{id}/sync', name: 'api_accounts_sync', methods: ['POST'])]
    public function sync(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        if ($account->getProvider() !== AccountProvider::Ibkr) {
            return new JsonResponse(['error' => 'Sync is not supported for this account provider'], 422);
        }

        try {
            $result = $this->ibkrFlex->sync($account);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        $account->setLastSyncedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse([
            'imported' => $result->imported,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
        ]);
    }

    #[Route('/assets/catalog', name: 'api_assets_catalog', methods: ['GET'])]
    public function assetCatalog(): JsonResponse
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT isin, ticker, name, currency
             FROM assets
             WHERE unit_weight_grams IS NULL
               AND isin NOT LIKE 'SPOT:%'
             ORDER BY ticker IS NULL, ticker ASC, name ASC",
        );
        return new JsonResponse(array_map(fn($r) => [
            'isin' => $r['isin'],
            'ticker' => $r['ticker'],
            'name' => $r['name'],
            'currency' => $r['currency'],
        ], $rows));
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
        $account->setProvider(AccountProvider::tryFrom((string) ($body['provider'] ?? '')) ?? AccountProvider::Manual);
        if (isset($body['providerConfig']) && is_array($body['providerConfig'])) {
            $account->setProviderConfig($this->sanitizeProviderConfig($body['providerConfig']));
        }

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

    #[Route('/{id}', name: 'api_accounts_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'Name cannot be empty'], 422);
            }
            $account->setName($name);
        }
        if (array_key_exists('institution', $body)) {
            $institution = $body['institution'];
            $account->setInstitution(is_string($institution) && trim($institution) !== '' ? trim($institution) : null);
        }
        if (array_key_exists('type', $body)) {
            $type = AccountType::tryFrom((string) $body['type']);
            if ($type === null) {
                return new JsonResponse(['error' => 'Invalid account type'], 422);
            }
            $account->setType($type);
        }
        if (array_key_exists('currency', $body)) {
            $currency = strtoupper(trim((string) $body['currency']));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                return new JsonResponse(['error' => 'Currency must be a 3-letter code'], 422);
            }
            $account->setCurrency($currency);
        }
        if (array_key_exists('provider', $body)) {
            $account->setProvider(AccountProvider::tryFrom((string) $body['provider']) ?? AccountProvider::Manual);
        }
        if (array_key_exists('providerConfig', $body)) {
            $cfg = $body['providerConfig'];
            $account->setProviderConfig(is_array($cfg) ? $this->sanitizeProviderConfig($cfg) : null);
        }

        $this->em->flush();

        $cash = $this->accounts->sumBalancesMinor([$account->getId()])[$account->getId()->toRfc4122()] ?? '0';
        $holdings = $this->holdings->forAccount($account);
        $holdingsValue = $this->holdings->totalValueMinor($account, $holdings);
        $total = bcadd($cash, $holdingsValue, 0);
        $hasOpening = $this->accounts->hasOpeningBalanceMap([$account->getId()])[$account->getId()->toRfc4122()] ?? false;

        return new JsonResponse($this->serializeAccount($account, $cash, $holdingsValue, $total, $hasOpening));
    }

    /** Keeps only string values to prevent arbitrary data from being stored. */
    private function sanitizeProviderConfig(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && (is_string($v) || $v === null)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function serializeAccount(
        Account $a,
        string $cashMinor,
        string $holdingsMinor = '0',
        ?string $totalMinor = null,
        bool $hasOpeningBalance = false,
    ): array {
        return [
            'id' => $a->getId()->toRfc4122(),
            'name' => $a->getName(),
            'institution' => $a->getInstitution(),
            'type' => $a->getType()->value,
            'currency' => $a->getCurrency(),
            'provider' => $a->getProvider()?->value ?? 'manual',
            'providerConfig' => $a->getProviderConfig(),
            'lastSyncedAt' => $a->getLastSyncedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'cashMinor' => $cashMinor,
            'holdingsMinor' => $holdingsMinor,
            'balanceMinor' => $totalMinor ?? $cashMinor,
            'hasOpeningBalance' => $hasOpeningBalance,
        ];
    }
}
