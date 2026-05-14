<?php

namespace App\Entity;

use App\Repository\FxRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FxRateRepository::class)]
#[ORM\Table(name: 'fx_rates')]
#[ORM\UniqueConstraint(name: 'uniq_fx_date_pair', columns: ['occurred_at', 'from_currency', 'to_currency'])]
#[ORM\Index(name: 'idx_fx_pair_date', columns: ['from_currency', 'to_currency', 'occurred_at'])]
class FxRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 3, name: 'from_currency')]
    private string $fromCurrency;

    #[ORM\Column(length: 3, name: 'to_currency')]
    private string $toCurrency;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $rate;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self { $this->occurredAt = $occurredAt; return $this; }
    public function getFromCurrency(): string { return $this->fromCurrency; }
    public function setFromCurrency(string $c): self { $this->fromCurrency = strtoupper($c); return $this; }
    public function getToCurrency(): string { return $this->toCurrency; }
    public function setToCurrency(string $c): self { $this->toCurrency = strtoupper($c); return $this; }
    public function getRate(): string { return $this->rate; }
    public function setRate(string $rate): self { $this->rate = $rate; return $this; }
}
