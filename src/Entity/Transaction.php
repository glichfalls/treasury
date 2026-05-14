<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(name: 'idx_transactions_account_date', columns: ['account_id', 'occurred_at'])]
#[ORM\UniqueConstraint(name: 'uniq_transactions_account_extref', columns: ['account_id', 'external_ref'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'bigint')]
    private string $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: TransactionType::class)]
    private TransactionType $type = TransactionType::Other;

    #[ORM\Column(length: 16, enumType: TransactionSource::class)]
    private TransactionSource $source = TransactionSource::Manual;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(length: 32, nullable: true, name: 'asset_isin')]
    private ?string $assetIsin = null;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 8, nullable: true)]
    private ?string $assetQuantity = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $account): self { $this->account = $account; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self { $this->occurredAt = $occurredAt; return $this; }
    public function getAmountMinor(): string { return $this->amountMinor; }
    public function setAmountMinor(string|int $amountMinor): self { $this->amountMinor = (string) $amountMinor; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getType(): TransactionType { return $this->type; }
    public function setType(TransactionType $type): self { $this->type = $type; return $this; }
    public function getSource(): TransactionSource { return $this->source; }
    public function setSource(TransactionSource $source): self { $this->source = $source; return $this; }
    public function getExternalRef(): ?string { return $this->externalRef; }
    public function setExternalRef(?string $externalRef): self { $this->externalRef = $externalRef; return $this; }
    public function getAssetIsin(): ?string { return $this->assetIsin; }
    public function setAssetIsin(?string $assetIsin): self { $this->assetIsin = $assetIsin !== null ? strtoupper($assetIsin) : null; return $this; }
    public function getAssetQuantity(): ?string { return $this->assetQuantity; }
    public function setAssetQuantity(?string $assetQuantity): self { $this->assetQuantity = $assetQuantity; return $this; }
}
