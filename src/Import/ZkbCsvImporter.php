<?php

namespace App\Import;

use App\Entity\TransactionType;

/**
 * Imports Zürcher Kantonalbank account statement exports (semicolon-separated, CHF).
 *
 * Header (in file order):
 *   Datum, Buchungstext, Whg, Betrag Detail, ZKB-Referenz, Referenznummer,
 *   Belastung CHF, Gutschrift CHF, Valuta, Saldo CHF, Zahlungszweck, Details
 *
 * Quirks:
 *  - Pending rows (just-recorded card swipes) have empty Valuta / Saldo / ZKB-Referenz.
 *    They re-appear settled in subsequent exports, so we skip them — importing pending
 *    rows would create transactions whose hash changes once a real ref is assigned.
 *  - Exactly one of Belastung CHF / Gutschrift CHF carries the amount, never both.
 *  - Whg / Betrag Detail are populated only when ZKB converts a foreign-currency
 *    payment; the CHF columns still hold the booked amount, which is what we record.
 */
final class ZkbCsvImporter implements CsvImporter
{
    public function name(): string { return 'zkb'; }

    public function supports(array $headers): bool
    {
        $needed = ['Datum', 'Buchungstext', 'Belastung CHF', 'Gutschrift CHF', 'Valuta', 'ZKB-Referenz'];
        foreach ($needed as $col) {
            if (!in_array($col, $headers, true)) {
                return false;
            }
        }
        return true;
    }

    public function parse(iterable $rows): iterable
    {
        // Fallback occurrence counter for rows that lack a ZKB-Referenz — see classify().
        $hashCount = [];

        foreach ($rows as $row) {
            $datum = trim((string) ($row['Datum'] ?? ''));
            $valuta = trim((string) ($row['Valuta'] ?? ''));
            $buchungstext = trim((string) ($row['Buchungstext'] ?? ''));
            $debit = trim((string) ($row['Belastung CHF'] ?? ''));
            $credit = trim((string) ($row['Gutschrift CHF'] ?? ''));
            $zkbRef = trim((string) ($row['ZKB-Referenz'] ?? ''));
            $details = trim((string) ($row['Details'] ?? ''));
            $zweck = trim((string) ($row['Zahlungszweck'] ?? ''));

            // Pending rows surface again with a Valuta on the next export — wait for that.
            if ($valuta === '') {
                continue;
            }
            // Sanity: each row is either a debit or a credit, never both / neither.
            if (($debit === '' && $credit === '') || ($debit !== '' && $credit !== '')) {
                continue;
            }

            $occurredAt = \DateTimeImmutable::createFromFormat('!d.m.Y', $valuta);
            if ($occurredAt === false) {
                $occurredAt = \DateTimeImmutable::createFromFormat('!d.m.Y', $datum);
            }
            if ($occurredAt === false) {
                continue;
            }

            $signed = $debit !== '' ? '-' . $debit : $credit;
            $amountMinor = MoneyParser::toMinor($signed, 2);
            $type = $this->classify($buchungstext, $debit !== '');

            if ($zkbRef !== '') {
                $externalRef = 'zkb:' . $zkbRef;
            } else {
                $baseHash = sha1(implode('|', [$datum, $valuta, $buchungstext, $signed]));
                $n = $hashCount[$baseHash] ?? 0;
                $hashCount[$baseHash] = $n + 1;
                $externalRef = 'zkb:' . ($n === 0 ? $baseHash : $baseHash . ':' . $n);
            }

            $descriptionParts = [$buchungstext];
            if ($zweck !== '' && stripos($buchungstext, $zweck) === false) {
                $descriptionParts[] = $zweck;
            }
            if ($details !== '' && stripos($buchungstext, $details) === false) {
                $descriptionParts[] = $details;
            }
            $description = implode(' — ', array_filter($descriptionParts));

            yield new TransactionDraft(
                occurredAt: $occurredAt,
                amountMinor: $amountMinor,
                currency: 'CHF',
                type: $type,
                externalRef: $externalRef,
                description: $description !== '' ? $description : null,
            );
        }
    }

    private function classify(string $buchungstext, bool $isDebit): TransactionType
    {
        $lower = mb_strtolower($buchungstext);

        // "Gebühr ZKB inklusiv Gold", "Kontogebühr", etc. — explicit fees, regardless of sign.
        if (str_contains($lower, 'gebühr') || str_contains($lower, 'gebuehr')) {
            return TransactionType::Fee;
        }
        // "Zinsgutschrift" / "Zinsabschluss" — interest credits or debits.
        if (str_contains($lower, 'zins')) {
            return TransactionType::Interest;
        }

        // Everything else falls into the deposit/withdrawal buckets based on direction —
        // card payments, standing orders, TWINT, eBill, transfers — the description carries
        // the merchant or counterparty for the user, no need to split further.
        return $isDebit ? TransactionType::Withdrawal : TransactionType::Deposit;
    }
}
