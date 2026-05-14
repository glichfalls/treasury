<?php

namespace App\Import;

use App\Entity\TransactionType;

/**
 * Handles Degiro's "Account Statement" / "Kontoauszug" export — one row per cash event.
 *
 * Headers (column index in parentheses, empty-named columns are renamed __col_N by the
 * ImportService). NOTE: Degiro's headers don't sit above their actual data — the column
 * labelled "Change" holds the *currency code*, and the unnamed column after it holds the
 * numeric amount. Same with "Balance".
 *
 *   0 Date
 *   1 Time
 *   2 Value date
 *   3 Product             (asset name, blank for non-trade rows)
 *   4 ISIN                (blank for non-trade rows)
 *   5 Description         (German label, e.g. "Verkauf 75 zu je 85.11 USD (US7731211089)")
 *   6 FX                  (exchange rate, blank when not an FX leg)
 *   7 Change              (HEADER MISLEADING: this is the currency code of the change)
 *   8 __col_8             (the actual signed numeric change amount)
 *   9 Balance             (HEADER MISLEADING: this is the currency code of the balance)
 *  10 __col_10            (the actual numeric balance, ignored for our purposes)
 *  11 Order Id            (UUID grouping rows that belong to the same order; blank for non-trades)
 *
 * Each trade typically expands to multiple rows (trade leg, fee, two FX legs) sharing an
 * Order Id; we treat each as a separate Transaction so spending/fees are itemized.
 */
final class DegiroAccountCsvImporter implements CsvImporter
{
    public function name(): string { return 'degiro_account'; }

    public function supports(array $headers): bool
    {
        $needed = ['Date', 'Time', 'Value date', 'Product', 'ISIN', 'Description', 'Change', 'Balance', 'Order Id'];
        foreach ($needed as $col) {
            if (!in_array($col, $headers, true)) {
                return false;
            }
        }
        return true;
    }

    public function parse(iterable $rows): iterable
    {
        // Multi-fill orders generate several rows with identical (date,time,description,
        // isin,change,balance,orderId). They are *real* separate events but indistinguishable
        // by their data alone. We suffix the Nth occurrence of a given base hash with ":N"
        // so each gets its own externalRef. Degiro's export order is deterministic, so
        // re-imports produce the same suffixes and dedup still works.
        $occurrenceCount = [];

        foreach ($rows as $row) {
            $date = trim((string) ($row['Date'] ?? ''));
            $time = trim((string) ($row['Time'] ?? ''));
            if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
                continue;
            }
            $occurredAt = \DateTimeImmutable::createFromFormat('!d-m-Y', $date);
            if ($occurredAt === false) {
                continue;
            }

            $description = trim((string) ($row['Description'] ?? ''));
            $product = trim((string) ($row['Product'] ?? ''));
            $isin = trim((string) ($row['ISIN'] ?? '')) ?: null;
            $orderId = trim((string) ($row['Order Id'] ?? ''));
            // The labelled columns hold the currency codes; the actual numeric values
            // sit in the unnamed columns after them — see class doc comment.
            $changeCurrency = strtoupper(trim((string) ($row['Change'] ?? '')));
            $change = trim((string) ($row['__col_8'] ?? ''));
            $balance = trim((string) ($row['__col_10'] ?? ''));

            // Some rows have a blank Change (e.g. zero-value bookkeeping); skip them.
            if ($change === '' || $changeCurrency === '') {
                continue;
            }

            [$type, $quantity] = $this->classify($description);

            $baseHash = sha1(implode('|', [
                $date, $time,
                strtolower($description),
                $isin ?? '',
                $change, $changeCurrency,
                $balance,
                $orderId,
            ]));
            $n = $occurrenceCount[$baseHash] ?? 0;
            $occurrenceCount[$baseHash] = $n + 1;
            $hash = $n === 0 ? $baseHash : $baseHash . ':' . $n;

            yield new TransactionDraft(
                occurredAt: $occurredAt,
                amountMinor: MoneyParser::toMinor($change, 2),
                currency: $changeCurrency,
                type: $type,
                externalRef: 'degiro:' . $hash,
                description: $description !== '' ? $description : null,
                assetIsin: $isin,
                assetQuantity: $quantity,
                assetName: $product !== '' ? $product : null,
            );
        }
    }

    /**
     * Classify a Degiro German description line.
     *
     * @return array{0: TransactionType, 1: ?string} type and signed quantity (string), if present
     */
    private function classify(string $description): array
    {
        // Trade lines: "Kauf 100 zu je 8.65 CAD (CA50077N1024)" or with a corporate-action
        // prefix like "AKTIENSPLIT: Kauf ...", "DELISTING: Verkauf ...", "FUSION: Kauf ...",
        // "Geldmarktfonds Umwandlung: Kauf ...", "ISIN-ÄNDERUNG: Kauf ...". The quantity may
        // include Swiss thousands separators (regular or typographic apostrophe) and a fractional
        // part (e.g. fractional shares in money-market fund conversions).
        if (preg_match("/(?:^|:\\s*)Kauf\\s+([\\d.,'’\\s]+?)\\s+zu/iu", $description, $m)) {
            return [TransactionType::TradeBuy, $this->normalizeNumber($m[1])];
        }
        if (preg_match("/(?:^|:\\s*)Verkauf\\s+([\\d.,'’\\s]+?)\\s+zu/iu", $description, $m)) {
            return [TransactionType::TradeSell, '-' . $this->normalizeNumber($m[1])];
        }

        $lower = mb_strtolower($description);

        if (str_starts_with($lower, 'währungswechsel')) {
            return [TransactionType::FxConversion, null];
        }
        if (str_starts_with($lower, 'dividendensteuer')) {
            return [TransactionType::Fee, null];
        }
        if (str_starts_with($lower, 'dividende')) {
            return [TransactionType::Dividend, null];
        }
        if (str_contains($lower, 'interest')) {
            return [TransactionType::Interest, null];
        }
        if (
            str_contains($lower, 'transaktionsgebühr')
            || str_contains($lower, 'gebühr')
            || str_contains($lower, 'stamp duty')
            || str_contains($lower, 'weitergabegebühr')
        ) {
            return [TransactionType::Fee, null];
        }
        if (
            str_starts_with($lower, 'einzahlung')
            || str_starts_with($lower, 'sofort einzahlung')
            || str_starts_with($lower, 'reservation ideal')
        ) {
            return [TransactionType::Deposit, null];
        }
        if (
            str_starts_with($lower, 'auszahlung')
            || str_starts_with($lower, 'überweisung auf ihr geldkonto')
        ) {
            return [TransactionType::Withdrawal, null];
        }

        return [TransactionType::Other, null];
    }

    /**
     * Convert Degiro's decimal format (with apostrophes for thousands) to a plain string.
     */
    private function normalizeNumber(string $raw): string
    {
        return str_replace(["'", '’', ' '], '', $raw);
    }
}
