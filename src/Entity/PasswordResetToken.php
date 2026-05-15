<?php

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Time-limited password-reset token. The user receives the plaintext token
 * (via email or admin-shared link); the DB only stores the SHA-256 hash so a
 * leaked database snapshot can't be used to reset accounts.
 */
#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 hex digest of the plaintext token. */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $h): self { $this->tokenHash = $h; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $at): self { $this->expiresAt = $at; return $this; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function setUsedAt(?\DateTimeImmutable $at): self { $this->usedAt = $at; return $this; }

    public function isUsable(): bool
    {
        if ($this->usedAt !== null) return false;
        return $this->expiresAt > new \DateTimeImmutable();
    }
}
