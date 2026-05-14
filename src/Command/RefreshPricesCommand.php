<?php

namespace App\Command;

use App\Price\PriceFetcher;
use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prices:refresh',
    description: 'Refresh prices for all tracked assets and FX rates for held currencies',
)]
class RefreshPricesCommand extends Command
{
    public function __construct(
        private readonly PriceFetcher $fetcher,
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Asset prices');
        $result = $this->fetcher->refreshAssets();
        $io->writeln(sprintf(
            'Updated: <info>%d</info>, skipped: <comment>%d</comment>, errors: %d',
            $result['updated'],
            $result['skipped'],
            count($result['errors']),
        ));
        foreach ($result['errors'] as $err) {
            $io->writeln('  ! ' . $err);
        }

        $io->section('FX rates');
        $baseCurrencies = array_values(array_unique(array_map(
            fn($a) => $a->getCurrency(),
            $this->accounts->findAll(),
        )));
        $this->fetcher->refreshFxFor($baseCurrencies);
        $io->writeln('FX refreshed for base currencies: ' . implode(', ', $baseCurrencies));

        return Command::SUCCESS;
    }
}
