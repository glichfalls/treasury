<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        return new JsonResponse($this->serialize($user));
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->serialize($user));
    }

    #[Route('/api/me/password', name: 'api_me_change_password', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $current = is_array($body) ? (string) ($body['currentPassword'] ?? '') : '';
        $new = is_array($body) ? (string) ($body['newPassword'] ?? '') : '';

        if (!$this->hasher->isPasswordValid($user, $current)) {
            return new JsonResponse(['error' => 'Current password is incorrect'], 422);
        }
        if (strlen($new) < 8) {
            return new JsonResponse(['error' => 'New password must be at least 8 characters'], 422);
        }
        if ($new === $current) {
            return new JsonResponse(['error' => 'New password must differ from the current one'], 422);
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/api/me/preferences', name: 'api_me_update_preferences', methods: ['PATCH'])]
    public function updatePreferences(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Body must be a JSON object'], 422);
        }
        if (array_key_exists('baseCurrency', $body)) {
            $ccy = strtoupper(trim((string) $body['baseCurrency']));
            if (!preg_match('/^[A-Z]{3}$/', $ccy)) {
                return new JsonResponse(['error' => 'baseCurrency must be a 3-letter code'], 422);
            }
            $user->setBaseCurrency($ccy);
        }
        $this->em->flush();
        return new JsonResponse($this->serialize($user));
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'baseCurrency' => $user->getBaseCurrency(),
        ];
    }
}
