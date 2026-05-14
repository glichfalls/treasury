<?php

namespace App\Import;

use App\Entity\TransactionType;

final class IbkrCsvImporter implements CsvImporter
{
    public function name(): string { return 'ibkr'; }

    public function supports(array $headers): bool
    {
        return in_array('ClientAccountID', $headers, true)
            && in_array('TransactionID', $headers, true)
            && in_array('ActivityCode', $headers, true)
            && in_array('LevelOfDetail', $headers, true);
    }

    public function parse(iterable $rows): iterable
    {
        foreach ($rows as $row) {
            // Only consume the consolidated base-currency rows so we don't double-count.
            if (($row['LevelOfDetail'] ?? '') !== 'BaseCurrency') {
                continue;
            }

            $transactionId = trim((string) ($row['TransactionID'] ?? ''));
            if ($transactionId === '') {
                continue;
            }

            $date = trim((string) ($row['Date'] ?? $row['ReportDate'] ?? ''));
            if (!preg_match('/^\d{8}$/', $date)) {
                continue;
            }
            $occurredAt = \DateTimeImmutable::createFromFormat('!Ymd', $date);
            if ($occurredAt === false) {
                continue;
            }

            $amount = trim((string) ($row['Amount'] ?? '0'));
            $currency = strtoupper(trim((string) ($row['CurrencyPrimary'] ?? 'CHF')));
            $type = $this->classify((string) ($row['ActivityCode'] ?? ''));

            $ticker = trim((string) ($row['Symbol'] ?? '')) ?: null;
            $isin = trim((string) ($row['ISIN'] ?? '')) ?: null;
            $assetName = trim((string) ($row['Description'] ?? '')) ?: null;
            $qtyRaw = trim((string) ($row['TradeQuantity'] ?? ''));
            $assetQuantity = ($qtyRaw === '' || $qtyRaw === '0') ? null : $qtyRaw;

            $description = trim((string) ($row['ActivityDescription'] ?? ''));

            yield new TransactionDraft(
                occurredAt: $occurredAt,
                amountMinor: MoneyParser::toMinor($amount, 2),
                currency: $currency,
                type: $type,
                externalRef: 'ibkr:' . $transactionId,
                description: $description !== '' ? $description : null,
                assetIsin: $isin,
                assetQuantity: $assetQuantity,
                assetTicker: $ticker,
                assetName: $assetName,
            );
        }
    }

    private function classify(string $activityCode): TransactionType
    {
        return match (strtoupper(trim($activityCode))) {
            'DEP' => TransactionType::Deposit,
            'WITH' => TransactionType::Withdrawal,
            'BUY' => TransactionType::TradeBuy,
            'SELL' => TransactionType::TradeSell,
            'FOREX' => TransactionType::FxConversion,
            'DIV', 'DIVFEE' => TransactionType::Dividend,
            'INT', 'CINT' => TransactionType::Interest,
            'FEE', 'COMM', 'SLB' => TransactionType::Fee,
            default => TransactionType::Other,
        };
    }
}
