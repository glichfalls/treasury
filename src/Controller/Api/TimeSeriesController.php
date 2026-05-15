<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Holdings\HoldingsService;
use App\Repository\AccountRepository;
use App\TimeSeries\PerformanceService;
use App\TimeSeries\TimeSeriesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class TimeSeriesController extends AbstractController
{
    public function __construct(
        private readonly TimeSeriesService $service,
        private readonly PerformanceService $performance,
        private readonly AccountRepository $accounts,
        private readonly HoldingsService $holdings,
    ) {}

    #[Route('/api/networth/timeseries', name: 'api_networth_timeseries', methods: ['GET'])]
    public function networth(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to, $granularity] = $this->parseRange($request);
        $series = $this->service->netWorthSeries($user, $from, $to, $granularity);
        return new JsonResponse(array_map(fn($p) => $p->toArray(), $series));
    }

    #[Route('/api/cashflow', name: 'api_cashflow', methods: ['GET'])]
    public function cashFlow(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        $series = $this->service->cashFlowMonthly($user, $from, $to);
        return new JsonResponse(array_map(fn($p) => $p->toArray(), $series));
    }

    #[Route('/api/cashflow/by-category', name: 'api_cashflow_by_category', methods: ['GET'])]
    public function cashFlowByCategory(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        return new JsonResponse($this->service->cashFlowByCategoryMonthly($user, $from, $to));
    }

    #[Route('/api/allocation', name: 'api_global_allocation', methods: ['GET'])]
    public function globalAllocation(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->service->globalAllocation($user));
    }

    #[Route('/api/accounts/{id}/timeseries', name: 'api_account_timeseries', methods: ['GET'])]
    public function accountSeries(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        [$from, $to, $granularity] = $this->parseRange($request);
        $series = $this->service->accountSeries($account, $from, $to, $granularity);
        return new JsonResponse(array_map(fn($p) => $p->toArray(), $series));
    }

    #[Route('/api/performance', name: 'api_networth_performance', methods: ['GET'])]
    public function networthPerformance(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to, $granularity] = $this->parseRange($request);
        $series = $this->performance->forUser($user, $from, $to, $granularity);
        return new JsonResponse(array_map(fn($p) => $p->toArray(), $series));
    }

    #[Route('/api/accounts/{id}/performance', name: 'api_account_performance', methods: ['GET'])]
    public function accountPerformance(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        [$from, $to, $granularity] = $this->parseRange($request);
        $series = $this->performance->forAccount($account, $from, $to, $granularity);
        return new JsonResponse(array_map(fn($p) => $p->toArray(), $series));
    }

    #[Route('/api/accounts/{id}/allocation', name: 'api_account_allocation', methods: ['GET'])]
    public function allocation(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $holdings = $this->holdings->forAccount($account);
        $slices = [];
        $cashMinor = $this->cashMinorFor($account, $holdings);
        if ($cashMinor !== '0') {
            $slices[] = ['label' => 'Cash', 'isin' => null, 'valueBaseMinor' => $cashMinor];
        }
        foreach ($holdings as $h) {
            if ($h->valueBaseMinor === null) {
                continue;
            }
            $slices[] = [
                'label' => $h->ticker ?? $h->name ?? $h->isin,
                'isin' => $h->isin,
                'valueBaseMinor' => $h->valueBaseMinor,
            ];
        }
        return new JsonResponse([
            'baseCurrency' => $account->getCurrency(),
            'slices' => $slices,
        ]);
    }

    #[Route('/api/assets/{isin}/prices', name: 'api_asset_prices', methods: ['GET'])]
    public function assetPrices(string $isin, Request $request): JsonResponse
    {
        $isin = strtoupper($isin);
        [$from, $to, $_g] = $this->parseRange($request);

        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $this->service->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT p.occurred_at, p.price_minor, p.currency
             FROM prices p
             INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin = ? AND p.occurred_at >= ? AND p.occurred_at <= ?
             ORDER BY p.occurred_at ASC',
            [$isin, $from->format('Y-m-d'), $to->format('Y-m-d')],
        );
        return new JsonResponse([
            'isin' => $isin,
            'points' => array_map(fn($r) => [
                'date' => $r['occurred_at'],
                'priceMinor' => $r['price_minor'],
                'currency' => $r['currency'],
            ], $rows),
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function parseRange(Request $request): array
    {
        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');
        $granularity = $request->query->get('granularity', 'daily');
        if (!in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            $granularity = 'daily';
        }

        $to = $toStr !== null ? new \DateTimeImmutable($toStr) : new \DateTimeImmutable('today');
        $from = $fromStr !== null
            ? new \DateTimeImmutable($fromStr)
            : $to->modify('-2 years');
        return [$from, $to, $granularity];
    }

    private function cashMinorFor(\App\Entity\Account $account, array $holdings): string
    {
        // Total balance = cash + holdings. We don't get cash directly; fetch via the
        // account row's serialized value... but TimeSeriesService is connection-only.
        // Simpler: SUM(amount_minor) for the account.
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $this->service->getConnection();
        $sum = $conn->fetchOne(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM transactions WHERE account_id = ?',
            [$account->getId()->toBinary()],
        );
        return (string) $sum;
    }
}
