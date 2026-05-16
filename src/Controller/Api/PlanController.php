<?php

namespace App\Controller\Api;

use App\Entity\PlanScenario;
use App\Entity\User;
use App\Plan\PlanService;
use App\Plan\PlanWindow;
use App\Repository\PlanScenarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PlanController extends AbstractController
{
    public function __construct(
        private readonly PlanService $service,
        private readonly PlanScenarioRepository $scenarios,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/plan/accounts', name: 'api_plan_accounts', methods: ['GET'])]
    public function accounts(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $window = PlanWindow::fromQuery($request->query->get('window'));
        return new JsonResponse([
            'baseCurrency' => $user->getBaseCurrency(),
            'window' => $window->value,
            'accounts' => $this->service->inputsForUser($user, $window),
        ]);
    }

    #[Route('/api/plan/scenarios', name: 'api_plan_scenarios_list', methods: ['GET'])]
    public function listScenarios(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(array_map(
            fn(PlanScenario $s) => $this->serialize($s),
            $this->scenarios->findByOwner($user),
        ));
    }

    #[Route('/api/plan/scenarios', name: 'api_plan_scenarios_create', methods: ['POST'])]
    public function createScenario(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Name is required'], 422);
        }
        if (mb_strlen($name) > 100) {
            return new JsonResponse(['error' => 'Name must be at most 100 characters'], 422);
        }
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        $scenario = (new PlanScenario())
            ->setOwner($user)
            ->setName($name)
            ->setPayload($payload);
        $this->em->persist($scenario);
        $this->em->flush();

        return new JsonResponse($this->serialize($scenario), 201);
    }

    #[Route('/api/plan/scenarios/{id}', name: 'api_plan_scenarios_update', methods: ['PUT'])]
    public function updateScenario(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $scenario = $this->scenarios->findOneOwnedBy($id, $user);
        if ($scenario === null) {
            throw new NotFoundHttpException();
        }
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'Name is required'], 422);
            }
            if (mb_strlen($name) > 100) {
                return new JsonResponse(['error' => 'Name must be at most 100 characters'], 422);
            }
            $scenario->setName($name);
        }
        if (array_key_exists('payload', $body) && is_array($body['payload'])) {
            $scenario->setPayload($body['payload']);
        }
        $scenario->touch();
        $this->em->flush();

        return new JsonResponse($this->serialize($scenario));
    }

    #[Route('/api/plan/scenarios/{id}', name: 'api_plan_scenarios_delete', methods: ['DELETE'])]
    public function deleteScenario(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $scenario = $this->scenarios->findOneOwnedBy($id, $user);
        if ($scenario === null) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($scenario);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function serialize(PlanScenario $s): array
    {
        return [
            'id' => $s->getId()->toRfc4122(),
            'name' => $s->getName(),
            'payload' => $s->getPayload(),
            'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
