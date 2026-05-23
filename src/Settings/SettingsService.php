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
