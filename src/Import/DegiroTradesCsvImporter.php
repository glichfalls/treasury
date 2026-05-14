<?php

namespace App\Import;

use App\Entity\TransactionType;

/**
 * Handles Degiro's "Transactions" export — trades-only, with a `Total CHF` net column
 * and a `Quantity` column. Each row already represents the net cash impact of one trade.
 *
 * For the richer "Account Statement" export (deposits, dividends, fees, FX legs), see
 * {@see DegiroAccountCsvImporter}.
 */
final class DegiroTradesCsvImporter implements CsvImporter
{
    public function name(): string { return 'degiro_trades'; }

    /**
     * Degiro's trades export has empty-string headers between Price/Local value/Value CHF
     * (those slots hold the currency codes). We match on the unique combination of named
     * columns that this export carries.
     */
    public function supports(array $headers): bool
    {
        $needed = ['Date', 'Time', 'Product', 'ISIN', 'Quantity', 'Price', 'Total CHF', 'Order ID'];
        foreach ($needed as $col) {
            if (!in_array($col, $headers, true)) {
                return false;
            }
        }
        return true;
    }

    public function parse(iterable $rows): iterable
    {
        // Multi-fill orders generate several rows with identical (date,time,qty,total,
        // isin,orderId,product) — real separate fills indistinguishable by data alone.
        // Suffix the Nth occurrence with ":N" so each gets its own externalRef.
        $occurrenceCount = [];

        foreach ($rows as $row) {
            // Row is indexed by header AND by position. We need positional access for
            // the un-named currency columns (indexes 8 and 10).
            $date = trim((string) ($row['Date'] ?? ''));
            $time = trim((string) ($row['Time'] ?? ''));
            if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
                continue;
            }
            $occurredAt = \DateTimeImmutable::createFromFormat('!d-m-Y', $date);
            if ($occurredAt === false) {
                continue;
            }

            $product = trim((string) ($row['Product'] ?? ''));
            $isin = trim((string) ($row['ISIN'] ?? '')) ?: null;
            $quantityRaw = trim((string) ($row['Quantity'] ?? '0'));
            $totalChf = trim((string) ($row['Total CHF'] ?? '0'));
            $orderId = trim((string) ($row['Order ID'] ?? ''));

            if ($quantityRaw === '' || $totalChf === '') {
                continue;
            }

            $qtySigned = (float) $quantityRaw;
            $type = $qtySigned >= 0 ? TransactionType::TradeBuy : TransactionType::TradeSell;

            $baseHash = sha1(implode('|', [
                $date, $time, $quantityRaw, $totalChf,
                $isin ?? '', $orderId, strtolower($product),
            ]));
            $n = $occurrenceCount[$baseHash] ?? 0;
            $occurrenceCount[$baseHash] = $n + 1;
            $hash = $n === 0 ? $baseHash : $baseHash . ':' . $n;

            yield new TransactionDraft(
                occurredAt: $occurredAt,
                amountMinor: MoneyParser::toMinor($totalChf, 2),
                currency: 'CHF',
                type: $type,
                externalRef: 'degiro:' . $hash,
                description: $product !== '' ? $product : null,
                assetIsin: $isin,
                assetQuantity: $quantityRaw,
                assetName: $product !== '' ? $product : null,
            );
        }
    }
}
