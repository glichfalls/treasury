<?php

namespace App\Price;

use App\Entity\Asset;
use App\Entity\FxRate;
use App\Entity\Price;
use App\Repository\AssetRepository;
use App\Repository\FxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PriceFetcher
{
    public function __construct(
        private readonly PriceProvider $provider,
        private readonly AssetRepository $assets,
        private readonly FxRateRepository $fxRates,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Refresh prices for the given assets (or all known assets if null).
     * Returns a per-asset success/skip summary.
     *
     * @param Asset[]|null $assets
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function refreshAssets(?array $assets = null): array
    {
        $assets ??= $this->assets->findAll();
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($assets as $asset) {
            try {
                $ticker = $asset->getTicker();
                if ($ticker === null || $ticker === '') {
                    $resolved = $this->provider->resolveTickerByIsin($asset->getIsin());
                    if ($resolved === null) {
                        $this->logger->info('No ticker resolved', ['isin' => $asset->getIsin()]);
                        $skipped++;
                        continue;
                    }
                    $asset->setTicker($resolved);
                    $ticker = $resolved;
                }

                $quote = $this->provider->fetchLatestPrice($ticker);
                if ($quote === null) {
                    $skipped++;
                    continue;
                }

                if ($asset->getCurrency() === null) {
                    $asset->setCurrency($quote->currency);
                }

                $this->storePrice($asset, $quote);
                $updated++;
            } catch (\Throwable $e) {
                $this->logger->error('Refresh failed', ['isin' => $asset->getIsin(), 'error' => $e->getMessage()]);
                $errors[] = $asset->getIsin() . ': ' . $e->getMessage();
            }
        }

        $this->em->flush();
        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Refresh FX rates needed to display assets in the requested base currencies.
     *
     * @param string[] $baseCurrencies
     */
    /**
     * Backfill historical daily prices for the given assets (or all known assets).
     * Bypasses the Doctrine unit of work and inserts via DBAL — clearing the EM mid-loop
     * detaches related Asset entities and trips a cascade-persist error.
     *
     * @param Asset[]|null $assets
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function backfillHistory(?array $assets = null, string $range = 'max'): array
    {
        $assets ??= $this->assets->findAll();
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $conn = $this->em->getConnection();

        foreach ($assets as $asset) {
            try {
                $ticker = $asset->getTicker();
                if ($ticker === null || $ticker === '') {
                    $ticker = $this->provider->resolveTickerByIsin($asset->getIsin());
                    if ($ticker === null) {
                        $skipped++;
                        continue;
                    }
                    $asset->setTicker($ticker);
                }

                $history = $this->provider->fetchPriceHistory($ticker, $range);
                if ($history === []) {
                    $skipped++;
                    continue;
                }

                if ($asset->getCurrency() === null && $history[0]->currency !== '') {
                    $asset->setCurrency($history[0]->currency);
                }

                $existing = $this->existingPriceDatesFor($asset);
                $assetBin = $asset->getId()->toBinary();
                $values = [];
                $params = [];
                foreach ($history as $quote) {
                    $key = $quote->asOf->format('Y-m-d');
                    if (isset($existing[$key])) {
                        continue;
                    }
                    $values[] = '(?, ?, ?, ?, ?)';
                    $params[] = \Symfony\Component\Uid\Uuid::v7()->toBinary();
                    $params[] = $assetBin;
                    $params[] = $key;
                    $params[] = (string) (int) round($quote->price * 100);
                    $params[] = $quote->currency;
                    $existing[$key] = true;
                }
                if ($values !== []) {
                    $conn->executeStatement(
                        'INSERT INTO prices (id, asset_id, occurred_at, price_minor, currency) VALUES '
                        . implode(', ', $values),
                        $params,
                    );
                }
                $updated++;
            } catch (\Throwable $e) {
                $this->logger->error('Backfill failed', ['isin' => $asset->getIsin(), 'error' => $e->getMessage()]);
                $errors[] = $asset->getIsin() . ': ' . $e->getMessage();
            }
        }

        // Flush any Asset.ticker / currency updates from the loop.
        $this->em->flush();
        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Backfill historical FX rates between asset currencies and the given base currencies.
     *
     * @param string[] $baseCurrencies
     * @param Asset[]|null $assets
     */
    public function backfillFxHistory(array $baseCurrencies, ?array $assets = null, string $range = 'max'): void
    {
        $assets ??= $this->assets->findAll();
        $pairs = [];
        foreach ($assets as $asset) {
            $c = $asset->getCurrency();
            if ($c === null) {
                continue;
            }
            foreach ($baseCurrencies as $base) {
                $base = strtoupper($base);
                if ($c === $base) {
                    continue;
                }
                $pairs[$c . '|' . $base] = [$c, $base];
            }
        }

        $conn = $this->em->getConnection();
        foreach ($pairs as [$from, $to]) {
            $history = $this->provider->fetchFxHistory($from, $to, $range);
            if ($history === []) {
                $this->logger->warning('FX history empty', ['from' => $from, 'to' => $to]);
                continue;
            }
            $existing = $this->existingFxDatesFor($from, $to);
            $values = [];
            $params = [];
            foreach ($history as $row) {
                $key = $row['date']->format('Y-m-d');
                if (isset($existing[$key])) {
                    continue;
                }
                $values[] = '(?, ?, ?, ?, ?)';
                $params[] = \Symfony\Component\Uid\Uuid::v7()->toBinary();
                $params[] = $key;
                $params[] = $from;
                $params[] = $to;
                $params[] = number_format($row['rate'], 8, '.', '');
                $existing[$key] = true;
            }
            if ($values !== []) {
                $conn->executeStatement(
                    'INSERT INTO fx_rates (id, occurred_at, from_currency, to_currency, rate) VALUES '
                    . implode(', ', $values),
                    $params,
                );
            }
        }
    }

    /** @return array<string, true> Map of YYYY-MM-DD => true for the asset's stored prices. */
    private function existingPriceDatesFor(Asset $asset): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT occurred_at FROM prices WHERE asset_id = :id',
            ['id' => $asset->getId()->toBinary()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['occurred_at']] = true;
        }
        return $out;
    }

    /** @return array<string, true> */
    private function existingFxDatesFor(string $from, string $to): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT occurred_at FROM fx_rates WHERE from_currency = :from AND to_currency = :to',
            ['from' => $from, 'to' => $to],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['occurred_at']] = true;
        }
        return $out;
    }

    public function refreshFxFor(array $baseCurrencies, ?array $assets = null): void
    {
        $assets ??= $this->assets->findAll();
        $needed = [];
        foreach ($assets as $asset) {
            $c = $asset->getCurrency();
            if ($c === null) {
                continue;
            }
            foreach ($baseCurrencies as $base) {
                $base = strtoupper($base);
                if ($c === $base) {
                    continue;
                }
                $needed[$c . '|' . $base] = [$c, $base];
            }
        }

        $today = (new \DateTimeImmutable())->setTime(0, 0);
        foreach ($needed as [$from, $to]) {
            $rate = $this->provider->fetchLatestFx($from, $to);
            if ($rate === null) {
                $this->logger->warning('FX fetch failed', ['from' => $from, 'to' => $to]);
                continue;
            }
            $rateStr = number_format($rate, 8, '.', '');

            $existing = $this->fxRates->findOneBy([
                'occurredAt' => $today,
                'fromCurrency' => $from,
                'toCurrency' => $to,
            ]);
            if ($existing !== null) {
                $existing->setRate($rateStr);
            } else {
                $entry = new FxRate();
                $entry->setOccurredAt($today);
                $entry->setFromCurrency($from);
                $entry->setToCurrency($to);
                $entry->setRate($rateStr);
                $this->em->persist($entry);
            }
            $this->em->flush();
        }
    }

    private function storePrice(Asset $asset, PriceQuote $quote): void
    {
        $priceMinor = (string) (int) round($quote->price * 100);

        $existing = $this->em->getRepository(Price::class)->findOneBy([
            'asset' => $asset,
            'occurredAt' => $quote->asOf,
        ]);
        if ($existing !== null) {
            $existing->setPriceMinor($priceMinor);
            $existing->setCurrency($quote->currency);
        } else {
            $price = new Price();
            $price->setAsset($asset);
            $price->setOccurredAt($quote->asOf);
            $price->setCurrency($quote->currency);
            $price->setPriceMinor($priceMinor);
            $this->em->persist($price);
        }
        $this->em->flush();
    }
}
