<?php

namespace App\Controller\Api;

use App\Entity\AccountAllocation;
use App\Entity\Asset;
use App\Entity\User;
use App\Pillar3a\ContributionService;
use App\Price\PriceFetcher;
use App\Repository\AccountAllocationRepository;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/accounts/{id}')]
class AllocationController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountAllocationRepository $allocations,
        private readonly EntityManagerInterface $em,
        private readonly ContributionService $contributions,
        private readonly PriceFetcher $priceFetcher,
    ) {}

    #[Route('/strategy', name: 'api_strategy_get', methods: ['GET'])]
    public function get(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $rows = $this->allocations->findEffective($account);
        $assetMeta = $this->loadAssetMeta(array_map(fn($r) => $r->getAssetIsin(), $rows));
        $effectiveFrom = $rows === [] ? null : $rows[0]->getEffectiveFrom()->format('Y-m-d');
        return new JsonResponse([
            'effectiveFrom' => $effectiveFrom,
            'rules' => array_map(fn($r) => $this->serializeRule($r, $assetMeta), $rows),
        ]);
    }

    #[Route('/strategy/history', name: 'api_strategy_history', methods: ['GET'])]
    public function history(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $versions = $this->allocations->findAllVersions($account);
        $allIsins = [];
        foreach ($versions as $rules) {
            foreach ($rules as $r) {
                $allIsins[] = $r->getAssetIsin();
            }
        }
        $assetMeta = $this->loadAssetMeta(array_values(array_unique($allIsins)));

        $out = [];
        foreach ($versions as $date => $rules) {
            $out[] = [
                'effectiveFrom' => $date,
                'rules' => array_map(fn($r) => $this->serializeRule($r, $assetMeta), $rules),
            ];
        }
        return new JsonResponse($out);
    }

    /**
     * @param list<string> $isins
     * @return array<string, array{ticker: ?string, name: ?string}>
     */
    private function loadAssetMeta(array $isins): array
    {
        if ($isins === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($isins), '?'));
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT isin, ticker, name FROM assets WHERE isin IN ($placeholders)",
            $isins,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['isin']] = ['ticker' => $r['ticker'], 'name' => $r['name']];
        }
        return $out;
    }

    /** @param array<string, array{ticker:?string,name:?string}> $meta */
    private function serializeRule(AccountAllocation $r, array $meta): array
    {
        $m = $meta[$r->getAssetIsin()] ?? ['ticker' => null, 'name' => null];
        return [
            'assetIsin' => $r->getAssetIsin(),
            'percent' => $r->getPercent(),
            'ticker' => $m['ticker'],
            'name' => $m['name'],
        ];
    }

    /**
     * Replace the account's allocation with the supplied list. Body: { allocations: [
     *   { assetIsin, percent }, ... ] } where percent is 0..100 with up to two decimals.
     */
    #[Route('/strategy', name: 'api_strategy_put', methods: ['PUT'])]
    public function put(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $rules = $body['allocations'] ?? [];
        if (!is_array($rules)) {
            return new JsonResponse(['error' => 'allocations must be an array'], 422);
        }

        // Validate and collect ISINs to create on the fly.
        $totalBp = 0;
        $assetRepo = $this->em->getRepository(Asset::class);
        $valid = [];
        $newAssets = [];
        foreach ($rules as $r) {
            $isin = isset($r['assetIsin']) && is_string($r['assetIsin']) ? strtoupper(trim($r['assetIsin'])) : null;
            $percent = isset($r['percent']) ? (float) $r['percent'] : null;
            if ($isin === null || $percent === null || $percent < 0) {
                return new JsonResponse(['error' => 'each rule needs assetIsin + percent ≥ 0'], 422);
            }
            $bp = (int) round($percent * 100);
            if ($bp === 0) {
                continue;
            }
            $existing = $assetRepo->findOneBy(['isin' => $isin]);
            if ($existing === null) {
                $asset = (new Asset())->setIsin($isin);
                $this->em->persist($asset);
                $newAssets[] = $asset;
            }
            $totalBp += $bp;
            $valid[] = [$isin, $bp];
        }
        if ($totalBp > 10000) {
            return new JsonResponse(['error' => sprintf('Total allocation %.2f %% exceeds 100 %%', $totalBp / 100)], 422);
        }

        // Create a new strategy version dated today (or a user-picked date). Past rule
        // sets stay in the DB so back-dated contributions keep using whichever strategy
        // was in effect on their date. If a version with the same effectiveFrom already
        // exists, replace it — saving with the same date is an idempotent update of that
        // version, not an append.
        $effectiveFromStr = $body['effectiveFrom'] ?? null;
        try {
            $effectiveFrom = is_string($effectiveFromStr)
                ? new \DateTimeImmutable($effectiveFromStr)
                : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid effectiveFrom date'], 422);
        }

        // Drop any pre-existing rules with the same effectiveFrom (replace-not-append).
        // Flushed before inserts to avoid colliding with the soon-to-be-added new rows.
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM account_allocations WHERE account_id = :a AND effective_from = :d',
            [
                'a' => $account->getId()->toBinary(),
                'd' => $effectiveFrom->format('Y-m-d'),
            ],
        );

        foreach ($valid as [$isin, $bp]) {
            $rule = (new AccountAllocation())
                ->setAccount($account)
                ->setAssetIsin($isin)
                ->setPercentBasisPoints($bp)
                ->setEffectiveFrom($effectiveFrom);
            $this->em->persist($rule);
        }
        $this->em->flush();

        // Resolve newly-created assets through Yahoo so the user gets a ticker, name,
        // and current price immediately. Failures are non-fatal — the asset row exists
        // and the next price-backfill run can pick it up.
        $resolved = [];
        $unresolved = [];
        if ($newAssets !== []) {
            $result = $this->priceFetcher->refreshAssets($newAssets);
            foreach ($newAssets as $a) {
                if ($a->getTicker() !== null) {
                    $resolved[] = $a->getIsin();
                } else {
                    $unresolved[] = $a->getIsin();
                }
            }
        }

        return new JsonResponse([
            'totalPercent' => $totalBp / 100,
            'effectiveFrom' => $effectiveFrom->format('Y-m-d'),
            'resolved' => $resolved,
            'unresolved' => $unresolved,
        ], 200);
    }

    /**
     * Body: { occurredAt: 'YYYY-MM-DD', amountMinor: '12345' }. Creates a deposit plus
     * fractional buy transactions per allocation, valued at the contribution-date price.
     */
    #[Route('/contributions', name: 'api_contributions_create', methods: ['POST'])]
    public function contribute(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $occurredAt = $body['occurredAt'] ?? null;
        $amountMinor = $body['amountMinor'] ?? null;
        if (!is_string($occurredAt) || !is_string($amountMinor) || !preg_match('/^\d+$/', $amountMinor)) {
            return new JsonResponse(['error' => 'occurredAt (date) and amountMinor (positive integer string) required'], 422);
        }

        try {
            $date = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Invalid occurredAt'], 422);
        }

        $description = is_string($body['description'] ?? null) ? $body['description'] : null;
        $result = $this->contributions->record($account, $date, (int) $amountMinor, $description);

        return new JsonResponse([
            'depositId' => $result['deposit']->getId()->toRfc4122(),
            'tradeCount' => count($result['trades']),
            'missingPrices' => $result['missingPrices'],
        ], 201);
    }
}
