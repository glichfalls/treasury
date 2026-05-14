<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Import\ImportService;
use App\Price\PriceFetcher;
use App\Repository\AccountRepository;
use App\Repository\AssetRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/accounts/{accountId}/imports')]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly ImportService $importer,
        private readonly AssetRepository $assets,
        private readonly PriceFetcher $prices,
    ) {}

    #[Route('', name: 'api_imports_create', methods: ['POST'])]
    public function upload(string $accountId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($accountId, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['error' => 'No file uploaded (expected "file" multipart field)'], 422);
        }

        $result = $this->importer->importFromFile($account, $file->getPathname());

        if ($result->imported > 0) {
            $held = $this->assets->findHeldByAccount($account->getId());
            $priceResult = $this->prices->refreshAssets($held);
            $this->prices->refreshFxFor([$account->getCurrency()], $held);
            $result = $result->withPriceRefresh($priceResult);
        }

        return new JsonResponse($result->toArray(), 201);
    }
}
