<?php

namespace App\Tests\TimeSeries;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\FxRate;
use App\Entity\Price;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\TimeSeries\TimeSeriesService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TimeSeriesServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TimeSeriesService $service;
    private User $user;
    private Account $checking;
    private Account $brokerage;
    private Asset $aapl;

    protected function setUp(): void
    {
        // Force a fresh kernel per test so the singleton FxConverter doesn't
        // carry over its cached "no rate found for USD→CHF" from a previous
        // test where the fx_rates table was empty.
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(TimeSeriesService::class);

        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('ts@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'pw'));
        $this->em->persist($this->user);

        $this->checking = (new Account())
            ->setOwner($this->user)->setName('Checking')
            ->setType(AccountType::BankChecking)->setCurrency('CHF');
        $this->brokerage = (new Account())
            ->setOwner($this->user)->setName('Brokerage')
            ->setType(AccountType::Brokerage)->setCurrency('CHF');
        $this->em->persist($this->checking);
        $this->em->persist($this->brokerage);

        $this->aapl = (new Asset())
            ->setIsin('US0378331005')->setTicker('AAPL')->setName('APPLE')->setCurrency('USD');
        $this->em->persist($this->aapl);

        $this->em->flush();
    }

    public function testCashFlowSeparatesIncomeFromSpending(): void
    {
        $this->addTx($this->checking, '2025-03-05', 500000, TransactionType::Deposit);
        $this->addTx($this->checking, '2025-03-10', -250000, TransactionType::Withdrawal);
        $this->addTx($this->checking, '2025-03-15', -3000, TransactionType::Fee);
        // Trade legs, FX, and opening balances should be EXCLUDED from cash
        // flow (the opening balance is accumulated savings, not real income).
        $this->addTx($this->brokerage, '2025-03-20', -100000, TransactionType::TradeBuy);
        $this->addTx($this->brokerage, '2025-03-21', -500, TransactionType::FxConversion);
        $this->addTx($this->brokerage, '2025-03-22', 999999, TransactionType::OpeningBalance);

        $series = $this->service->cashFlowMonthly(
            $this->user,
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2025-03-31'),
        );

        $this->assertCount(1, $series);
        $point = $series[0];
        $this->assertSame('2025-03', $point->month->format('Y-m'));
        $this->assertSame('500000', $point->incomeMinor);
        $this->assertSame('-253000', $point->expenseMinor); // -250000 withdrawal + -3000 fee
    }

    public function testCashFlowFillsEmptyMonths(): void
    {
        $this->addTx($this->checking, '2025-01-10', 100000, TransactionType::Deposit);
        $this->addTx($this->checking, '2025-03-10', 100000, TransactionType::Deposit);

        $series = $this->service->cashFlowMonthly(
            $this->user,
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-03-31'),
        );

        $this->assertCount(3, $series);
        $this->assertSame('100000', $series[0]->incomeMinor);
        $this->assertSame('0',      $series[1]->incomeMinor); // Feb empty but still present
        $this->assertSame('100000', $series[2]->incomeMinor);
    }

    public function testNetDepositsTrackedSeparatelyFromTotalValue(): void
    {
        // Deposit 1000 CHF, then buy stock for 800 CHF. Cumulative cash = 200, holdings ≈ 800,
        // total ≈ 1000, but net deposits = 1000 (the buy doesn't move net-deposits).
        $this->addTx($this->brokerage, '2025-01-10', 100000, TransactionType::Deposit);
        $this->addTx($this->brokerage, '2025-01-15', -80000, TransactionType::TradeBuy, 'AAPL buy', 'US0378331005', '5');
        $this->addPrice($this->aapl, '2025-01-15', '16000', 'USD');
        $this->addFx('USD', 'CHF', '1.00', '2025-01-15');

        $series = $this->service->accountSeries(
            $this->brokerage,
            new \DateTimeImmutable('2025-01-20'),
            new \DateTimeImmutable('2025-01-20'),
            'daily',
        );

        $this->assertCount(1, $series);
        $point = $series[0];
        $this->assertSame('20000', $point->cashMinor);    // 1000 deposit − 800 buy = 200
        $this->assertSame('80000', $point->holdingsMinor); // 5 × $160 × FX 1.00 = $800
        $this->assertSame('100000', $point->netDepositsMinor); // only the deposit counted
    }

    public function testGlobalAllocationAggregatesAcrossAccounts(): void
    {
        // Cash in two accounts, plus a holding.
        $this->addTx($this->checking, '2025-01-01', 500000, TransactionType::Deposit);
        $this->addTx($this->brokerage, '2025-01-01', 200000, TransactionType::Deposit);
        $this->addTx($this->brokerage, '2025-01-02', -150000, TransactionType::TradeBuy, 'AAPL', 'US0378331005', '10');
        $this->addPrice($this->aapl, '2025-01-02', '18000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2025-01-02');

        $result = $this->service->globalAllocation($this->user, 'CHF');

        $this->assertSame('CHF', $result['baseCurrency']);
        // Cash: 5000 checking + 2000 brokerage - 1500 spent on stock = 5500 CHF = 550000 minor
        $cashSlice = array_values(array_filter($result['slices'], fn($s) => $s['label'] === 'Cash'))[0] ?? null;
        $this->assertNotNull($cashSlice);
        $this->assertSame('550000', $cashSlice['valueBaseMinor']);
        // Holding: 10 × $180 × 0.90 = $1620 → CHF 1620 = 162000 minor
        $aaplSlice = array_values(array_filter($result['slices'], fn($s) => $s['isin'] === 'US0378331005'))[0] ?? null;
        $this->assertNotNull($aaplSlice);
        $this->assertSame('162000', $aaplSlice['valueBaseMinor']);
    }

    public function testForeignCurrencyCashIsConvertedAtTransactionDateRate(): void
    {
        // USD brokerage account. Transactions stored in USD. Portfolio-wide
        // (netWorthSeries with displayCurrency=CHF) should convert each cash
        // flow at the FX rate effective on its date.
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        // Two USD deposits at different FX rates.
        $this->addTx($usdAccount, '2025-01-10', 100_000, TransactionType::Deposit, currency: 'USD');
        $this->addTx($usdAccount, '2025-06-10', 200_000, TransactionType::Deposit, currency: 'USD');

        $this->addFx('USD', 'CHF', '0.90', '2025-01-10');
        $this->addFx('USD', 'CHF', '0.95', '2025-06-10');

        $series = $this->service->netWorthSeries(
            $this->user,
            new \DateTimeImmutable('2025-06-15'),
            new \DateTimeImmutable('2025-06-15'),
            'daily',
            'CHF',
        );

        $this->assertCount(1, $series);
        // Jan: 1000 USD × 0.90 = 900 CHF (90 000 minor)
        // Jun: 2000 USD × 0.95 = 1900 CHF (190 000 minor)
        // Total cash in CHF base: 280 000 minor
        $this->assertSame('280000', $series[0]->cashMinor);
        $this->assertSame('280000', $series[0]->netDepositsMinor);
    }

    public function testMixedCurrencyAccountsAggregateToBaseCurrency(): void
    {
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        // CHF account: 5000 CHF deposit. USD account: 1000 USD deposit at 0.90.
        $this->addTx($this->checking, '2025-01-10', 500_000, TransactionType::Deposit);
        $this->addTx($usdAccount, '2025-01-10', 100_000, TransactionType::Deposit, currency: 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2025-01-10');

        $series = $this->service->netWorthSeries(
            $this->user,
            new \DateTimeImmutable('2025-01-15'),
            new \DateTimeImmutable('2025-01-15'),
            'daily',
            'CHF',
        );

        // 500 000 (CHF) + 90 000 (USD→CHF) = 590 000 minor in CHF.
        $this->assertSame('590000', $series[0]->cashMinor);
    }

    public function testCashFlowMonthlyConvertsForeignCurrencyIncome(): void
    {
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        // USD interest income, USD fee. Both should convert via FX.
        $this->addTx($usdAccount, '2025-03-05', 200_00, TransactionType::Interest, currency: 'USD');
        $this->addTx($usdAccount, '2025-03-15', -50_00, TransactionType::Fee, currency: 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2025-03-05');
        $this->addFx('USD', 'CHF', '0.90', '2025-03-15');

        $series = $this->service->cashFlowMonthly(
            $this->user,
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2025-03-31'),
            'CHF',
        );

        $this->assertCount(1, $series);
        // 200 USD × 0.90 = 180 CHF → 18 000 minor
        // -50 USD × 0.90 = -45 CHF → -4 500 minor
        $this->assertSame('18000', $series[0]->incomeMinor);
        $this->assertSame('-4500', $series[0]->expenseMinor);
    }

    public function testCashFlowMonthlyMixesCurrenciesIntoSingleBucket(): void
    {
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        // Same month: a CHF deposit + a USD deposit. Both end up as CHF income.
        $this->addTx($this->checking, '2025-03-10', 500_00, TransactionType::Deposit);
        $this->addTx($usdAccount, '2025-03-10', 100_00, TransactionType::Deposit, currency: 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2025-03-10');

        $series = $this->service->cashFlowMonthly(
            $this->user,
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2025-03-31'),
            'CHF',
        );

        // 500 CHF + (100 USD × 0.90) = 500 + 90 = 590 CHF → 59 000 minor.
        $this->assertSame('59000', $series[0]->incomeMinor);
    }

    public function testCashFlowByCategoryConvertsForeignCurrency(): void
    {
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        // A USD subscription expense, properly categorized.
        $tx = (new Transaction())
            ->setAccount($usdAccount)
            ->setOccurredAt(new \DateTimeImmutable('2025-04-15'))
            ->setAmountMinor('-1000')   // -10 USD
            ->setCurrency('USD')
            ->setType(TransactionType::Withdrawal)
            ->setCategory(\App\Entity\TransactionCategory::Subscriptions);
        $this->em->persist($tx);
        $this->em->flush();

        $this->addFx('USD', 'CHF', '0.90', '2025-04-15');

        $result = $this->service->cashFlowByCategoryMonthly(
            $this->user,
            new \DateTimeImmutable('2025-04-01'),
            new \DateTimeImmutable('2025-04-30'),
            'CHF',
        );

        $this->assertCount(1, $result);
        // -10 USD × 0.90 = -9 CHF → -900 minor.
        $this->assertSame('2025-04', $result[0]['month']);
        $this->assertSame('subscriptions', $result[0]['category']);
        $this->assertSame('-900', $result[0]['amountMinor']);
    }

    public function testCashFlowFallsBackToRawAmountWhenFxMissing(): void
    {
        // Edge case: a USD transaction with NO matching FX rate. The series
        // should still show *something* (raw amount in CHF terms) rather than
        // silently dropping the cash flow — easier to spot + debug.
        $usdAccount = (new Account())
            ->setOwner($this->user)->setName('IBKR')
            ->setType(AccountType::Brokerage)->setCurrency('USD');
        $this->em->persist($usdAccount);
        $this->em->flush();

        $this->addTx($usdAccount, '2025-01-10', 100_000, TransactionType::Deposit, currency: 'USD');
        // No FX rate added.

        $series = $this->service->netWorthSeries(
            $this->user,
            new \DateTimeImmutable('2025-01-15'),
            new \DateTimeImmutable('2025-01-15'),
            'daily',
            'CHF',
        );

        // Fallback: the raw 100 000 USD amount surfaces in cashMinor.
        $this->assertSame('100000', $series[0]->cashMinor);
    }

    private function addTx(
        Account $account,
        string $date,
        int $amountMinor,
        TransactionType $type,
        string $description = '',
        ?string $isin = null,
        ?string $qty = null,
        string $currency = 'CHF',
    ): void {
        $t = (new Transaction())
            ->setAccount($account)
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setAmountMinor((string) $amountMinor)
            ->setCurrency($currency)
            ->setDescription($description ?: null)
            ->setType($type)
            ->setAssetIsin($isin)
            ->setAssetQuantity($qty);
        $this->em->persist($t);
        $this->em->flush();
    }

    private function addPrice(Asset $asset, string $date, string $priceMinor, string $currency): void
    {
        $p = (new Price())
            ->setAsset($asset)
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setPriceMinor($priceMinor)
            ->setCurrency($currency);
        $this->em->persist($p);
        $this->em->flush();
    }

    private function addFx(string $from, string $to, string $rate, string $date): void
    {
        $r = (new FxRate())
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setFromCurrency($from)->setToCurrency($to)
            ->setRate($rate);
        $this->em->persist($r);
        $this->em->flush();
    }
}
