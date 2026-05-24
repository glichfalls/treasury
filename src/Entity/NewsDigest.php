<?php

namespace App\Entity;

use App\Repository\NewsDigestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An AI-generated briefing summarising news across a user's holdings since
 * their last briefing. One row per generation; the UI shows the latest.
 */
#[ORM\Entity(repositoryClass: NewsDigestRepository::class)]
#[ORM\Table(name: 'news_digests')]
#[ORM\Index(name: 'idx_news_digests_owner_generated', columns: ['owner_id', 'generated_at'])]
class NewsDigest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodEnd;

    /** Markdown briefing. */
    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'integer')]
    private int $itemCount = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): self { $this->owner = $owner; return $this; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $d): self { $this->periodStart = $d; return $this; }
    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $d): self { $this->periodEnd = $d; return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): self { $this->content = $content; return $this; }
    public function getItemCount(): int { return $this->itemCount; }
    public function setItemCount(int $n): self { $this->itemCount = $n; return $this; }
}
