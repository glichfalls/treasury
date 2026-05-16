<?php

namespace App\Tests\Plan;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\FxRate;
use App\Entity\Price;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Plan\PlanService;
use App\Plan\PlanWindow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlanServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlanService $service;
    private User $user;

    protected function setUp(): void
    {
        // Fresh kernel per test so the singleton FxConverter cache doesn't bleed
        // across cases. Same pattern as TimeSeriesServiceTest.
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PlanService::class);

        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('plan@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'pw'));
        $this->user->setBaseCurrency('CHF');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testNonInvestableAccountsAreExcluded(): void
    {
        $checking = $this->makeAccount('Checking', AccountType::BankChecking, 'CHF');
        $brokerage = $this->makeAccount('Brokerage', AccountType::Brokerage, 'CHF');
        $this->addTx($checking, '2024-01-01', 100_00, TransactionType::Deposit);
        $this->addTx($brokerage, '2024-01-01', 100_00, TransactionType::Deposit);

        $result = $this->service->inputsForUser($this->user, PlanWindow::Inception);

        $this->assertCount(1, $result);
        $this->assertSame('Brokerage', $result[0]['name']);
    }

    public function testForeignCurrencyAccountStartingValueConvertsToBase(): void
    {
        $usd = $this->makeAccount('IBKR', AccountType::Brokerage, 'USD');
        $this->addTx($usd, '2024-01-10', 1_000_00, TransactionType::Deposit, currency: 'USD');
        // FX rate used for the "today" conversion — pick a far-future date so
        // the converter's "≤ today" lookup picks it up.
        $this->addFx('USD', 'CHF', '0.90', '2024-01-10');

        $result = $this->service->inputsForUser($this->user, PlanWindow::Inception);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('USD', $row['currency']);
        $this->assertSame('CHF', $row['baseCurrency']);
        $this->assertSame('100000', $row['startingMinor']);       // native USD minor units
        $this->assertSame('90000', $row['startingMinorBase']);    // 1000 USD × 0.90 = 900 CHF = 90 000 minor
    }

    public function testHistoricalContributionAnnualizesAcrossWindow(): void
    {
        $brokerage = $this->makeAccount('Brokerage', AccountType::Brokerage, 'CHF');
        // Two years ago: 2 000 CHF deposit. One year ago: 4 000 CHF deposit.
        // With "Inception" window, span ≈ 2 years, total deposits = 6 000 CHF
        // → annualized ≈ 3 000 CHF/year = 300 000 minor/year.
        $twoYearsAgo = (new \DateTimeImmutable('today'))->modify('-2 years')->format('Y-m-d');
        $oneYearAgo = (new \DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d');
        $this->addTx($brokerage, $twoYearsAgo, 200_000, TransactionType::Deposit);
        $this->addTx($brokerage, $oneYearAgo, 400_000, TransactionType::Deposit);

        $result = $this->service->inputsForUser($this->user, PlanWindow::Inception);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertTrue($row['hasSufficientHistory']);
        // 6 000 / 2 years = 3 000 CHF/year = 300 000 minor units. Allow rounding wobble.
        $contrib = (int) $row['historicalContribAnnualMinorBase'];
        $this->assertGreaterThanOrEqual(290_000, $contrib);
        $this->assertLessThanOrEqual(310_000, $contrib);
    }

    public function testInsufficientHistoryReturnsNullDefaults(): void
    {
        $brokerage = $this->makeAccount('Brokerage', AccountType::Brokerage, 'CHF');
        // One deposit a week ago — well under the 6-month minimum.
        $aWeekAgo = (new \DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d');
        $this->addTx($brokerage, $aWeekAgo, 100_00, TransactionType::Deposit);

        $result = $this->service->inputsForUser($this->user, PlanWindow::FiveYears);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertFalse($row['hasSufficientHistory']);
        $this->assertNull($row['historicalContribAnnualMinorBase']);
        $this->assertNull($row['historicalReturnPct']);
        $this->assertNull($row['historicalVolPct']);
    }

    public function testReturnAndVolDerivedFromMonthlyPriceMovement(): void
    {
        $brokerage = $this->makeAccount('Brokerage', AccountType::Brokerage, 'CHF');
        $asset = (new Asset())->setIsin('CH0123456789')->setTicker('TST')->setName('Test')->setCurrency('CHF');
        $this->em->persist($asset);
        $this->em->flush();

        // Buy 100 shares 1 year ago at 100 CHF. Add monthly prices so the TWR
        // series has enough points for vol. End price 110 CHF → ~10% TWR.
        $oneYearAgo = new \DateTimeImmutable('today -1 year');
        $this->addTx(
            $brokerage,
            $oneYearAgo->format('Y-m-d'),
            -1_000_000,
            TransactionType::TradeBuy,
            isin: 'CH0123456789',
            qty: '100',
        );
        // Opening basis so cash starts positive (TWR needs a positive prevTotal).
        $this->addTx(
            $brokerage,
            $oneYearAgo->modify('-1 day')->format('Y-m-d'),
            1_000_000,
            TransactionType::OpeningBalance,
        );

        // Monthly prices walking 100 → 110 with a couple of zig-zags so vol > 0.
        $monthlyPrices = ['10000', '10500', '9800', '10300', '10600', '10200',
                          '10500', '10800', '10400', '10700', '11000', '10900', '11000'];
        $cursor = $oneYearAgo;
        foreach ($monthlyPrices as $price) {
            $this->addPrice($asset, $cursor->format('Y-m-d'), $price, 'CHF');
            $cursor = $cursor->modify('+1 month');
        }

        $result = $this->service->inputsForUser($this->user, PlanWindow::OneYear);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertTrue($row['hasSufficientHistory']);
        $this->assertNotNull($row['historicalReturnPct']);
        $this->assertNotNull($row['historicalVolPct']);
        // Sanity: end price 110 vs start 100 ≈ +10% over a year — positive.
        $this->assertGreaterThan(0, $row['historicalReturnPct']);
        $this->assertLessThan(50, $row['historicalReturnPct']);
        // Vol should be > 0 given the zig-zag.
        $this->assertGreaterThan(0, $row['historicalVolPct']);
    }

    private function makeAccount(string $name, AccountType $type, string $currency): Account
    {
        $a = (new Account())
            ->setOwner($this->user)->setName($name)
            ->setType($type)->setCurrency($currency);
        $this->em->persist($a);
        $this->em->flush();
        return $a;
    }

    private function addTx(
        Account $account,
        string $date,
        int $amountMinor,
        TransactionType $type,
        ?string $isin = null,
        ?string $qty = null,
        string $currency = 'CHF',
    ): void {
        $t = (new Transaction())
            ->setAccount($account)
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setAmountMinor((string) $amountMinor)
            ->setCurrency($currency)
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
