<?php

namespace App\Tests\Import;

use App\Entity\TransactionType;
use App\Import\IbkrCsvImporter;
use App\Import\TransactionDraft;
use PHPUnit\Framework\TestCase;

final class IbkrCsvImporterTest extends TestCase
{
    private const HEADERS = [
        'ClientAccountID', 'CurrencyPrimary', 'AssetClass', 'Symbol', 'Description',
        'ISIN', 'Date', 'ReportDate', 'ActivityCode', 'ActivityDescription',
        'Buy/Sell', 'TradeQuantity', 'TradePrice', 'Amount', 'Balance',
        'LevelOfDetail', 'TransactionID',
    ];

    public function testSupportsRealHeaders(): void
    {
        $importer = new IbkrCsvImporter();
        $this->assertTrue($importer->supports(self::HEADERS));
    }

    public function testRejectsForeignHeaders(): void
    {
        $importer = new IbkrCsvImporter();
        $this->assertFalse($importer->supports(['Date', 'Time', 'Product', 'ISIN']));
    }

    public function testParsesDeposit(): void
    {
        $rows = [[
            'ClientAccountID' => 'U1', 'CurrencyPrimary' => 'CHF', 'AssetClass' => '',
            'Symbol' => '', 'Description' => '', 'ISIN' => '',
            'Date' => '20240308', 'ReportDate' => '20240308',
            'ActivityCode' => 'DEP', 'ActivityDescription' => 'Electronic Fund Transfer',
            'Buy/Sell' => '', 'TradeQuantity' => '0', 'TradePrice' => '',
            'Amount' => '1000', 'Balance' => '1000',
            'LevelOfDetail' => 'BaseCurrency', 'TransactionID' => '12345',
        ]];

        $drafts = iterator_to_array((new IbkrCsvImporter())->parse($rows));

        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame('ibkr:12345', $d->externalRef);
        $this->assertSame(TransactionType::Deposit, $d->type);
        $this->assertSame('100000', $d->amountMinor);
        $this->assertSame('CHF', $d->currency);
        $this->assertSame('2024-03-08', $d->occurredAt->format('Y-m-d'));
        $this->assertNull($d->assetIsin);
        $this->assertNull($d->assetQuantity);
    }

    public function testParsesTradeWithSymbolAndQuantity(): void
    {
        $rows = [[
            'ClientAccountID' => 'U1', 'CurrencyPrimary' => 'CHF', 'AssetClass' => 'STK',
            'Symbol' => 'AAPL', 'Description' => 'APPLE INC', 'ISIN' => 'US0378331005',
            'Date' => '20240314', 'ReportDate' => '20240314',
            'ActivityCode' => 'BUY', 'ActivityDescription' => 'Buy 5 APPLE INC',
            'Buy/Sell' => 'BUY', 'TradeQuantity' => '5', 'TradePrice' => '173.79',
            'Amount' => '-768.792214', 'Balance' => '229.81',
            'LevelOfDetail' => 'BaseCurrency', 'TransactionID' => '67890',
        ]];

        $drafts = iterator_to_array((new IbkrCsvImporter())->parse($rows));

        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(TransactionType::TradeBuy, $d->type);
        $this->assertSame('-76879', $d->amountMinor);
        $this->assertSame('US0378331005', $d->assetIsin);
        $this->assertSame('AAPL', $d->assetTicker);
        $this->assertSame('APPLE INC', $d->assetName);
        $this->assertSame('5', $d->assetQuantity);
    }

    public function testSkipsNonBaseCurrencyRows(): void
    {
        $rows = [[
            'ClientAccountID' => 'U1', 'CurrencyPrimary' => 'USD',
            'Symbol' => 'AAPL', 'Description' => '', 'ISIN' => '',
            'Date' => '20240314', 'ReportDate' => '20240314',
            'ActivityCode' => 'BUY', 'ActivityDescription' => '',
            'Buy/Sell' => 'BUY', 'TradeQuantity' => '5', 'TradePrice' => '173.79',
            'Amount' => '-868.95', 'Balance' => '0',
            'LevelOfDetail' => 'Currency', 'TransactionID' => '67890',
            'AssetClass' => 'STK',
        ]];

        $drafts = iterator_to_array((new IbkrCsvImporter())->parse($rows));
        $this->assertSame([], $drafts);
    }

    public function testSkipsRowsWithoutTransactionId(): void
    {
        $rows = [array_fill_keys(self::HEADERS, '') + [
            'LevelOfDetail' => 'BaseCurrency', 'Date' => '20240101', 'CurrencyPrimary' => 'CHF',
            'Amount' => '10', 'ActivityCode' => 'OTHER', 'TransactionID' => '',
        ]];
        $drafts = iterator_to_array((new IbkrCsvImporter())->parse($rows));
        $this->assertSame([], $drafts);
    }

    public static function activityCodeMatrix(): array
    {
        return [
            ['DEP', TransactionType::Deposit],
            ['WITH', TransactionType::Withdrawal],
            ['BUY', TransactionType::TradeBuy],
            ['SELL', TransactionType::TradeSell],
            ['FOREX', TransactionType::FxConversion],
            ['DIV', TransactionType::Dividend],
            ['DIVFEE', TransactionType::Dividend],
            ['INT', TransactionType::Interest],
            ['FEE', TransactionType::Fee],
            ['COMM', TransactionType::Fee],
            ['MYSTERY', TransactionType::Other],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activityCodeMatrix')]
    public function testActivityCodeClassification(string $code, TransactionType $expected): void
    {
        $rows = [[
            'ClientAccountID' => 'U1', 'CurrencyPrimary' => 'CHF', 'AssetClass' => '',
            'Symbol' => '', 'Description' => '', 'ISIN' => '',
            'Date' => '20240101', 'ReportDate' => '20240101',
            'ActivityCode' => $code, 'ActivityDescription' => '',
            'Buy/Sell' => '', 'TradeQuantity' => '0', 'TradePrice' => '',
            'Amount' => '10', 'Balance' => '0',
            'LevelOfDetail' => 'BaseCurrency', 'TransactionID' => 'tx-' . $code,
        ]];

        /** @var TransactionDraft[] $drafts */
        $drafts = iterator_to_array((new IbkrCsvImporter())->parse($rows));
        $this->assertSame($expected, $drafts[0]->type);
    }
}
