<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A single application-wide configuration value (admin-managed), e.g. a
 * provider API key. Values are stored as plain text — keep DB access trusted.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
#[ORM\UniqueConstraint(name: 'uniq_app_settings_name', columns: ['name'])]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
