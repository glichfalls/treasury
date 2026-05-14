<?php

namespace App\Holdings;

use App\Entity\Account;
use App\Repository\AssetRepository;
use App\Repository\FxRateRepository;
use App\Repository\PriceRepository;
use Doctrine\ORM\EntityManagerInterface;

final class HoldingsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetRepository $assets,
        private readonly PriceRepository $prices,
        private readonly FxRateRepository $fxRates,
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

        $base = $account->getCurrency();
        $holdings = [];
        foreach ($rows as $r) {
            $asset = $assetsByIsin[$r['asset_isin']] ?? null;
            $qty = (string) $r['qty'];
            $price = $asset !== null ? ($latestPrices[$asset->getId()->toRfc4122()] ?? null) : null;

            $valueBaseMinor = null;
            if ($price !== null && $asset !== null) {
                $priceMajor = (float) $price->getPriceMinor() / 100;
                $valueNative = (float) $qty * $priceMajor;
                $fx = 1.0;
                if ($price->getCurrency() !== $base) {
                    $rate = $this->fxRates->findLatest($price->getCurrency(), $base);
                    $fx = $rate !== null ? (float) $rate->getRate() : 0.0;
                }
                if ($fx > 0) {
                    $valueBaseMinor = (string) (int) round($valueNative * $fx * 100);
                }
            }

            $holdings[] = new Holding(
                isin: $r['asset_isin'],
                ticker: $asset?->getTicker(),
                name: $asset?->getName(),
                quantity: $qty,
                priceCurrency: $price?->getCurrency(),
                priceMinor: $price?->getPriceMinor(),
                priceAsOf: $price?->getOccurredAt()->format('Y-m-d'),
                valueBaseMinor: $valueBaseMinor,
                baseCurrency: $base,
            );
        }

        usort($holdings, fn(Holding $a, Holding $b) => ($b->valueBaseMinor === null ? -1 : (int) $b->valueBaseMinor)
            <=> ($a->valueBaseMinor === null ? -1 : (int) $a->valueBaseMinor));

        return $holdings;
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
