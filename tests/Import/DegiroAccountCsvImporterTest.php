<?php

namespace App\Tests\Import;

use App\Entity\TransactionType;
use App\Import\DegiroAccountCsvImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DegiroAccountCsvImporterTest extends TestCase
{
    private const HEADERS_OK = [
        'Date', 'Time', 'Value date', 'Product', 'ISIN', 'Description',
        'FX', 'Change', '__col_8', 'Balance', '__col_10', 'Order Id',
    ];

    public function testSupportsAccountStatementHeaders(): void
    {
        $this->assertTrue((new DegiroAccountCsvImporter())->supports(self::HEADERS_OK));
    }

    public function testRejectsTradesExportHeaders(): void
    {
        $this->assertFalse((new DegiroAccountCsvImporter())->supports([
            'Date', 'Time', 'Product', 'ISIN', 'Quantity', 'Price', 'Total CHF', 'Order ID',
        ]));
    }

    public function testRejectsIbkrHeaders(): void
    {
        $this->assertFalse((new DegiroAccountCsvImporter())->supports([
            'ClientAccountID', 'CurrencyPrimary', 'LevelOfDetail', 'TransactionID', 'ActivityCode',
        ]));
    }

    public function testParsesSellWithQuantityFromDescription(): void
    {
        $rows = [$this->row([
            'Date' => '17-04-2026', 'Time' => '18:31',
            'Product' => 'ROCKET LAB CORP', 'ISIN' => 'US7731211089',
            'Description' => 'Verkauf 75 zu je 85.11 USD (US7731211089)',
            'Change' => 'USD', '__col_8' => '6383.25',
            'Balance' => 'USD', '__col_10' => '6383.25',
            'Order Id' => 'fbd1c77b',
        ])];

        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows));
        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(TransactionType::TradeSell, $d->type);
        $this->assertSame('638325', $d->amountMinor);
        $this->assertSame('USD', $d->currency);
        $this->assertSame('US7731211089', $d->assetIsin);
        $this->assertSame('-75', $d->assetQuantity);
    }

    public function testParsesBuyWithQuantityFromDescription(): void
    {
        $rows = [$this->row([
            'Description' => 'Kauf 100 zu je 8.65 CAD (CA50077N1024)',
            'ISIN' => 'CA50077N1024',
            'Change' => 'CAD', '__col_8' => '-865.00',
        ])];

        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows));
        $d = $drafts[0];
        $this->assertSame(TransactionType::TradeBuy, $d->type);
        $this->assertSame('100', $d->assetQuantity);
        $this->assertSame('-86500', $d->amountMinor);
        $this->assertSame('CAD', $d->currency);
    }

    public function testStockSplitPrefixStillParsesAsTrade(): void
    {
        $rows = [$this->row([
            'Description' => 'AKTIENSPLIT: Kauf 20 zu je 23.4 USD (US3789735079)',
            'ISIN' => 'US3789735079',
            'Change' => 'USD', '__col_8' => '-468.00',
        ])];

        $d = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows))[0];
        $this->assertSame(TransactionType::TradeBuy, $d->type);
        $this->assertSame('20', $d->assetQuantity);
    }

    public function testIsinChangeWithThousandsSeparator(): void
    {
        // Real-world Degiro corporate action: rename moves shares from old ISIN to new ISIN
        // and quantities are written with a typographic apostrophe (’) as thousands separator.
        $sellOld = $this->row([
            'Description' => 'ISIN-ÄNDERUNG: Verkauf 1’400 zu je 25.42 USD (US7731221062)',
            'ISIN' => 'US7731221062',
            'Change' => 'USD', '__col_8' => '35588.00',
        ]);
        $buyNew = $this->row([
            'Description' => 'ISIN-ÄNDERUNG: Kauf 1’400 zu je 25.42 USD (US7731211089)',
            'ISIN' => 'US7731211089',
            'Change' => 'USD', '__col_8' => '-35588.00',
        ]);

        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse([$sellOld, $buyNew]));
        $this->assertSame(TransactionType::TradeSell, $drafts[0]->type);
        $this->assertSame('-1400', $drafts[0]->assetQuantity);
        $this->assertSame(TransactionType::TradeBuy, $drafts[1]->type);
        $this->assertSame('1400', $drafts[1]->assetQuantity);
    }

    public function testFractionalQuantity(): void
    {
        // Money-market fund conversions use fractional share quantities.
        $rows = [$this->row([
            'Description' => 'Geldmarktfonds Umwandlung: Kauf 116.5949 zu je 0.9768 CHF',
            'Change' => 'CHF', '__col_8' => '-113.91',
        ])];
        $d = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows))[0];
        $this->assertSame(TransactionType::TradeBuy, $d->type);
        $this->assertSame('116.5949', $d->assetQuantity);
    }

    public static function classificationCases(): array
    {
        return [
            ['Einzahlung', TransactionType::Deposit],
            ['SOFORT Einzahlung', TransactionType::Deposit],
            ['Reservation iDEAL', TransactionType::Deposit],
            ['Auszahlung', TransactionType::Withdrawal],
            ['Auszahlung von Ihrem Geldkonto bei der flatexDEGIRO Bank: 250 CHF', TransactionType::Withdrawal],
            ['Überweisung auf Ihr Geldkonto bei der flatexDEGIRO Bank: 277.14 CHF', TransactionType::Withdrawal],
            ['Dividende', TransactionType::Dividend],
            ['Dividendensteuer', TransactionType::Fee],
            ['Flatex Interest Income', TransactionType::Interest],
            ['Flatex Interest', TransactionType::Interest],
            ['Währungswechsel (Einbuchung)', TransactionType::FxConversion],
            ['Währungswechsel (Ausbuchung)', TransactionType::FxConversion],
            ['DEGIRO Transaktionsgebühren und/oder Fremdkosten', TransactionType::Fee],
            ['Gebühr für Kapitalmaßnahme', TransactionType::Fee],
            ['Stamp Duty (London/Dublin)', TransactionType::Fee],
            ['ADR/GDR Weitergabegebühr', TransactionType::Fee],
            ['Degiro Cash Sweep Transfer', TransactionType::Other],
            ['US6393581003', TransactionType::Other],
        ];
    }

    #[DataProvider('classificationCases')]
    public function testClassification(string $description, TransactionType $expected): void
    {
        $rows = [$this->row([
            'Description' => $description,
            'Change' => 'CHF', '__col_8' => '1.00',
        ])];
        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows));
        $this->assertSame($expected, $drafts[0]->type);
        // For non-trade rows, no asset quantity should be inferred.
        if ($expected !== TransactionType::TradeBuy && $expected !== TransactionType::TradeSell) {
            $this->assertNull($drafts[0]->assetQuantity);
        }
    }

    public function testHashStability(): void
    {
        $rows = [$this->row([
            'Date' => '17-04-2026', 'Time' => '18:31',
            'Description' => 'Verkauf 75 zu je 85.11 USD (US7731211089)',
            'Change' => 'USD', '__col_8' => '6383.25',
            'Balance' => 'USD', '__col_10' => '6383.25',
            'Order Id' => 'fbd1c77b',
            'ISIN' => 'US7731211089',
        ])];

        $a = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows));
        $b = iterator_to_array((new DegiroAccountCsvImporter())->parse($rows));
        $this->assertSame($a[0]->externalRef, $b[0]->externalRef);
    }

    public function testTwoIdenticalDescriptionsAtDifferentBalancesProduceDistinctHashes(): void
    {
        // Same fee on the same minute appears at two different running balances → different hash,
        // so each gets its own Transaction (won't be incorrectly deduped together).
        $r1 = $this->row([
            'Description' => 'Flatex Interest Income',
            'Change' => 'CHF', '__col_8' => '-0.01', 'Balance' => 'CHF', '__col_10' => '100.00',
        ]);
        $r2 = $this->row([
            'Description' => 'Flatex Interest Income',
            'Change' => 'CHF', '__col_8' => '-0.01', 'Balance' => 'CHF', '__col_10' => '99.99',
        ]);

        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse([$r1, $r2]));
        $this->assertCount(2, $drafts);
        $this->assertNotSame($drafts[0]->externalRef, $drafts[1]->externalRef);
    }

    public function testIdenticalMultiFillRowsGetDistinctHashes(): void
    {
        // Two truly identical rows (same date/time/desc/isin/change/balance/order) are
        // separate fills of one order — must not collapse into a single Transaction.
        $row = $this->row([
            'Date' => '06-11-2024', 'Time' => '17:35',
            'Description' => 'Kauf 100 zu je 12.55 USD (US7731221062)',
            'ISIN' => 'US7731221062',
            'Change' => 'USD', '__col_8' => '-1255.00',
            'Balance' => 'USD', '__col_10' => '-1255.00',
            'Order Id' => 'ord-abc',
        ]);

        $drafts = iterator_to_array((new DegiroAccountCsvImporter())->parse([$row, $row]));
        $this->assertCount(2, $drafts);
        $this->assertNotSame($drafts[0]->externalRef, $drafts[1]->externalRef);
        // Re-importing the same two-row sequence must produce the same two refs.
        $again = iterator_to_array((new DegiroAccountCsvImporter())->parse([$row, $row]));
        $this->assertSame($drafts[0]->externalRef, $again[0]->externalRef);
        $this->assertSame($drafts[1]->externalRef, $again[1]->externalRef);
    }

    public function testSkipsRowsWithEmptyChange(): void
    {
        $rows = [$this->row([
            'Description' => 'Some bookkeeping row',
            'Change' => 'CHF', '__col_8' => '',
        ])];
        $this->assertSame([], iterator_to_array((new DegiroAccountCsvImporter())->parse($rows)));
    }

    private function row(array $overrides): array
    {
        return array_merge([
            'Date' => '01-01-2025', 'Time' => '12:00', 'Value date' => '01-01-2025',
            'Product' => '', 'ISIN' => '', 'Description' => '',
            'FX' => '', 'Change' => 'CHF', '__col_8' => '0',
            'Balance' => 'CHF', '__col_10' => '0', 'Order Id' => '',
        ], $overrides);
    }
}
