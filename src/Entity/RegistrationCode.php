<?php

namespace App\Entity;

use App\Repository\RegistrationCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Single-use registration code, issued by an admin to invite one new user.
 * Marked used (with usedBy + usedAt) the moment someone registers with it; can
 * never be reused. Admins can also delete unused codes to revoke them.
 */
#[ORM\Entity(repositoryClass: RegistrationCodeRepository::class)]
#[ORM\Table(name: 'registration_codes')]
class RegistrationCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    /** Optional admin-facing note ("for Alice", "leftover from beta", …). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $usedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function setCreatedBy(User $u): self { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUsedBy(): ?User { return $this->usedBy; }
    public function setUsedBy(?User $u): self { $this->usedBy = $u; return $this; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function setUsedAt(?\DateTimeImmutable $at): self { $this->usedAt = $at; return $this; }

    public function isUsed(): bool { return $this->usedAt !== null; }
}
