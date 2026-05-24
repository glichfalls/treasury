<?php

namespace App\Settings;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read/write access to admin-managed application settings (provider API keys
 * etc.), backed by the app_settings table. Reads are memoized per request so
 * providers can call get() freely. This is where keys live instead of .env.
 */
final class SettingsService
{
    /** Known setting keys, so callers don't pass around magic strings. */
    public const FINNHUB_API_KEY = 'finnhub_api_key';
    public const MARKETAUX_API_TOKEN = 'marketaux_api_token';
    public const FMP_API_KEY = 'fmp_api_key';
    public const OPENAI_API_KEY = 'openai_api_key';
    /** Comma-separated list of market-wide subreddits to search per holding. */
    public const REDDIT_BROAD_SUBREDDITS = 'reddit_broad_subreddits';
    /** Comma-separated provider source keys that are switched off. */
    public const NEWS_DISABLED_SOURCES = 'news_disabled_sources';
    /** Fetch volume per holding: low | medium | high. */
    public const NEWS_VOLUME = 'news_volume';
    /** Master switch for AI processing of custom-source articles ('1' = on). */
    public const NEWS_CUSTOM_AI = 'news_custom_ai';

    public const DEFAULT_BROAD_SUBREDDITS = ['wallstreetbets', 'stocks', 'investing', 'trading', 'StockMarket'];

    /** Articles fetched per holding per source, by volume level. */
    private const VOLUME_LIMITS = ['low' => 3, 'medium' => 8, 'high' => 20];

    /** @var array<string, ?string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly AppSettingRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function get(string $name): ?string
    {
        $this->load();
        $value = $this->cache[$name] ?? null;
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * The configured market-wide subreddits, falling back to the defaults.
     *
     * @return string[]
     */
    public function getRedditBroadSubreddits(): array
    {
        $raw = $this->get(self::REDDIT_BROAD_SUBREDDITS);
        if ($raw === null) {
            return self::DEFAULT_BROAD_SUBREDDITS;
        }
        $subs = array_values(array_filter(array_map(
            static fn(string $s) => (string) preg_replace('#^/?r/#i', '', trim($s)),
            explode(',', $raw),
        )));
        return $subs !== [] ? $subs : self::DEFAULT_BROAD_SUBREDDITS;
    }

    /** @return string[] Provider source keys the admin has switched off. */
    public function getDisabledSources(): array
    {
        $raw = $this->get(self::NEWS_DISABLED_SOURCES);
        if ($raw === null) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(string $s) => strtolower(trim($s)),
            explode(',', $raw),
        )));
    }

    public function isSourceEnabled(string $source): bool
    {
        return !in_array(strtolower($source), $this->getDisabledSources(), true);
    }

    /**
     * Master switch for AI processing (deep briefs, digest inclusion) of
     * custom-source articles. Defaults on, so curated feeds get the same AI
     * treatment as built-in sources unless an admin turns it off; per-source
     * aiEnabled toggles refine it under this switch.
     */
    public function isCustomNewsAiEnabled(): bool
    {
        $v = $this->get(self::NEWS_CUSTOM_AI);
        return $v === null || ($v !== '0' && strtolower($v) !== 'false' && strtolower($v) !== 'off');
    }

    public function getNewsVolume(): string
    {
        $v = $this->get(self::NEWS_VOLUME);
        return $v !== null && isset(self::VOLUME_LIMITS[$v]) ? $v : 'medium';
    }

    /** Articles to fetch per holding per source for the configured volume. */
    public function getNewsVolumeLimit(): int
    {
        return self::VOLUME_LIMITS[$this->getNewsVolume()];
    }

    /**
     * Upsert a value. A null or empty string clears the setting.
     */
    public function set(string $name, ?string $value): void
    {
        $value = $value !== null && trim($value) !== '' ? trim($value) : null;

        $setting = $this->repo->findByName($name);
        if ($setting === null) {
            $setting = new AppSetting($name);
            $this->em->persist($setting);
        }
        $setting->setValue($value);
        $this->em->flush();

        if ($this->cache !== null) {
            $this->cache[$name] = $value;
        }
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->repo->findAll() as $setting) {
            $this->cache[$setting->getName()] = $setting->getValue();
        }
    }
}
