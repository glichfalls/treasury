<?php

namespace App\Tests\Fx;

use App\Entity\FxRate;
use App\Fx\FxConverter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FxConverterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private FxConverter $fx;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->conn = $c->get(Connection::class);

        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        // Fresh instance per test — the converter caches series in memory, so
        // re-using it across tests would mask DB-not-loaded bugs.
        $this->fx = new FxConverter($this->conn);
    }

    public function testSameCurrencyReturnsAmountUnchanged(): void
    {
        $this->assertSame(123_456, $this->fx->convertMinor(123_456, 'CHF', 'CHF', new \DateTimeImmutable('2025-01-01')));
        // No DB lookup needed for same-currency, so it works even with no fx_rates row.
    }

    public function testDirectRateAppliedAtHistoricalDate(): void
    {
        $this->addFx('USD', 'CHF', '0.90', '2025-01-01');
        $this->addFx('USD', 'CHF', '0.95', '2025-06-01');
        $this->addFx('USD', 'CHF', '0.92', '2025-09-01');

        // June 15 → uses the June 1 rate (latest on or before).
        $result = $this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-06-15'));
        $this->assertSame(95_00, $result);
    }

    public function testPickedRateIsLatestOnOrBeforeTarget(): void
    {
        $this->addFx('USD', 'CHF', '0.90', '2024-12-01');
        $this->addFx('USD', 'CHF', '0.95', '2025-06-01');

        // Exactly on the boundary — should use the same-day rate.
        $this->assertSame(95_00, $this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-06-01')));
        // One day before — falls back to the older rate.
        $this->assertSame(90_00, $this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-05-31')));
    }

    public function testInverseRateUsedWhenDirectMissing(): void
    {
        // Only CHF→USD stored, asking for USD→CHF should compute 1/rate.
        $this->addFx('CHF', 'USD', '1.10', '2025-01-01');
        // 100 USD * (1 / 1.10) = 90.909... → 90_91 minor (rounded)
        $result = $this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-06-01'));
        $this->assertSame(90_91, $result);
    }

    public function testReturnsNullWhenNoRateKnown(): void
    {
        // No data for the pair at all.
        $this->assertNull($this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-06-01')));

        // Rates exist but only AFTER the target date.
        $this->addFx('USD', 'CHF', '0.90', '2025-06-01');
        $this->assertNull($this->fx->convertMinor(100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-01-01')));
    }

    public function testNegativeAmountConvertedCorrectly(): void
    {
        $this->addFx('USD', 'CHF', '0.90', '2025-01-01');
        // -100 USD → -90 CHF, preserving sign.
        $this->assertSame(-90_00, $this->fx->convertMinor(-100_00, 'USD', 'CHF', new \DateTimeImmutable('2025-06-01')));
    }

    private function addFx(string $from, string $to, string $rate, string $date): void
    {
        $r = (new FxRate())
            ->setOccurredAt(new \DateTimeImmutable($date))
            ->setFromCurrency($from)
            ->setToCurrency($to)
            ->setRate($rate);
        $this->em->persist($r);
        $this->em->flush();
    }
}
