<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill the new opening_balance transaction type for rows that were created
 * before it existed. We identify them by the description that OpeningBalanceForm
 * has always written, since that's the only signal in old data.
 */
final class Version20260516160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert legacy "Opening balance" deposits to opening_balance type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE transactions SET type = 'opening_balance'
             WHERE type = 'deposit' AND LOWER(description) = 'opening balance'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE transactions SET type = 'deposit'
             WHERE type = 'opening_balance' AND LOWER(description) = 'opening balance'"
        );
    }
}
