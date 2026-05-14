<?php

namespace App\Tests\Import;

use App\Entity\TransactionType;
use App\Import\DegiroTradesCsvImporter;
use PHPUnit\Framework\TestCase;

final class DegiroTradesCsvImporterTest extends TestCase
{
    private const HEADERS_OK = [
        'Date', 'Time', 'Product', 'ISIN', 'Reference exchange', 'Venue',
        'Quantity', 'Price', '__col_8', 'Local value', '__col_10', 'Value CHF',
        'Exchange rate', 'AutoFX Fee', 'Transaction and/or third party fees CHF',
        'Total CHF', 'Order ID', '__col_17',
    ];

    public function testSupportsDegiroHeaders(): void
    {
        $this->assertTrue((new DegiroTradesCsvImporter())->supports(self::HEADERS_OK));
    }

    public function testRejectsIbkrHeaders(): void
    {
        $this->assertFalse((new DegiroTradesCsvImporter())->supports([
            'ClientAccountID', 'CurrencyPrimary', 'LevelOfDetail', 'TransactionID', 'ActivityCode',
        ]));
    }

    public function testParsesBuy(): void
    {
        $rows = [$this->row([
            'Date' => '14-01-2026', 'Time' => '15:30',
            'Product' => 'KRAKEN ROBOTICS INC', 'ISIN' => 'CA50077N1024',
            'Quantity' => '100', 'Price' => '8.6500',
            'Total CHF' => '-500.33',
            'Order ID' => '22f596c9-f8de-4647-ace1-f434b8f74b83',
        ])];

        $drafts = iterator_to_array((new DegiroTradesCsvImporter())->parse($rows));
        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(TransactionType::TradeBuy, $d->type);
        $this->assertSame('-50033', $d->amountMinor);
        $this->assertSame('CHF', $d->currency);
        $this->assertSame('2026-01-14', $d->occurredAt->format('Y-m-d'));
        $this->assertSame('CA50077N1024', $d->assetIsin);
        $this->assertSame('100', $d->assetQuantity);
        $this->assertStringStartsWith('degiro:', $d->externalRef);
    }

    public function testParsesSellViaNegativeQuantity(): void
    {
        $rows = [$this->row([
            'Date' => '17-04-2026', 'Time' => '18:31',
            'Product' => 'ROCKET LAB CORP', 'ISIN' => 'US7731211089',
            'Quantity' => '-75', 'Price' => '85.1100',
            'Total CHF' => '4959.70',
            'Order ID' => 'fbd1c77b',
        ])];

        $drafts = iterator_to_array((new DegiroTradesCsvImporter())->parse($rows));
        $this->assertSame(TransactionType::TradeSell, $drafts[0]->type);
        $this->assertSame('495970', $drafts[0]->amountMinor);
        $this->assertSame('-75', $drafts[0]->assetQuantity);
    }

    public function testHashIsStableAcrossCalls(): void
    {
        $row = [$this->row([
            'Date' => '14-01-2026', 'Time' => '15:30', 'Product' => 'KRAKEN',
            'ISIN' => 'CA1', 'Quantity' => '100', 'Total CHF' => '-500.33',
            'Order ID' => 'ord-1',
        ])];

        $a = iterator_to_array((new DegiroTradesCsvImporter())->parse($row));
        $b = iterator_to_array((new DegiroTradesCsvImporter())->parse($row));
        $this->assertSame($a[0]->externalRef, $b[0]->externalRef);
    }

    public function testDifferentRowsProduceDifferentHashes(): void
    {
        $row1 = $this->row([
            'Date' => '14-01-2026', 'Time' => '15:30', 'Product' => 'KRAKEN',
            'ISIN' => 'CA1', 'Quantity' => '100', 'Total CHF' => '-500.33', 'Order ID' => 'ord-1',
        ]);
        $row2 = $this->row([
            'Date' => '14-01-2026', 'Time' => '15:30', 'Product' => 'KRAKEN',
            'ISIN' => 'CA1', 'Quantity' => '200', 'Total CHF' => '-1000.50', 'Order ID' => 'ord-1',
        ]);

        $drafts = iterator_to_array((new DegiroTradesCsvImporter())->parse([$row1, $row2]));
        $this->assertNotSame($drafts[0]->externalRef, $drafts[1]->externalRef);
    }

    public function testSkipsMalformedDate(): void
    {
        $rows = [$this->row(['Date' => 'not-a-date', 'Quantity' => '1', 'Total CHF' => '1'])];
        $this->assertSame([], iterator_to_array((new DegiroTradesCsvImporter())->parse($rows)));
    }

    private function row(array $overrides): array
    {
        return array_merge([
            'Date' => '01-01-2025', 'Time' => '12:00',
            'Product' => '', 'ISIN' => '',
            'Reference exchange' => '', 'Venue' => '',
            'Quantity' => '0', 'Price' => '0',
            '__col_8' => '', 'Local value' => '0',
            '__col_10' => '', 'Value CHF' => '0',
            'Exchange rate' => '', 'AutoFX Fee' => '',
            'Transaction and/or third party fees CHF' => '',
            'Total CHF' => '0', 'Order ID' => '', '__col_17' => '',
        ], $overrides);
    }
}
