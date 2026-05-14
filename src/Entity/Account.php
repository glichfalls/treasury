<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $institution = null;

    #[ORM\Column(length: 32, enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: Transaction::class, cascade: ['remove'])]
    private Collection $transactions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->transactions = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): self { $this->owner = $owner; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getInstitution(): ?string { return $this->institution; }
    public function setInstitution(?string $institution): self { $this->institution = $institution; return $this; }
    public function getType(): AccountType { return $this->type; }
    public function setType(AccountType $type): self { $this->type = $type; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
