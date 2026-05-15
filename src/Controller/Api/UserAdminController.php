<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/users')]
#[IsGranted('ROLE_ADMIN')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(
            fn(User $u) => $this->serialize($u),
            $this->users->findAllOrdered(),
        ));
    }

    #[Route('/{id}/roles', name: 'api_users_update_roles', methods: ['PATCH'])]
    public function updateRoles(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->resolve($id);

        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body) || !isset($body['isAdmin']) || !is_bool($body['isAdmin'])) {
            return new JsonResponse(['error' => 'Body must include boolean "isAdmin"'], 422);
        }

        // Guard: an admin can't strip their own admin role — otherwise they could
        // accidentally lock the instance out of admin entirely if they're the last one.
        if ($admin->getId()->equals($user->getId()) && !$body['isAdmin']) {
            return new JsonResponse(['error' => 'You cannot remove your own admin role'], 409);
        }

        $roles = array_values(array_filter($user->getRoles(), fn($r) => $r !== 'ROLE_ADMIN' && $r !== 'ROLE_USER'));
        if ($body['isAdmin']) {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles(array_values(array_unique($roles)));
        $this->em->flush();

        return new JsonResponse($this->serialize($user));
    }

    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->resolve($id);

        if ($admin->getId()->equals($user->getId())) {
            return new JsonResponse(['error' => 'You cannot delete your own account'], 409);
        }

        $this->em->remove($user);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function resolve(string $id): User
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $user = $this->em->find(User::class, $uuid);
        if ($user === null) {
            throw new NotFoundHttpException();
        }
        return $user;
    }

    private function serialize(User $u): array
    {
        return [
            'id' => $u->getId()->toRfc4122(),
            'email' => $u->getEmail(),
            'roles' => $u->getRoles(),
            'isAdmin' => in_array('ROLE_ADMIN', $u->getRoles(), true),
        ];
    }
}
