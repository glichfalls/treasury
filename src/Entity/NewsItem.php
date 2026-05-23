<?php

namespace App\Entity;

use App\Repository\NewsItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NewsItemRepository::class)]
#[ORM\Table(name: 'news_items')]
#[ORM\UniqueConstraint(name: 'uniq_news_asset_content', columns: ['asset_id', 'content_hash'])]
#[ORM\Index(name: 'idx_news_asset_published', columns: ['asset_id', 'published_at'])]
#[ORM\Index(name: 'idx_news_published', columns: ['published_at'])]
class NewsItem
{
    /** Article from a news wire / aggregator. */
    public const KIND_HEADLINE = 'headline';
    /** Structured analyst upgrade/downgrade event. */
    public const KIND_ANALYST_ACTION = 'analyst_action';
    /** Social chatter (Reddit, StockTwits, …). */
    public const KIND_SOCIAL = 'social';

    public const SENTIMENT_BULLISH = 'bullish';
    public const SENTIMENT_BEARISH = 'bearish';
    public const SENTIMENT_NEUTRAL = 'neutral';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Asset $asset;

    /** Origin of the item, e.g. 'yahoo', 'finnhub', 'reddit'. */
    #[ORM\Column(length: 32)]
    private string $source;

    /** One of the KIND_* constants. */
    #[ORM\Column(length: 16, options: ['default' => self::KIND_HEADLINE])]
    private string $kind = self::KIND_HEADLINE;

    #[ORM\Column(length: 512)]
    private string $title;

    #[ORM\Column(length: 1024)]
    private string $url;

    /** Publisher/author name, when the source provides one. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $publisher = null;

    /** Raw excerpt/description from the source, if any. Seeds the AI summary. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $snippet = null;

    /** AI-generated one-line summary; null until classified. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    /** One of the SENTIMENT_* constants; null until classified. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $sentiment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $publishedAt;

    /**
     * Per-asset de-duplication key (sha256 of the normalized URL). The unique
     * (asset_id, content_hash) constraint makes re-fetches idempotent while
     * still letting one article attach to multiple holdings it mentions.
     */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAsset(): Asset { return $this->asset; }
    public function setAsset(Asset $asset): self { $this->asset = $asset; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): self { $this->kind = $kind; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = mb_substr($title, 0, 512); return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = mb_substr($url, 0, 1024); return $this; }
    public function getPublisher(): ?string { return $this->publisher; }
    public function setPublisher(?string $publisher): self { $this->publisher = $publisher; return $this; }
    public function getSnippet(): ?string { return $this->snippet; }
    public function setSnippet(?string $snippet): self { $this->snippet = $snippet; return $this; }
    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): self { $this->summary = $summary; return $this; }
    public function getSentiment(): ?string { return $this->sentiment; }
    public function setSentiment(?string $sentiment): self { $this->sentiment = $sentiment; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
    public function getContentHash(): string { return $this->contentHash; }
    public function setContentHash(string $contentHash): self { $this->contentHash = $contentHash; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
