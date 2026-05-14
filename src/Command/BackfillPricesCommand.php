<?php

namespace App\Command;

use App\Price\PriceFetcher;
use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prices:backfill',
    description: 'Backfill historical daily prices and FX rates from Yahoo Finance',
)]
class BackfillPricesCommand extends Command
{
    public function __construct(
        private readonly PriceFetcher $fetcher,
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('range', null, InputOption::VALUE_REQUIRED, 'Yahoo range (1mo, 1y, 5y, max)', 'max');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $range = (string) $input->getOption('range');

        $io->section("Backfilling prices (range={$range})");
        $result = $this->fetcher->backfillHistory(null, $range);
        $io->writeln(sprintf(
            'Assets backfilled: <info>%d</info>, skipped: <comment>%d</comment>, errors: %d',
            $result['updated'],
            $result['skipped'],
            count($result['errors']),
        ));
        foreach ($result['errors'] as $err) {
            $io->writeln('  ! ' . $err);
        }

        $io->section('Backfilling FX rates');
        $baseCurrencies = array_values(array_unique(array_map(
            fn($a) => $a->getCurrency(),
            $this->accounts->findAll(),
        )));
        $this->fetcher->backfillFxHistory($baseCurrencies, null, $range);
        $io->writeln('FX history loaded for base currencies: ' . implode(', ', $baseCurrencies));

        return Command::SUCCESS;
    }
}
