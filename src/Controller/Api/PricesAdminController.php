<?php

namespace App\Controller\Api;

use App\Schedule\RefreshPricesMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/prices')]
#[IsGranted('ROLE_ADMIN')]
class PricesAdminController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/refresh', name: 'api_admin_prices_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        $this->bus->dispatch(new RefreshPricesMessage());
        return new JsonResponse(['queued' => true], 202);
    }
}
