<?php

namespace App\Entity;

use App\Repository\AssetNewsSourceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A user-curated, asset-specific news source: an RSS/Atom feed or a website
 * crawled for that holding. Fanned out per asset by CustomFeedProvider during
 * the regular news refresh, the same as any other provider — these just come
 * from config instead of being hard-coded.
 */
#[ORM\Entity(repositoryClass: AssetNewsSourceRepository::class)]
#[ORM\Table(name: 'asset_news_sources')]
#[ORM\Index(name: 'idx_ans_asset', columns: ['asset_id'])]
class AssetNewsSource
{
    /** A real feed parsed directly (the URL was, or resolved to, RSS/Atom). */
    public const MODE_FEED = 'feed';
    /** A feedless site read by the heuristic HTML scraper. */
    public const MODE_SCRAPE = 'scrape';

    public const TYPE_RSS = 'rss';
    public const TYPE_ATOM = 'atom';
    public const TYPE_WEBSITE = 'website';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Asset::class, inversedBy: 'newsSources')]
    #[ORM\JoinColumn(nullable: false)]
    private Asset $asset;

    /** The URL the user entered (a feed, or a website to discover/scrape). */
    #[ORM\Column(length: 1024)]
    private string $url;

    /** One of the TYPE_* constants, detected when the source is added. */
    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_WEBSITE;

    /**
     * Resolved feed URL when {@see $url} was a website carrying a feed
     * (autodiscovery). Null for direct feeds and for scraped sites — the fetch
     * target is feedUrl ?? url.
     */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $feedUrl = null;

    /** MODE_FEED (structured) or MODE_SCRAPE (heuristic HTML extraction). */
    #[ORM\Column(length: 16)]
    private string $scrapeMode = self::MODE_FEED;

    /** Display label (feed/site title or user-given); becomes NewsItem.publisher. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /**
     * Whether AI processing (deep brief, digest inclusion) runs for this
     * source's articles. Gated under the global NEWS_CUSTOM_AI master switch.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $aiEnabled = true;

    /** HTTP validators for conditional GETs, so unchanged feeds aren't re-parsed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastModified = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /** Outcome of the last fetch ('ok', 'no items found', 'error: …') — shown in admin. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastStatus = null;

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
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = mb_substr(trim($url), 0, 1024); return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getFeedUrl(): ?string { return $this->feedUrl; }
    public function setFeedUrl(?string $feedUrl): self { $this->feedUrl = $feedUrl !== null ? mb_substr($feedUrl, 0, 1024) : null; return $this; }
    public function getScrapeMode(): string { return $this->scrapeMode; }
    public function setScrapeMode(string $mode): self { $this->scrapeMode = $mode; return $this; }
    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 200) : null; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function isAiEnabled(): bool { return $this->aiEnabled; }
    public function setAiEnabled(bool $aiEnabled): self { $this->aiEnabled = $aiEnabled; return $this; }
    public function getEtag(): ?string { return $this->etag; }
    public function setEtag(?string $etag): self { $this->etag = $etag !== null ? mb_substr($etag, 0, 255) : null; return $this; }
    public function getLastModified(): ?string { return $this->lastModified; }
    public function setLastModified(?string $lastModified): self { $this->lastModified = $lastModified !== null ? mb_substr($lastModified, 0, 255) : null; return $this; }
    public function getLastFetchedAt(): ?\DateTimeImmutable { return $this->lastFetchedAt; }
    public function setLastFetchedAt(?\DateTimeImmutable $at): self { $this->lastFetchedAt = $at; return $this; }
    public function getLastStatus(): ?string { return $this->lastStatus; }
    public function setLastStatus(?string $status): self { $this->lastStatus = $status !== null ? mb_substr($status, 0, 255) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** The URL to actually fetch: the resolved feed when present, else the entered URL. */
    public function fetchTarget(): string
    {
        return $this->feedUrl ?? $this->url;
    }
}
