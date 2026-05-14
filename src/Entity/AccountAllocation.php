<?php

namespace App\Entity;

use App\Repository\AccountAllocationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One ISIN-percent line of an account's allocation, valid from $effectiveFrom onward
 * until a later batch supersedes it. New rule sets don't delete old ones — the history
 * is preserved so back-dated contributions still use whichever strategy was in effect
 * on their date.
 */
#[ORM\Entity(repositoryClass: AccountAllocationRepository::class)]
#[ORM\Table(name: 'account_allocations')]
#[ORM\Index(name: 'idx_alloc_account_date', columns: ['account_id', 'effective_from'])]
class AccountAllocation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(length: 32, name: 'asset_isin')]
    private string $assetIsin;

    /** 0–10000 basis points (i.e. 0–100 % with two-decimal precision). */
    #[ORM\Column(type: 'integer')]
    private int $percentBasisPoints;

    #[ORM\Column(type: 'date_immutable', name: 'effective_from')]
    private \DateTimeImmutable $effectiveFrom;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->effectiveFrom = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid { return $this->id; }
    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $account): self { $this->account = $account; return $this; }
    public function getAssetIsin(): string { return $this->assetIsin; }
    public function setAssetIsin(string $isin): self { $this->assetIsin = strtoupper($isin); return $this; }
    public function getPercentBasisPoints(): int { return $this->percentBasisPoints; }
    public function setPercentBasisPoints(int $bp): self { $this->percentBasisPoints = $bp; return $this; }
    public function getEffectiveFrom(): \DateTimeImmutable { return $this->effectiveFrom; }
    public function setEffectiveFrom(\DateTimeImmutable $d): self { $this->effectiveFrom = $d; return $this; }

    public function getPercent(): float { return $this->percentBasisPoints / 100; }
}
