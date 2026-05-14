<?php

namespace App\Tests\Import;

use App\Entity\TransactionType;
use App\Import\ZkbCsvImporter;
use PHPUnit\Framework\TestCase;

final class ZkbCsvImporterTest extends TestCase
{
    private const HEADERS_OK = [
        'Datum', 'Buchungstext', 'Whg', 'Betrag Detail', 'ZKB-Referenz', 'Referenznummer',
        'Belastung CHF', 'Gutschrift CHF', 'Valuta', 'Saldo CHF', 'Zahlungszweck', 'Details',
    ];

    public function testSupportsZkbHeaders(): void
    {
        $this->assertTrue((new ZkbCsvImporter())->supports(self::HEADERS_OK));
    }

    public function testRejectsIbkrHeaders(): void
    {
        $this->assertFalse((new ZkbCsvImporter())->supports([
            'ClientAccountID', 'TransactionID', 'ActivityCode', 'LevelOfDetail',
        ]));
    }

    public function testRejectsDegiroHeaders(): void
    {
        $this->assertFalse((new ZkbCsvImporter())->supports([
            'Date', 'Time', 'Value date', 'Product', 'ISIN', 'Description',
        ]));
    }

    public function testParsesCardPurchaseAsWithdrawal(): void
    {
        $rows = [$this->row([
            'Datum' => '12.05.2026',
            'Buchungstext' => 'Einkauf ZKB Visa Debit Card Nr. xxxx 1276, Coop ZH Hofwiesenstrasse',
            'Belastung CHF' => '6.60',
            'Valuta' => '11.05.2026',
            'ZKB-Referenz' => 'L115B111A54C8QAM-1',
        ])];

        $drafts = iterator_to_array((new ZkbCsvImporter())->parse($rows));
        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(TransactionType::Withdrawal, $d->type);
        $this->assertSame('-660', $d->amountMinor);
        $this->assertSame('CHF', $d->currency);
        $this->assertSame('zkb:L115B111A54C8QAM-1', $d->externalRef);
        // Valuta is preferred over Datum for occurredAt.
        $this->assertSame('2026-05-11', $d->occurredAt->format('Y-m-d'));
    }

    public function testParsesIncomingTransferAsDeposit(): void
    {
        $rows = [$this->row([
            'Datum' => '06.05.2026',
            'Buchungstext' => 'Gutschrift Auftraggeber: novu ag, Ottikerstrasse 14, 8006 Zürich',
            'Gutschrift CHF' => '16.75',
            'Valuta' => '06.05.2026',
            'ZKB-Referenz' => 'CA260505607D8B03',
        ])];

        $drafts = iterator_to_array((new ZkbCsvImporter())->parse($rows));
        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(TransactionType::Deposit, $d->type);
        $this->assertSame('1675', $d->amountMinor);
        $this->assertSame('zkb:CA260505607D8B03', $d->externalRef);
    }

    public function testClassifiesFeeFromBuchungstext(): void
    {
        $rows = [$this->row([
            'Datum' => '05.05.2026',
            'Buchungstext' => 'Gebühr ZKB inklusiv Gold',
            'Belastung CHF' => '12.50',
            'Valuta' => '30.04.2026',
            'ZKB-Referenz' => 'L11ET111A4KEA28L-1',
        ])];

        $drafts = iterator_to_array((new ZkbCsvImporter())->parse($rows));
        $this->assertSame(TransactionType::Fee, $drafts[0]->type);
        $this->assertSame('-1250', $drafts[0]->amountMinor);
    }

    public function testClassifiesInterestFromBuchungstext(): void
    {
        $rows = [$this->row([
            'Datum' => '31.12.2025',
            'Buchungstext' => 'Zinsgutschrift per 31.12.2025',
            'Gutschrift CHF' => '4.25',
            'Valuta' => '31.12.2025',
            'ZKB-Referenz' => 'ZINS-2025',
        ])];

        $this->assertSame(
            TransactionType::Interest,
            iterator_to_array((new ZkbCsvImporter())->parse($rows))[0]->type,
        );
    }

    public function testSkipsPendingRowsWithoutValuta(): void
    {
        $rows = [
            $this->row([
                'Datum' => '14.05.2026',
                'Buchungstext' => 'Einkauf ZKB Visa Debit Card Nr. xxxx 1276, MERCHANT',
                'Belastung CHF' => '6.90',
                'Valuta' => '',
                'ZKB-Referenz' => '',
            ]),
            $this->row([
                'Datum' => '14.05.2026',
                'Buchungstext' => 'Einkauf ZKB Visa Debit Card Nr. xxxx 1276, MERCHANT (settled)',
                'Belastung CHF' => '6.90',
                'Valuta' => '12.05.2026',
                'ZKB-Referenz' => 'L115B111A59DCAZ3-1',
            ]),
        ];

        $drafts = iterator_to_array((new ZkbCsvImporter())->parse($rows));
        $this->assertCount(1, $drafts);
        $this->assertSame('zkb:L115B111A59DCAZ3-1', $drafts[0]->externalRef);
    }

    public function testHashFallbackWhenReferenzIsMissing(): void
    {
        // Same data appearing twice should get distinct externalRefs (suffix :1 on second).
        $row = $this->row([
            'Datum' => '01.05.2026',
            'Buchungstext' => 'TWINT Belastung',
            'Belastung CHF' => '20.00',
            'Valuta' => '01.05.2026',
            'ZKB-Referenz' => '',
        ]);

        $drafts = iterator_to_array((new ZkbCsvImporter())->parse([$row, $row]));
        $this->assertCount(2, $drafts);
        $this->assertNotSame($drafts[0]->externalRef, $drafts[1]->externalRef);
        $this->assertStringStartsWith('zkb:', $drafts[0]->externalRef);
        $this->assertStringEndsWith(':1', $drafts[1]->externalRef);
    }

    public function testAppendsZahlungszweckAndDetailsToDescription(): void
    {
        $rows = [$this->row([
            'Datum' => '06.05.2026',
            'Buchungstext' => 'Gutschrift Auftraggeber: novu ag',
            'Gutschrift CHF' => '100.00',
            'Valuta' => '06.05.2026',
            'ZKB-Referenz' => 'CA260505607D8B03',
            'Zahlungszweck' => 'Salary May',
            'Details' => 'novu ag, Ottikerstrasse 14',
        ])];

        $d = iterator_to_array((new ZkbCsvImporter())->parse($rows))[0];
        $this->assertStringContainsString('Salary May', (string) $d->description);
        $this->assertStringContainsString('Ottikerstrasse', (string) $d->description);
    }

    /** @param array<string, string> $overrides */
    private function row(array $overrides): array
    {
        $defaults = [
            'Datum' => '', 'Buchungstext' => '', 'Whg' => '', 'Betrag Detail' => '',
            'ZKB-Referenz' => '', 'Referenznummer' => '',
            'Belastung CHF' => '', 'Gutschrift CHF' => '',
            'Valuta' => '', 'Saldo CHF' => '',
            'Zahlungszweck' => '', 'Details' => '',
        ];
        return array_merge($defaults, $overrides);
    }
}
