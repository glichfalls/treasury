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
        $this->fetcher->refreshAssets();
        $baseCurrencies = array_values(array_unique(array_map(
            fn($a) => $a->getCurrency(),
            $this->accounts->findAll(),
        )));
        $this->fetcher->refreshFxFor($baseCurrencies);
    }
}
