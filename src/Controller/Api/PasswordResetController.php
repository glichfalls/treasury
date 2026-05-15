<?php

namespace App\Controller\Api;

use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    private const TOKEN_LIFETIME = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Always returns 204 even if the email is unknown, so the endpoint can't be
     * used to enumerate registered emails. If a matching user exists, a token is
     * generated and either emailed to them or logged for the admin to pass on
     * (depending on whether MAILER_DSN is configured).
     */
    #[Route('/api/password/forgot', name: 'api_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $email = is_array($body) ? strtolower(trim((string) ($body['email'] ?? ''))) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Valid email is required'], 422);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            return new JsonResponse(null, 204);
        }

        $plaintext = bin2hex(random_bytes(24));
        $token = new PasswordResetToken();
        $token->setUser($user);
        $token->setTokenHash(hash('sha256', $plaintext));
        $token->setExpiresAt((new \DateTimeImmutable())->modify(self::TOKEN_LIFETIME));
        $this->em->persist($token);
        $this->em->flush();

        $resetUrl = sprintf('%s/reset-password?token=%s',
            $request->getSchemeAndHttpHost(),
            $plaintext,
        );

        // Always log the URL so the admin can fall back to manual delivery if the
        // mailer is misconfigured (e.g. MAILER_DSN=null://null in dev).
        $this->logger->info('Password reset link generated', [
            'email' => $email,
            'url' => $resetUrl,
            'expires' => $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);

        try {
            $this->mailer->send(
                (new Email())
                    ->to($email)
                    ->subject('Treasury — reset your password')
                    ->text(sprintf(
                        "Click the link below to set a new password. The link is valid for one hour.\n\n%s\n\nIf you didn't request this, ignore this email.\n",
                        $resetUrl,
                    )),
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Password reset email failed', ['error' => $e->getMessage()]);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/password/reset', name: 'api_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $plaintext = is_array($body) ? trim((string) ($body['token'] ?? '')) : '';
        $newPassword = is_array($body) ? (string) ($body['newPassword'] ?? '') : '';

        if ($plaintext === '') {
            return new JsonResponse(['error' => 'Reset token is required'], 422);
        }
        if (strlen($newPassword) < 8) {
            return new JsonResponse(['error' => 'Password must be at least 8 characters'], 422);
        }

        $token = $this->tokens->findByHash(hash('sha256', $plaintext));
        if ($token === null || !$token->isUsable()) {
            return new JsonResponse(['error' => 'Reset link is invalid or has expired'], 410);
        }

        $user = $token->getUser();
        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $token->setUsedAt(new \DateTimeImmutable());

        $this->em->flush();

        return new JsonResponse(null, 204);
    }
}
