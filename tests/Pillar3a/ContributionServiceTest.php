<?php

namespace App\Tests\Pillar3a;

use App\Entity\Account;
use App\Entity\AccountAllocation;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\FxRate;
use App\Entity\Price;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Pillar3a\ContributionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ContributionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ContributionService $service;
    private Account $account;
    private Asset $voo;
    private Asset $aapl;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->service = $c->get(ContributionService::class);

        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('3a@example.com');
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $this->em->persist($user);

        $this->account = (new Account())
            ->setOwner($user)->setName('viac')
            ->setType(AccountType::Pillar3a)->setCurrency('CHF');
        $this->em->persist($this->account);

        $this->voo = (new Asset())->setIsin('US9229083632')->setTicker('VOO')->setCurrency('USD');
        $this->aapl = (new Asset())->setIsin('US0378331005')->setTicker('AAPL')->setCurrency('USD');
        $this->em->persist($this->voo);
        $this->em->persist($this->aapl);

        $this->em->flush();
    }

    public function testFanOutCreatesDepositAndTrades(): void
    {
        $this->setAllocation([['US9229083632', 6000], ['US0378331005', 4000]]); // 60% VOO, 40% AAPL
        $this->addPrice($this->voo, '2024-06-01', '50000', 'USD');   // $500
        $this->addPrice($this->aapl, '2024-06-01', '20000', 'USD');  // $200
        $this->addFx('USD', 'CHF', '0.90', '2024-06-01');

        $result = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000, // CHF 1000
        );

        $this->assertSame(TransactionType::Deposit, $result['deposit']->getType());
        $this->assertSame('100000', $result['deposit']->getAmountMinor());
        $this->assertCount(2, $result['trades']);
        $this->assertSame([], $result['missingPrices']);

        // 60% of CHF 1000 = CHF 600 → 60000 minor
        $voo = $result['trades'][0];
        $this->assertSame('US9229083632', $voo->getAssetIsin());
        $this->assertSame('-60000', $voo->getAmountMinor());
        // shares = 600 CHF / ($500 × 0.90) = 600 / 450 = 1.33333333
        $this->assertEqualsWithDelta(1.33333333, (float) $voo->getAssetQuantity(), 0.0000001);

        $aapl = $result['trades'][1];
        $this->assertSame('US0378331005', $aapl->getAssetIsin());
        $this->assertSame('-40000', $aapl->getAmountMinor());
        // shares = 400 CHF / ($200 × 0.90) = 400 / 180 = 2.22222222
        $this->assertEqualsWithDelta(2.22222222, (float) $aapl->getAssetQuantity(), 0.0000001);
    }

    public function testMissingPriceSkipsThatSliceButKeepsOthers(): void
    {
        $this->setAllocation([['US9229083632', 5000], ['US0378331005', 5000]]);
        // Only price for VOO, none for AAPL.
        $this->addPrice($this->voo, '2024-06-01', '50000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2024-06-01');

        $result = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000,
        );

        $this->assertCount(1, $result['trades']);
        $this->assertSame(['US0378331005'], $result['missingPrices']);
    }

    public function testLeftoverPercentStaysAsCash(): void
    {
        // 70% allocated, 30% remains uninvested (cash).
        $this->setAllocation([['US9229083632', 7000]]);
        $this->addPrice($this->voo, '2024-06-01', '50000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2024-06-01');

        $result = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000, // CHF 1000
        );

        $this->assertCount(1, $result['trades']);
        $this->assertSame('-70000', $result['trades'][0]->getAmountMinor());

        // Net cash on the account = deposit (+1000) + trade (-700) = +300
        $cashSum = (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM transactions WHERE account_id = ?',
            [$this->account->getId()->toBinary()],
        );
        $this->assertSame(30000, $cashSum);
    }

    public function testAllocationOver100PercentIsRejected(): void
    {
        $this->setAllocation([['US9229083632', 6000], ['US0378331005', 5000]]); // 110%

        $this->expectException(\InvalidArgumentException::class);
        $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000,
        );
    }

    public function testOpeningBalanceUsesItsOwnType(): void
    {
        $this->setAllocation([['US9229083632', 10000]]);
        $this->addPrice($this->voo, '2024-06-01', '50000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2024-06-01');

        $result = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000,
            'Opening balance',
            isOpeningBalance: true,
        );

        // The deposit row carries the new type so cash-flow charts know to skip
        // it (the money is accumulated savings, not income on the entry date).
        // The buy legs stay as ordinary trade_buy so holdings math is unchanged.
        $this->assertSame(TransactionType::OpeningBalance, $result['deposit']->getType());
        $this->assertCount(1, $result['trades']);
        $this->assertSame(TransactionType::TradeBuy, $result['trades'][0]->getType());
    }

    public function testBackdatedContributionUsesStrategyEffectiveAtThatDate(): void
    {
        // Two strategies:
        //  - From 2023-01-01: 100 % VOO
        //  - From 2025-01-01: 100 % AAPL
        $this->setAllocation([['US9229083632', 10000]], '2023-01-01');
        $this->setAllocation([['US0378331005', 10000]], '2025-01-01');

        // Prices for both ETFs on both dates, plus FX.
        $this->addPrice($this->voo, '2024-06-01', '40000', 'USD');
        $this->addPrice($this->aapl, '2025-06-01', '20000', 'USD');
        $this->addFx('USD', 'CHF', '0.90', '2024-06-01');
        $this->addFx('USD', 'CHF', '0.88', '2025-06-01');

        // Back-dated contribution to 2024 should hit the OLD strategy (VOO).
        $oldEra = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2024-06-01'),
            100000,
        );
        $this->assertCount(1, $oldEra['trades']);
        $this->assertSame('US9229083632', $oldEra['trades'][0]->getAssetIsin());

        // A 2025 contribution should hit the NEW strategy (AAPL).
        $newEra = $this->service->record(
            $this->account,
            new \DateTimeImmutable('2025-06-01'),
            100000,
        );
        $this->assertCount(1, $newEra['trades']);
        $this->assertSame('US0378331005', $newEra['trades'][0]->getAssetIsin());
    }

    /** @param list<array{0:string,1:int}> $rules */
    private function setAllocation(array $rules, string $effectiveFrom = '2020-01-01'): void
    {
        foreach ($rules as [$isin, $bp]) {
            $r = (new AccountAllocation())
                ->setAccount($this->account)
                ->setAssetIsin($isin)
                ->setPercentBasisPoints($bp)
                ->setEffectiveFrom(new \DateTimeImmutable($effectiveFrom));
            $this->em->persist($r);
        }
        $this->em->flush();
    }

    private function addPrice(Asset $asset, string $date, string $minor, string $currency): void
    {
        $p = (new Price())
            ->setAsset($asset)
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setPriceMinor($minor)
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
