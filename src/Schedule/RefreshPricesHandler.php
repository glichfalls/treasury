<?php

namespace App\Schedule;

use App\Price\PriceFetcher;
use App\Repository\AccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshPricesHandler
{
    public function __construct(
        private readonly PriceFetcher $fetcher,
        private readonly AccountRepository $accounts,
    ) {}

    public function __invoke(RefreshPricesMessage $message): void
    {
        $baseCurrencies = array_values(array_unique(array_map(
            fn($a) => $a->getCurrency(),
            $this->accounts->findAll(),
        )));

        $this->fetcher->refreshAssets();
        $this->fetcher->refreshFxFor($baseCurrencies);

        // Fill in any daily prices and FX rates we missed in the last month
        // (e.g. server downtime, missed scheduler ticks). Backfill is
        // date-deduplicated, so this only writes the gaps.
        $this->fetcher->backfillHistory(null, '1mo');
        $this->fetcher->backfillFxHistory($baseCurrencies, null, '1mo');
    }
}
