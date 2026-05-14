<?php

namespace App\Import;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Upserts Asset rows from import drafts, keyed by ISIN. Caches in-memory per
 * import run so we don't hammer the repository for every transaction.
 */
final class AssetUpserter
{
    /** @var array<string, Asset> */
    private array $cache = [];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly EntityManagerInterface $em,
    ) {}

    public function upsert(TransactionDraft $draft): ?Asset
    {
        if ($draft->assetIsin === null || $draft->assetIsin === '') {
            return null;
        }
        $isin = strtoupper($draft->assetIsin);
        if (isset($this->cache[$isin])) {
            $asset = $this->cache[$isin];
        } else {
            $asset = $this->assets->findByIsin($isin) ?? (new Asset())->setIsin($isin);
            $this->cache[$isin] = $asset;
        }

        // Fill in metadata if we have hints and don't already have a value.
        if ($draft->assetTicker !== null && $asset->getTicker() === null) {
            $asset->setTicker($draft->assetTicker);
        }
        if ($draft->assetName !== null && $asset->getName() === null) {
            $asset->setName($draft->assetName);
        }

        if (!$this->em->contains($asset)) {
            $this->em->persist($asset);
        }
        return $asset;
    }

    /** Reset the in-memory cache (e.g. between separate import runs in long-lived processes). */
    public function reset(): void
    {
        $this->cache = [];
    }
}
