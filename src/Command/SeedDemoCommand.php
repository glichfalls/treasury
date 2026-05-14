<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a self-contained demo user with believable-but-fake data, used to populate
 * the README screenshot. Idempotent: if the demo user exists, the command no-ops.
 */
#[AsCommand(
    name: 'app:seed:demo',
    description: 'Create a demo user with sample accounts and transactions for screenshots',
)]
class SeedDemoCommand extends Command
{
    private const EMAIL = 'demo@treasury.local';
    private const PASSWORD = 'demo';

    /** ticker => [isin, currency, name, approx_price_2024_q1] */
    private const TICKERS = [
        'AAPL' => ['US0378331005', 'USD', 'APPLE INC',                185.0],
        'MSFT' => ['US5949181045', 'USD', 'MICROSOFT CORP',           405.0],
        'NVDA' => ['US67066G1040', 'USD', 'NVIDIA CORP',               85.0],
        'VOO'  => ['US9229083632', 'USD', 'VANGUARD S&P 500 ETF',     460.0],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if ($existing !== null) {
            $io->warning(sprintf('Demo user %s already exists. Delete it first if you want to reseed.', self::EMAIL));
            return Command::SUCCESS;
        }

        $user = (new User())
            ->setEmail(self::EMAIL)
            ->setPassword($this->hasher->hashPassword(new User(), self::PASSWORD));
        $this->em->persist($user);

        $checking = (new Account())
            ->setOwner($user)
            ->setName('Everyday Checking')
            ->setInstitution('Demo Bank')
            ->setType(AccountType::BankChecking)
            ->setCurrency('CHF');

        $brokerage = (new Account())
            ->setOwner($user)
            ->setName('Investments')
            ->setInstitution('Demo Broker')
            ->setType(AccountType::Brokerage)
            ->setCurrency('CHF');

        $this->em->persist($checking);
        $this->em->persist($brokerage);

        $this->seedAssets();
        $this->seedCheckingActivity($checking, $brokerage);
        $this->seedBrokerageTrades($brokerage);

        $this->em->flush();

        $io->success(sprintf(
            "Demo user created: %s / %s\nAccounts: Everyday Checking, Investments\nRun `app:prices:backfill --range=max` to populate the chart with real Yahoo history.",
            self::EMAIL,
            self::PASSWORD,
        ));

        return Command::SUCCESS;
    }

    private function seedAssets(): void
    {
        $repo = $this->em->getRepository(Asset::class);
        foreach (self::TICKERS as $ticker => [$isin, $currency, $name]) {
            if ($repo->findOneBy(['isin' => $isin]) !== null) {
                continue;
            }
            $this->em->persist((new Asset())
                ->setIsin($isin)
                ->setTicker($ticker)
                ->setName($name)
                ->setCurrency($currency));
        }
    }

    /**
     * 28 months of plausible salary + rent + monthly transfer to the brokerage,
     * plus weekly spending. Roughly: +CHF 6500 in, -CHF 2500 rent, -CHF 1500
     * transferred out for investments, plus -CHF 50..300 weekly spending.
     */
    private function seedCheckingActivity(Account $checking, Account $brokerage): void
    {
        $start = new \DateTimeImmutable('2024-01-01');
        $end = new \DateTimeImmutable('today');
        $month = $start;

        while ($month <= $end) {
            $this->addTx($checking, $month->modify('first day of this month'), 650000, TransactionType::Deposit, 'Monthly salary');
            $this->addTx($checking, $month->modify('first day of this month'), -250000, TransactionType::Withdrawal, 'Rent');
            $this->addTx($checking, $month->modify('first day of this month')->modify('+14 days'), -150000, TransactionType::Withdrawal, 'Transfer to investments');
            $this->addTx($brokerage, $month->modify('first day of this month')->modify('+14 days'), 150000, TransactionType::Deposit, 'Transfer from checking');

            // Spending: four weekly debits, pseudo-random but deterministic.
            for ($week = 0; $week < 4; $week++) {
                $day = $month->modify('first day of this month')->modify(sprintf('+%d days', $week * 7 + 3));
                if ($day > $end) {
                    break;
                }
                $amount = -((($week * 31 + (int) $month->format('n')) % 250) + 50) * 100;
                $this->addTx($checking, $day, $amount, TransactionType::Withdrawal, $this->spendingLabel($week));
            }

            $month = $month->modify('first day of next month');
        }
    }

    private function seedBrokerageTrades(Account $brokerage): void
    {
        // Each entry: [date, ticker, qty (positive = buy), price-per-share-in-USD]
        $trades = [
            ['2024-02-15', 'AAPL',  10, 185.00],
            ['2024-02-15', 'MSFT',   5, 410.00],
            ['2024-04-10', 'VOO',   10, 460.00],
            ['2024-06-20', 'NVDA',  10, 130.00],
            ['2024-09-05', 'AAPL',   5, 220.00],
            ['2024-11-15', 'VOO',    5, 540.00],
            ['2025-01-20', 'NVDA',   8, 145.00],
            ['2025-03-12', 'MSFT',   3, 395.00],
            ['2025-05-08', 'AAPL',  -8, 205.00], // partial sell
            ['2025-08-22', 'VOO',    4, 580.00],
            ['2025-11-04', 'NVDA',   5, 175.00],
            ['2026-02-10', 'MSFT',   4, 425.00],
            ['2026-04-18', 'VOO',    3, 595.00],
        ];

        $fxUsdChf = 0.92; // close enough for cosmetic seed data
        foreach ($trades as [$dateStr, $ticker, $qty, $price]) {
            [$isin] = self::TICKERS[$ticker];
            $usdAmount = -$qty * $price;
            $chfMinor = (int) round($usdAmount * $fxUsdChf * 100);
            $type = $qty >= 0 ? TransactionType::TradeBuy : TransactionType::TradeSell;

            $tx = new Transaction();
            $tx->setAccount($brokerage);
            $tx->setOccurredAt(new \DateTimeImmutable($dateStr));
            $tx->setAmountMinor((string) $chfMinor);
            $tx->setCurrency('CHF');
            $tx->setDescription(sprintf('%s %d %s @ %.2f USD', $qty >= 0 ? 'Buy' : 'Sell', abs($qty), $ticker, $price));
            $tx->setType($type);
            $tx->setSource(TransactionSource::Manual);
            $tx->setAssetIsin($isin);
            $tx->setAssetQuantity((string) $qty);
            $this->em->persist($tx);
        }
    }

    private function addTx(
        Account $account,
        \DateTimeImmutable $when,
        int $amountMinor,
        TransactionType $type,
        string $description,
    ): void {
        $tx = new Transaction();
        $tx->setAccount($account);
        $tx->setOccurredAt($when);
        $tx->setAmountMinor((string) $amountMinor);
        $tx->setCurrency('CHF');
        $tx->setDescription($description);
        $tx->setType($type);
        $tx->setSource(TransactionSource::Manual);
        $this->em->persist($tx);
    }

    private function spendingLabel(int $week): string
    {
        return ['Groceries', 'Restaurants', 'Transport', 'Misc'][$week % 4];
    }
}
