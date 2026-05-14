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

/**
 * Coin (commodity-backed asset) valuation. Value should derive from the gold spot
 * price scaled by fine-gold weight and an optional dealer premium.
 */
final class CoinValuationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HoldingsService $service;
    private Account $account;
    private Asset $spot;
    private Asset $vreneli;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->service = $c->get(HoldingsService::class);

        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('coins@example.com');
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $this->em->persist($user);

        $this->account = (new Account())
            ->setOwner($user)->setName('Gold')
            ->setType(AccountType::PreciousMetals)->setCurrency('CHF');
        $this->em->persist($this->account);

        $this->spot = (new Asset())
            ->setIsin(HoldingsService::SPOT_GOLD_ISIN)
            ->setTicker('GC=F')->setName('Gold spot')->setCurrency('USD');
        $this->em->persist($this->spot);

        $this->vreneli = (new Asset())
            ->setIsin('COIN:VRENELI20')
            ->setName('Vreneli 20 CHF')
            ->setCurrency('USD')
            ->setUnitWeightGrams('5.81')
            ->setPricePremiumPct('5.0');
        $this->em->persist($this->vreneli);

        $this->em->flush();
    }

    public function testCoinValueDerivesFromSpotWithWeightAndPremium(): void
    {
        // Spot: $2400 / oz on 2025-01-10. Bought 2 Vrenelis.
        $this->addPrice($this->spot, '2025-01-10', '240000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2025-01-10');
        $this->addTrade('COIN:VRENELI20', '2', '-140000');

        $holdings = $this->service->forAccount($this->account);

        $this->assertCount(1, $holdings);
        $h = $holdings[0];
        $this->assertSame('COIN:VRENELI20', $h->isin);

        // Per-coin native price: ($2400 / 31.1034768 g/oz) × 5.81 g × 1.05 = $471.13 (approx)
        $expectedPerCoinUsd = (2400.0 / 31.1034768) * 5.81 * 1.05;
        $this->assertEqualsWithDelta($expectedPerCoinUsd, ((int) $h->priceMinor) / 100, 0.5);

        // Value: 2 × $471.13 × FX 0.90 = ~CHF 848.04
        $expectedChf = 2 * $expectedPerCoinUsd * 0.90;
        $this->assertEqualsWithDelta($expectedChf, ((int) $h->valueBaseMinor) / 100, 0.5);
    }

    public function testCoinHasNoValueWithoutSpotPrice(): void
    {
        // No spot price stored → can't value the coin.
        $this->addTrade('COIN:VRENELI20', '2', '-140000');

        $holdings = $this->service->forAccount($this->account);
        $this->assertCount(1, $holdings);
        $this->assertNull($holdings[0]->valueBaseMinor);
    }

    private function addTrade(string $isin, string $qty, string $amountMinor): void
    {
        $t = (new Transaction())
            ->setAccount($this->account)
            ->setOccurredAt(new \DateTimeImmutable('2025-01-15'))
            ->setAmountMinor($amountMinor)
            ->setCurrency('CHF')
            ->setType(TransactionType::TradeBuy)
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
