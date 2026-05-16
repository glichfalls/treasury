<?php

namespace App\Entity;

use App\Repository\PriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PriceRepository::class)]
#[ORM\Table(name: 'prices')]
#[ORM\UniqueConstraint(name: 'uniq_prices_asset_date', columns: ['asset_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_prices_asset_date', columns: ['asset_id', 'occurred_at'])]
class Price
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Asset $asset;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'bigint')]
    private string $priceMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    /**
     * True once this row reflects the official daily close for the exchange.
     * False means the value is still intraday and may be upgraded by a later
     * refresh once the market has closed.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isClose = false;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAsset(): Asset { return $this->asset; }
    public function setAsset(Asset $asset): self { $this->asset = $asset; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self { $this->occurredAt = $occurredAt; return $this; }
    public function getPriceMinor(): string { return $this->priceMinor; }
    public function setPriceMinor(string|int $priceMinor): self { $this->priceMinor = (string) $priceMinor; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function isClose(): bool { return $this->isClose; }
    public function setIsClose(bool $isClose): self { $this->isClose = $isClose; return $this; }
}
