<?php

namespace App\Controller\Api;

use App\Entity\RegistrationCode;
use App\Entity\User;
use App\Repository\RegistrationCodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RegistrationCodeRepository $codes,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 422);
        }

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $codeStr = trim((string) ($body['code'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Valid email is required'], 422);
        }
        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters'], 422);
        }
        if ($codeStr === '') {
            return new JsonResponse(['error' => 'Registration code is required'], 422);
        }

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            return new JsonResponse(['error' => 'Email already registered'], 409);
        }

        $code = $this->codes->findByCode($codeStr);
        if ($code === null) {
            return new JsonResponse(['error' => 'Unknown registration code'], 422);
        }
        if ($code->isUsed()) {
            return new JsonResponse(['error' => 'This code has already been used'], 410);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $code->setUsedBy($user);
        $code->setUsedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ], 201);
    }

    #[Route('/api/registration-codes', name: 'api_registration_codes_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(
            fn(RegistrationCode $c) => $this->serialize($c),
            $this->codes->findAllOrdered(),
        ));
    }

    #[Route('/api/registration-codes', name: 'api_registration_codes_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $label = is_array($body) && isset($body['label']) ? trim((string) $body['label']) : '';

        $code = new RegistrationCode();
        $code->setCode($this->generateCode());
        if ($label !== '') {
            $code->setLabel($label);
        }
        $code->setCreatedBy($admin);

        $this->em->persist($code);
        $this->em->flush();

        return new JsonResponse($this->serialize($code), 201);
    }

    #[Route('/api/registration-codes/{id}', name: 'api_registration_codes_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revoke(string $id): JsonResponse
    {
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $code = $this->em->find(RegistrationCode::class, $uuid);
        if ($code === null) {
            throw new NotFoundHttpException();
        }
        if ($code->isUsed()) {
            return new JsonResponse(['error' => 'Cannot revoke a code that has already been used'], 409);
        }
        $this->em->remove($code);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    /** Three groups of four uppercase base32-like chars: easy to read out / type. */
    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I to avoid confusion
        $len = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            if ($i > 0 && $i % 4 === 0) {
                $out .= '-';
            }
            $out .= $alphabet[random_int(0, $len - 1)];
        }
        return $out;
    }

    private function serialize(RegistrationCode $c): array
    {
        return [
            'id' => $c->getId()->toRfc4122(),
            'code' => $c->getCode(),
            'label' => $c->getLabel(),
            'createdAt' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'createdByEmail' => $c->getCreatedBy()->getEmail(),
            'usedAt' => $c->getUsedAt()?->format(\DateTimeInterface::ATOM),
            'usedByEmail' => $c->getUsedBy()?->getEmail(),
        ];
    }
}
