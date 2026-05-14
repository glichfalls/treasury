<?php

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Table(name: 'assets')]
class Asset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $isin;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $ticker = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getIsin(): string { return $this->isin; }
    public function setIsin(string $isin): self { $this->isin = strtoupper($isin); return $this; }
    public function getTicker(): ?string { return $this->ticker; }
    public function setTicker(?string $ticker): self { $this->ticker = $ticker; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
    public function getCurrency(): ?string { return $this->currency; }
    public function setCurrency(?string $currency): self { $this->currency = $currency !== null ? strtoupper($currency) : null; return $this; }
}
