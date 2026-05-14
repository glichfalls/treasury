<?php

namespace App\Import;

interface CsvImporter
{
    /** Stable short identifier, e.g. "ibkr" or "degiro". Used as the externalRef prefix. */
    public function name(): string;

    /** @param string[] $headers Raw header row, in file order. */
    public function supports(array $headers): bool;

    /**
     * @param iterable<array<string, string>|array<int, string>> $rows
     *        Each row is keyed by header name (where headers are unique and non-empty);
     *        rows with empty/duplicate headers will only be accessible by index, so
     *        importers should not assume associative access.
     * @return iterable<TransactionDraft>
     */
    public function parse(iterable $rows): iterable;
}
