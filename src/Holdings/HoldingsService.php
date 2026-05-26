<?php

namespace App\Holdings;

use App\Entity\Account;
use App\Price\PreMarketService;
use App\Repository\AssetRepository;
use App\Repository\FxRateRepository;
use App\Repository\PriceRepository;
use Doctrine\ORM\EntityManagerInterface;

final class HoldingsService
{
    /** Synthetic ISIN for the gold spot reference price. */
    public const SPOT_GOLD_ISIN = 'SPOT:XAUUSD';

    /** Troy ounce in grams. */
    private const TROY_OUNCE_GRAMS = 31.1034768;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetRepository $assets,
        private readonly PriceRepository $prices,
        private readonly FxRateRepository $fxRates,
        private readonly PreMarketService $preMarket,
    ) {}

    /** @return Holding[] */
    public function forAccount(Account $account): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT asset_isin, SUM(asset_quantity) AS qty
             FROM transactions
             WHERE account_id = :id AND asset_isin IS NOT NULL AND asset_quantity IS NOT NULL
             GROUP BY asset_isin
             HAVING SUM(asset_quantity) <> 0',
            ['id' => $account->getId()->toBinary()],
        );

        if ($rows === []) {
            return [];
        }

        $isins = array_column($rows, 'asset_isin');
        $assetsByIsin = [];
        foreach ($this->assets->findBy(['isin' => $isins]) as $asset) {
            $assetsByIsin[$asset->getIsin()] = $asset;
        }

        $assetIds = array_map(fn($a) => $a->getId(), $assetsByIsin);
        $latestPrices = $this->prices->findLatestByAssetIds($assetIds);
        $lastTwoByAssetId = $this->prices->findLatestTwoByAssetIds($assetIds);

        // Spot price is needed if any of our held assets are commodity-backed.
        $spotAsset = $this->assets->findByIsin(self::SPOT_GOLD_ISIN);
        $spotPrice = $spotAsset !== null ? $this->prices->findLatest($spotAsset) : null;
        // Commodity coins derive their price from the gold spot — so their day
        // change is just the spot's day change (the grams × premium factor is a
        // positive constant that cancels out).
        $spotDayChange = $spotAsset !== null
            ? $this->dayChangePctFor($lastTwoByAssetId[$spotAsset->getId()->toRfc4122()] ?? [])
            : null;

        // Fetch pre-market quotes in parallel for all market assets with a ticker.
        $preMarketTickers = [];
        foreach ($assetsByIsin as $asset) {
            if ($asset->getUnitWeightGrams() === null && $asset->getTicker() !== null) {
                $preMarketTickers[] = $asset->getTicker();
            }
        }
        $preMarketQuotes = $this->preMarket->getQuotes($preMarketTickers);

        $base = $account->getCurrency();
        $holdings = [];
        foreach ($rows as $r) {
            $asset = $assetsByIsin[$r['asset_isin']] ?? null;
            $qty = (string) $r['qty'];

            $previousPriceMinor = null;
            $dayChangePct = null;

            if ($asset !== null && $asset->getUnitWeightGrams() !== null) {
                // Commodity-backed asset (e.g. gold coin): value = qty × grams ×
                // spotPerGram × (1 + premium) × FX.
                [$priceCurrency, $priceMinor, $priceAsOf, $valueBaseMinor] =
                    $this->valuateCommodity($asset, $qty, $spotPrice, $base);
                $dayChangePct = $spotDayChange;
            } else {
                [$priceCurrency, $priceMinor, $priceAsOf, $valueBaseMinor] =
                    $this->valuateMarketAsset($asset, $qty, $latestPrices, $base);
                if ($asset !== null) {
                    $two = $lastTwoByAssetId[$asset->getId()->toRfc4122()] ?? [];
                    $dayChangePct = $this->dayChangePctFor($two);
                    $previousPriceMinor = $two[1]['priceMinor'] ?? null;
                }
            }

            $ticker = $asset?->getTicker();
            $pmq = ($ticker !== null) ? ($preMarketQuotes[$ticker] ?? null) : null;

            $holdings[] = new Holding(
                isin: $r['asset_isin'],
                ticker: $ticker,
                name: $asset?->getName(),
                quantity: $qty,
                priceCurrency: $priceCurrency,
                priceMinor: $priceMinor,
                priceAsOf: $priceAsOf,
                valueBaseMinor: $valueBaseMinor,
                baseCurrency: $base,
                previousPriceMinor: $previousPriceMinor,
                dayChangePct: $dayChangePct,
                preMarketPriceMinor: $pmq !== null ? (string) $pmq->priceMinor : null,
                preMarketChangePct: $pmq?->changePct,
            );
        }

        usort($holdings, fn(Holding $a, Holding $b) => ($b->valueBaseMinor === null ? -1 : (int) $b->valueBaseMinor)
            <=> ($a->valueBaseMinor === null ? -1 : (int) $a->valueBaseMinor));

        return $holdings;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} currency, priceMinor, priceAsOf, valueBaseMinor
     */
    private function valuateMarketAsset(?\App\Entity\Asset $asset, string $qty, array $latestPrices, string $base): array
    {
        $price = $asset !== null ? ($latestPrices[$asset->getId()->toRfc4122()] ?? null) : null;
        if ($price === null) {
            return [null, null, null, null];
        }
        $priceMajor = (float) $price->getPriceMinor() / 100;
        $valueNative = (float) $qty * $priceMajor;
        $fx = 1.0;
        if ($price->getCurrency() !== $base) {
            $rate = $this->fxRates->findLatest($price->getCurrency(), $base);
            $fx = $rate !== null ? (float) $rate->getRate() : 0.0;
        }
        $valueBaseMinor = $fx > 0 ? (string) (int) round($valueNative * $fx * 100) : null;
        return [
            $price->getCurrency(),
            $price->getPriceMinor(),
            $price->getOccurredAt()->format('Y-m-d'),
            $valueBaseMinor,
        ];
    }

    /**
     * Derive a coin's per-unit price from the gold spot, apply the catalog premium,
     * then multiply by quantity and convert to display currency.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function valuateCommodity(
        \App\Entity\Asset $asset,
        string $qty,
        ?\App\Entity\Price $spotPrice,
        string $base,
    ): array {
        if ($spotPrice === null) {
            return [null, null, null, null];
        }
        $grams = (float) $asset->getUnitWeightGrams();
        $premium = (float) ($asset->getPricePremiumPct() ?? '0') / 100;
        $spotPerOz = (float) $spotPrice->getPriceMinor() / 100;
        $spotPerGram = $spotPerOz / self::TROY_OUNCE_GRAMS;
        $perUnitNative = $spotPerGram * $grams * (1 + $premium);
        $valueNative = (float) $qty * $perUnitNative;

        $fx = 1.0;
        if ($spotPrice->getCurrency() !== $base) {
            $rate = $this->fxRates->findLatest($spotPrice->getCurrency(), $base);
            $fx = $rate !== null ? (float) $rate->getRate() : 0.0;
        }
        $valueBaseMinor = $fx > 0 ? (string) (int) round($valueNative * $fx * 100) : null;

        return [
            $spotPrice->getCurrency(),
            (string) (int) round($perUnitNative * 100),
            $spotPrice->getOccurredAt()->format('Y-m-d'),
            $valueBaseMinor,
        ];
    }

    /**
     * Compute day-over-day % change from a list of "latest two" price rows.
     *
     * @param list<array{priceMinor: string, currency: string, occurredAt: string}> $lastTwo
     */
    private function dayChangePctFor(array $lastTwo): ?float
    {
        if (count($lastTwo) < 2) {
            return null;
        }
        $latest = (float) $lastTwo[0]['priceMinor'];
        $prev = (float) $lastTwo[1]['priceMinor'];
        if ($prev === 0.0) {
            return null;
        }
        return ($latest - $prev) / $prev * 100;
    }

    /** Sum of holding values in the account's base currency. Returns null if any holding lacks a price. */
    public function totalValueMinor(Account $account, array $holdings): string
    {
        $total = 0;
        foreach ($holdings as $h) {
            if ($h->valueBaseMinor !== null) {
                $total += (int) $h->valueBaseMinor;
            }
        }
        return (string) $total;
    }
}
