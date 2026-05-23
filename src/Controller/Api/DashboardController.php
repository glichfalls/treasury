<?php

namespace App\Controller\Api;

use App\Dashboard\DashboardService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    #[Route('/movers', name: 'api_dashboard_movers', methods: ['GET'])]
    public function movers(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = max(1, min(20, (int) $request->query->get('limit', '5')));
        return new JsonResponse($this->dashboard->topMovers($user, $limit));
    }

    #[Route('/pnl-today', name: 'api_dashboard_pnl_today', methods: ['GET'])]
    public function pnlToday(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->dashboard->todayPnl($user));
    }

    #[Route('/activity', name: 'api_dashboard_activity', methods: ['GET'])]
    public function activity(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $limit = max(1, min(50, (int) $request->query->get('limit', '10')));
        return new JsonResponse($this->dashboard->recentActivity($user, $limit));
    }
}
