<?php

namespace App\Import;

final class ImportResult
{
    /** @param array{updated:int,skipped:int,errors:string[]}|null $priceRefresh */
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        /** @var string[] */
        public readonly array $errors = [],
        public readonly ?string $importer = null,
        public readonly ?array $priceRefresh = null,
    ) {}

    public function withPriceRefresh(array $priceRefresh): self
    {
        return new self($this->imported, $this->skipped, $this->errors, $this->importer, $priceRefresh);
    }

    public function toArray(): array
    {
        return [
            'importer' => $this->importer,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'priceRefresh' => $this->priceRefresh,
        ];
    }
}
