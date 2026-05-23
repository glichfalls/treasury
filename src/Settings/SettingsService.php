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
    public const OPENAI_API_KEY = 'openai_api_key';
    public const REDDIT_CLIENT_ID = 'reddit_client_id';
    public const REDDIT_CLIENT_SECRET = 'reddit_client_secret';
    /** Comma-separated list of market-wide subreddits to search per holding. */
    public const REDDIT_BROAD_SUBREDDITS = 'reddit_broad_subreddits';

    public const DEFAULT_BROAD_SUBREDDITS = ['wallstreetbets', 'stocks', 'investing', 'trading', 'StockMarket'];

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
