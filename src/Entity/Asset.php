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

    /**
     * Grams of fine metal per unit, for assets whose value derives from a commodity
     * spot price (e.g. gold coins). Null for stocks/ETFs/cash.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $unitWeightGrams = null;

    /**
     * Markup (or discount) percent over the commodity spot price for commodity-backed
     * assets — e.g. 5.0 means the coin trades at 5 % over melt value. Null defaults to 0.
     */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 3, nullable: true)]
    private ?string $pricePremiumPct = null;

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
    public function getUnitWeightGrams(): ?string { return $this->unitWeightGrams; }
    public function setUnitWeightGrams(?string $grams): self { $this->unitWeightGrams = $grams; return $this; }
    public function getPricePremiumPct(): ?string { return $this->pricePremiumPct; }
    public function setPricePremiumPct(?string $pct): self { $this->pricePremiumPct = $pct; return $this; }
}
