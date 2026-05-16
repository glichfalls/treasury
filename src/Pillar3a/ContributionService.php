<?php

namespace App\Pillar3a;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Repository\AccountAllocationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records a periodic contribution to a Pillar 3a account, automatically fanning the cash
 * out into fractional ETF purchases per the account's allocation rule. Prices are taken
 * from the latest stored Price ≤ contribution date; FX is applied if the asset's currency
 * differs from the account's.
 */
final class ContributionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountAllocationRepository $allocations,
        private readonly Connection $conn,
    ) {}

    /**
     * @return array{deposit: Transaction, trades: list<Transaction>, missingPrices: list<string>}
     */
    public function record(
        Account $account,
        \DateTimeImmutable $date,
        int $amountMinor,
        ?string $description = null,
        bool $isOpeningBalance = false,
    ): array {
        // Use the strategy effective on the contribution date — not today's — so back-
        // dated contributions reflect what the user actually held at that time.
        $allocations = $this->allocations->findEffective($account, $date);
        $totalBp = array_sum(array_map(fn($a) => $a->getPercentBasisPoints(), $allocations));
        if ($totalBp > 10000) {
            throw new \InvalidArgumentException(sprintf('Allocation exceeds 100 %% (sum: %.2f %%).', $totalBp / 100));
        }

        // The cash deposit always lands first. Opening-balance rows are tagged with
        // their own type so cash-flow charts can skip them (the money was earned
        // over years before the app existed, not income at the entry date).
        $deposit = new Transaction();
        $deposit->setAccount($account);
        $deposit->setOccurredAt($date);
        $deposit->setAmountMinor((string) $amountMinor);
        $deposit->setCurrency($account->getCurrency());
        $deposit->setDescription($description ?? 'Pillar 3a contribution');
        $deposit->setType($isOpeningBalance ? TransactionType::OpeningBalance : TransactionType::Deposit);
        $deposit->setSource(TransactionSource::Manual);
        $this->em->persist($deposit);

        $trades = [];
        $missingPrices = [];
        $base = $account->getCurrency();
        $dateStr = $date->format('Y-m-d');

        foreach ($allocations as $rule) {
            $slice = (int) round($amountMinor * $rule->getPercentBasisPoints() / 10000);
            if ($slice === 0) {
                continue;
            }
            $isin = $rule->getAssetIsin();
            $priceRow = $this->latestPriceOnOrBefore($isin, $dateStr);
            if ($priceRow === null) {
                $missingPrices[] = $isin;
                continue;
            }
            $priceMajor = ((int) $priceRow['price_minor']) / 100;
            $assetCcy = $priceRow['currency'];

            // Cash the trade consumes, in account currency. Convert price to account ccy
            // for the share-count math.
            $priceInBase = $priceMajor;
            if ($assetCcy !== $base) {
                $fx = $this->fxRateOnOrBefore($assetCcy, $base, $dateStr);
                if ($fx === null) {
                    $missingPrices[] = $assetCcy . '→' . $base;
                    continue;
                }
                $priceInBase = $priceMajor * $fx;
            }
            if ($priceInBase <= 0) {
                continue;
            }
            $sliceMajor = $slice / 100;
            $shares = $sliceMajor / $priceInBase;

            $trade = new Transaction();
            $trade->setAccount($account);
            $trade->setOccurredAt($date);
            $trade->setAmountMinor((string) (-$slice));
            $trade->setCurrency($base);
            $trade->setDescription(sprintf('3a allocation: %s', $isin));
            $trade->setType(TransactionType::TradeBuy);
            $trade->setSource(TransactionSource::Manual);
            $trade->setAssetIsin($isin);
            $trade->setAssetQuantity(number_format($shares, 8, '.', ''));
            $this->em->persist($trade);
            $trades[] = $trade;
        }

        $this->em->flush();

        return ['deposit' => $deposit, 'trades' => $trades, 'missingPrices' => array_values(array_unique($missingPrices))];
    }

    /**
     * Latest price ≤ the contribution date. Falls back to the earliest stored price if the
     * contribution predates all stored prices — common for "opening balance" entries where
     * we don't have backfilled history for an ETF yet. The exact share count is then
     * computed from today's price, which is what the OpeningBalanceForm tells the user
     * will happen.
     *
     * @return array{price_minor: string, currency: string}|null
     */
    private function latestPriceOnOrBefore(string $isin, string $date): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT p.price_minor, p.currency FROM prices p
             INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin = :isin AND p.occurred_at <= :date
             ORDER BY p.occurred_at DESC LIMIT 1',
            ['isin' => $isin, 'date' => $date],
        );
        if ($row !== false) {
            return ['price_minor' => $row['price_minor'], 'currency' => $row['currency']];
        }
        $fallback = $this->conn->fetchAssociative(
            'SELECT p.price_minor, p.currency FROM prices p
             INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin = :isin
             ORDER BY p.occurred_at ASC LIMIT 1',
            ['isin' => $isin],
        );
        return $fallback === false ? null : ['price_minor' => $fallback['price_minor'], 'currency' => $fallback['currency']];
    }

    private function fxRateOnOrBefore(string $from, string $to, string $date): ?float
    {
        $row = $this->conn->fetchAssociative(
            'SELECT rate FROM fx_rates
             WHERE from_currency = :from AND to_currency = :to AND occurred_at <= :date
             ORDER BY occurred_at DESC LIMIT 1',
            ['from' => $from, 'to' => $to, 'date' => $date],
        );
        if ($row !== false) {
            return (float) $row['rate'];
        }
        // Same fallback as prices: if the deposit predates any stored FX, use the earliest.
        $fallback = $this->conn->fetchAssociative(
            'SELECT rate FROM fx_rates
             WHERE from_currency = :from AND to_currency = :to
             ORDER BY occurred_at ASC LIMIT 1',
            ['from' => $from, 'to' => $to],
        );
        return $fallback === false ? null : (float) $fallback['rate'];
    }
}
