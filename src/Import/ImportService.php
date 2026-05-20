<?php

namespace App\Import;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ImportService
{
    /** @param iterable<CsvImporter> $importers */
    public function __construct(
        private readonly iterable $importers,
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactions,
        private readonly AssetUpserter $assets,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function importFromFile(Account $account, string $path): ImportResult
    {
        $csv = Reader::from(new \SplFileObject($path, 'r'));
        // Detect delimiter: IBKR/Degiro use comma, ZKB uses semicolon. League/csv defaults
        // to comma; with the wrong delimiter every row collapses to a single column and the
        // importer detection fails with a confusing error.
        $csv->setDelimiter($this->detectDelimiter($path));

        // Read header row manually — some broker exports (Degiro) have empty-named columns
        // which league/csv's setHeaderOffset rejects as duplicates.
        $records = $csv->getRecords();
        $iter = $records instanceof \Iterator ? $records : new \IteratorIterator($records);
        $iter->rewind();
        if (!$iter->valid()) {
            return new ImportResult(0, 0, ['Empty CSV file']);
        }
        $rawHeaders = array_values($iter->current());
        $iter->next();

        // Replace empty header slots with __col_{index} so positional uniqueness is preserved
        // while real header names remain usable.
        $headers = [];
        foreach ($rawHeaders as $i => $h) {
            $h = trim((string) $h);
            $headers[$i] = $h === '' ? "__col_{$i}" : $h;
        }

        $importer = $this->detectImporter($headers);
        if ($importer === null) {
            return new ImportResult(0, 0, ['Unrecognized CSV format. Headers: ' . implode(', ', $headers)]);
        }

        $rowGenerator = function () use ($iter, $headers): \Generator {
            while ($iter->valid()) {
                $values = array_values($iter->current());
                $row = [];
                foreach ($headers as $i => $name) {
                    $row[$name] = $values[$i] ?? '';
                }
                yield $row;
                $iter->next();
            }
        };

        return $this->importDrafts($account, $importer->parse($rowGenerator()), $importer->name());
    }

    /** @param iterable<TransactionDraft> $drafts */
    public function importDrafts(Account $account, iterable $drafts, string $importerName = 'sync'): ImportResult
    {
        $existing = $this->transactions->findExternalRefsForAccount($account);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($drafts as $i => $draft) {
            if (isset($existing[$draft->externalRef])) {
                $skipped++;
                continue;
            }

            try {
                $t = new Transaction();
                $t->setAccount($account);
                $t->setOccurredAt($draft->occurredAt);
                $t->setAmountMinor($draft->amountMinor);
                $t->setCurrency($draft->currency);
                $t->setDescription($draft->description);
                $t->setType($draft->type);
                $t->setSource(TransactionSource::Import);
                $t->setExternalRef($draft->externalRef);
                $t->setAssetIsin($draft->assetIsin);
                $t->setAssetQuantity($draft->assetQuantity);

                $this->assets->upsert($draft);

                $this->em->persist($t);
                $this->em->flush();
                $existing[$draft->externalRef] = true;
                $imported++;
            } catch (UniqueConstraintViolationException) {
                $this->em->clear(Transaction::class);
                $skipped++;
            } catch (\Throwable $e) {
                $this->logger->error('Import row failed', ['index' => $i, 'error' => $e->getMessage()]);
                $errors[] = "Row {$i}: " . $e->getMessage();
                $this->em->clear(Transaction::class);
            }
        }

        return new ImportResult($imported, $skipped, $errors, $importerName);
    }

    /** @param string[] $headers */
    private function detectImporter(array $headers): ?CsvImporter
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($headers)) {
                return $importer;
            }
        }
        return null;
    }

    /**
     * Sniff the delimiter from the file's first non-empty line by counting candidates.
     * Robust enough for the formats we actually see (comma, semicolon, tab); falls back
     * to comma if the line is empty / unreadable.
     */
    private function detectDelimiter(string $path): string
    {
        $f = @fopen($path, 'r');
        if ($f === false) {
            return ',';
        }
        $firstLine = '';
        while (($line = fgets($f)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $firstLine = $line;
                break;
            }
        }
        fclose($f);
        if ($firstLine === '') {
            return ',';
        }
        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $top = array_key_first($counts);
        return $counts[$top] > 0 ? $top : ',';
    }
}
