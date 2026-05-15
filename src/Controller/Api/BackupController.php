<?php

namespace App\Controller\Api;

use App\Backup\AccountBackup;
use App\Backup\ImportMode;
use App\Entity\User;
use App\Repository\AccountRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/accounts')]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly AccountBackup $backup,
        private readonly AccountRepository $accounts,
    ) {}

    #[Route('/export', name: 'api_accounts_export_all', methods: ['GET'])]
    public function exportAll(#[CurrentUser] User $user): JsonResponse
    {
        return $this->jsonDownload(
            $this->backup->exportForUser($user),
            sprintf('treasury-backup-%s.json', date('Y-m-d')),
        );
    }

    #[Route('/{id}/export', name: 'api_accounts_export_one', methods: ['GET'])]
    public function exportOne(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $account = $this->accounts->findOneOwnedBy($id, $user);
        if ($account === null) {
            throw new NotFoundHttpException();
        }
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', $account->getName()) ?: $id;
        return $this->jsonDownload(
            $this->backup->exportOne($account),
            sprintf('treasury-%s-%s.json', strtolower($slug), date('Y-m-d')),
        );
    }

    #[Route('/import', name: 'api_accounts_import', methods: ['POST'])]
    public function import(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mode = ImportMode::tryFrom((string) $request->query->get('mode', 'skip')) ?? ImportMode::Skip;

        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON: ' . $e->getMessage()], 422);
        }
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload must be a JSON object'], 422);
        }

        try {
            $result = $this->backup->import($payload, $user, $mode);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
        return new JsonResponse($result->toArray());
    }

    private function jsonDownload(array $data, string $filename): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        return $response;
    }
}
