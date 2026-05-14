<?php

namespace App\Import;

use App\Entity\TransactionType;

final class TransactionDraft
{
    public function __construct(
        public readonly \DateTimeImmutable $occurredAt,
        /** Minor units as a signed decimal string, e.g. "-768792" for -7687.92. */
        public readonly string $amountMinor,
        public readonly string $currency,
        public readonly TransactionType $type,
        public readonly string $externalRef,
        public readonly ?string $description = null,
        public readonly ?string $assetIsin = null,
        public readonly ?string $assetQuantity = null,
        /** Hint for ticker (if the source provides one) — used to seed Asset row. */
        public readonly ?string $assetTicker = null,
        /** Hint for asset name — used to seed Asset row. */
        public readonly ?string $assetName = null,
    ) {}
}
