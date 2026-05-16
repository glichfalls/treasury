<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Insights\InsightsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/insights')]
class InsightsController extends AbstractController
{
    public function __construct(
        private readonly InsightsService $insights,
    ) {}

    #[Route('/currencies', name: 'api_insights_currencies', methods: ['GET'])]
    public function currencies(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $base = $user->getBaseCurrency();
        [$from, $to] = $this->parseRange($request);
        $granularity = $request->query->get('granularity', 'weekly');
        if (!in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            $granularity = 'weekly';
        }
        return new JsonResponse([
            'baseCurrency' => $base,
            'snapshot' => $this->insights->currencyExposureSnapshot($user, $base),
            'fxGain' => $this->insights->fxGainByCurrency($user, $base),
            'history' => $this->insights->currencyExposureHistory($user, $from, $to, $granularity, $base),
        ]);
    }

    #[Route('/fees', name: 'api_insights_fees', methods: ['GET'])]
    public function fees(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        return new JsonResponse($this->insights->fees($user, $from, $to, $user->getBaseCurrency()));
    }

    #[Route('/dividends', name: 'api_insights_dividends', methods: ['GET'])]
    public function dividends(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$from, $to] = $this->parseRange($request);
        return new JsonResponse($this->insights->dividends($user, $from, $to, $user->getBaseCurrency()));
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function parseRange(Request $request): array
    {
        $toStr = $request->query->get('to');
        $fromStr = $request->query->get('from');
        $to = $toStr !== null ? new \DateTimeImmutable($toStr) : new \DateTimeImmutable('today');
        $from = $fromStr !== null ? new \DateTimeImmutable($fromStr) : $to->modify('-1 year');
        return [$from, $to];
    }
}
