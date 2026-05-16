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
     * Refresh prices for the given assets (or all known assets if null). The gold spot
     * reference asset is always included even if not in the provided list, so commodity-
     * backed coin valuations stay current.
     *
     * @param Asset[]|null $assets
     * @return array{updated: int, skipped: int, errors: string[]}
     */
    public function refreshAssets(?array $assets = null): array
    {
        $assets ??= $this->assets->findAll();
        $spot = $this->assets->findByIsin(\App\Holdings\HoldingsService::SPOT_GOLD_ISIN);
        if ($spot !== null) {
            $haveSpot = false;
            foreach ($assets as $a) {
                if ($a->getIsin() === $spot->getIsin()) {
                    $haveSpot = true;
                    break;
                }
            }
            if (!$haveSpot) {
                $assets[] = $spot;
            }
        }
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
                // Fill in the human name from Yahoo if we don't have one yet — keeps
                // strategy/allocation UIs readable.
                if ($asset->getName() === null && $quote->name !== null) {
                    $asset->setName($quote->name);
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
                if ($asset->getName() === null && $history[0]->name !== null) {
                    $asset->setName($history[0]->name);
                }

                $existing = $this->existingPricesFor($asset);
                $assetBin = $asset->getId()->toBinary();
                $values = [];
                $params = [];
                foreach ($history as $quote) {
                    $key = $quote->asOf->format('Y-m-d');
                    if (isset($existing[$key])) {
                        // Upgrade an intraday row to the official close when
                        // Yahoo can now give us one. Never downgrade a close
                        // back to an intraday print.
                        if (!$existing[$key] && $quote->isClose) {
                            $conn->executeStatement(
                                'UPDATE prices SET price_minor = ?, currency = ?, is_close = 1 '
                                . 'WHERE asset_id = ? AND occurred_at = ?',
                                [
                                    (string) (int) round($quote->price * 100),
                                    $quote->currency,
                                    $assetBin,
                                    $key,
                                ],
                            );
                            $existing[$key] = true;
                        }
                        continue;
                    }
                    $values[] = '(?, ?, ?, ?, ?, ?)';
                    $params[] = \Symfony\Component\Uid\Uuid::v7()->toBinary();
                    $params[] = $assetBin;
                    $params[] = $key;
                    $params[] = (string) (int) round($quote->price * 100);
                    $params[] = $quote->currency;
                    $params[] = $quote->isClose ? 1 : 0;
                    $existing[$key] = $quote->isClose;
                }
                if ($values !== []) {
                    $conn->executeStatement(
                        'INSERT INTO prices (id, asset_id, occurred_at, price_minor, currency, is_close) VALUES '
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
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $values = [];
            $params = [];
            foreach ($history as $row) {
                $key = $row['date']->format('Y-m-d');
                $rateStr = number_format($row['rate'], 8, '.', '');
                if (isset($existing[$key])) {
                    // Past-date rates are finalized once the trading day is
                    // done; overwrite any stale or glitched value we may have
                    // captured intraday. Leave today's row to refreshFxFor so
                    // we don't ping-pong between two writes in the same run.
                    if ($key !== $today) {
                        $conn->executeStatement(
                            'UPDATE fx_rates SET rate = ? '
                            . 'WHERE from_currency = ? AND to_currency = ? AND occurred_at = ?',
                            [$rateStr, $from, $to, $key],
                        );
                    }
                    continue;
                }
                $values[] = '(?, ?, ?, ?, ?)';
                $params[] = \Symfony\Component\Uid\Uuid::v7()->toBinary();
                $params[] = $key;
                $params[] = $from;
                $params[] = $to;
                $params[] = $rateStr;
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
    /**
     * Map of YYYY-MM-DD => isClose for each price row of an asset.
     *
     * @return array<string, bool>
     */
    private function existingPricesFor(Asset $asset): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT occurred_at, is_close FROM prices WHERE asset_id = :id',
            ['id' => $asset->getId()->toBinary()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['occurred_at']] = (bool) $r['is_close'];
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
            // Don't downgrade a locked-in close back to an intraday print: once
            // we have the final close for a day, the next intraday refresh
            // (e.g. an admin clicking "Reload prices" the next morning before
            // the new session opens) must not stomp on it.
            if ($existing->isClose() && !$quote->isClose) {
                return;
            }
            $existing->setPriceMinor($priceMinor);
            $existing->setCurrency($quote->currency);
            $existing->setIsClose($quote->isClose);
        } else {
            $price = new Price();
            $price->setAsset($asset);
            $price->setOccurredAt($quote->asOf);
            $price->setCurrency($quote->currency);
            $price->setPriceMinor($priceMinor);
            $price->setIsClose($quote->isClose);
            $this->em->persist($price);
        }
        $this->em->flush();
    }
}
