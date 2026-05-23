<?php

namespace App\Command;

use App\News\NewsFetcher;
use App\News\Sentiment\NewsClassificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:news:refresh',
    description: 'Aggregate news for all held, news-enabled assets and classify it',
)]
class RefreshNewsCommand extends Command
{
    public function __construct(
        private readonly NewsFetcher $fetcher,
        private readonly NewsClassificationService $classifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max articles per asset per source', '10');
        $this->addOption('classify', null, InputOption::VALUE_NONE, 'Also classify pending items (default: skip — items are classified on first open)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->fetcher->refresh(null, max(1, (int) $input->getOption('limit')));
        $io->writeln(sprintf(
            'Fetched — inserted: <info>%d</info>, skipped: <comment>%d</comment>, errors: %d',
            $result['inserted'],
            $result['skipped'],
            count($result['errors']),
        ));
        foreach ($result['errors'] as $err) {
            $io->writeln('  ! ' . $err);
        }

        if ($input->getOption('classify')) {
            $c = $this->classifier->classifyPending();
            $io->writeln(sprintf('Classified <info>%d</info> item(s) via <comment>%s</comment>.', $c['classified'], $c['via']));
        }

        return Command::SUCCESS;
    }
}
