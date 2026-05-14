<?php

namespace App\Tests\Holdings;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\FxRate;
use App\Entity\Price;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Holdings\HoldingsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HoldingsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HoldingsService $service;
    private Account $account;
    private Asset $aapl;
    private Asset $nesn;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(HoldingsService::class);

        $this->resetSchema();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('h@example.com');
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $this->em->persist($user);

        $this->account = new Account();
        $this->account->setOwner($user);
        $this->account->setName('IBKR');
        $this->account->setType(AccountType::Brokerage);
        $this->account->setCurrency('CHF');
        $this->em->persist($this->account);

        $this->aapl = (new Asset())
            ->setIsin('US0378331005')
            ->setTicker('AAPL')
            ->setName('APPLE INC')
            ->setCurrency('USD');
        $this->nesn = (new Asset())
            ->setIsin('CH0038863350')
            ->setTicker('NESN.SW')
            ->setName('NESTLE')
            ->setCurrency('CHF');
        $this->em->persist($this->aapl);
        $this->em->persist($this->nesn);
        $this->em->flush();
    }

    public function testNoHoldingsForEmptyAccount(): void
    {
        $this->assertSame([], $this->service->forAccount($this->account));
    }

    public function testHoldingsAggregateAcrossBuysAndSells(): void
    {
        // Two buys (5 + 10) and one sell (-3) — net 12 shares.
        $this->addTrade('US0378331005', '5', TransactionType::TradeBuy, -1000);
        $this->addTrade('US0378331005', '10', TransactionType::TradeBuy, -2000);
        $this->addTrade('US0378331005', '-3', TransactionType::TradeSell, 600);

        $holdings = $this->service->forAccount($this->account);
        $this->assertCount(1, $holdings);
        $this->assertSame('US0378331005', $holdings[0]->isin);
        $this->assertSame('12', rtrim(rtrim($holdings[0]->quantity, '0'), '.'));
    }

    public function testFullySoldPositionIsExcluded(): void
    {
        $this->addTrade('US0378331005', '5', TransactionType::TradeBuy, -1000);
        $this->addTrade('US0378331005', '-5', TransactionType::TradeSell, 1100);

        $this->assertSame([], $this->service->forAccount($this->account));
    }

    public function testValuationWithoutPriceLeavesValueNull(): void
    {
        $this->addTrade('CH0038863350', '10', TransactionType::TradeBuy, -1000);

        $holdings = $this->service->forAccount($this->account);
        $this->assertCount(1, $holdings);
        $this->assertNull($holdings[0]->priceMinor);
        $this->assertNull($holdings[0]->valueBaseMinor);
    }

    public function testValuationSameCurrencyNoFxNeeded(): void
    {
        $this->addTrade('CH0038863350', '10', TransactionType::TradeBuy, -1000);
        $this->addPrice($this->nesn, '7500', 'CHF'); // 75.00 CHF per share

        $holdings = $this->service->forAccount($this->account);
        $this->assertCount(1, $holdings);
        $this->assertSame('75000', $holdings[0]->valueBaseMinor); // 10 × 750.00 minor units in CHF
    }

    public function testValuationCrossCurrencyAppliesFx(): void
    {
        $this->addTrade('US0378331005', '5', TransactionType::TradeBuy, -1000);
        $this->addPrice($this->aapl, '20000', 'USD'); // $200 per share
        $this->addFx('USD', 'CHF', '0.88');           // 1 USD = 0.88 CHF

        $holdings = $this->service->forAccount($this->account);
        $this->assertCount(1, $holdings);
        // 5 × $200 = $1000; × 0.88 = CHF 880.00 = 88000 minor
        $this->assertSame('88000', $holdings[0]->valueBaseMinor);
    }

    public function testTotalValueIsSumOfHoldings(): void
    {
        $this->addTrade('US0378331005', '5', TransactionType::TradeBuy, -1000);
        $this->addTrade('CH0038863350', '10', TransactionType::TradeBuy, -1000);
        $this->addPrice($this->aapl, '20000', 'USD');
        $this->addPrice($this->nesn, '7500', 'CHF');
        $this->addFx('USD', 'CHF', '0.88');

        $holdings = $this->service->forAccount($this->account);
        $total = $this->service->totalValueMinor($this->account, $holdings);
        // CHF 880.00 + CHF 750.00 = CHF 1630.00 = 163000 minor
        $this->assertSame('163000', $total);
    }

    private function addTrade(string $isin, string $quantity, TransactionType $type, int $amountMinor): void
    {
        $t = new Transaction();
        $t->setAccount($this->account);
        $t->setOccurredAt(new \DateTimeImmutable());
        $t->setAmountMinor((string) $amountMinor);
        $t->setCurrency('CHF');
        $t->setType($type);
        $t->setAssetIsin($isin);
        $t->setAssetQuantity($quantity);
        $this->em->persist($t);
        $this->em->flush();
    }

    private function addPrice(Asset $asset, string $priceMinor, string $currency): void
    {
        $p = new Price();
        $p->setAsset($asset);
        $p->setOccurredAt((new \DateTimeImmutable())->setTime(0, 0));
        $p->setPriceMinor($priceMinor);
        $p->setCurrency($currency);
        $this->em->persist($p);
        $this->em->flush();
    }

    private function addFx(string $from, string $to, string $rate): void
    {
        $r = new FxRate();
        $r->setOccurredAt((new \DateTimeImmutable())->setTime(0, 0));
        $r->setFromCurrency($from);
        $r->setToCurrency($to);
        $r->setRate($rate);
        $this->em->persist($r);
        $this->em->flush();
    }

    private function resetSchema(): void
    {
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }
}
