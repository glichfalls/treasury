<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair the transactions.tags column on databases where Version20260515145849
 * ran against existing rows.
 *
 * That migration added `tags JSON NOT NULL` with no default. MariaDB stores
 * JSON as LONGTEXT with an implicit CHECK constraint (named `transactions.tags`)
 * that runs json_valid(). The CHECK is not enforced retroactively on ALTER, so
 * pre-existing rows ended up with empty-string tags. Any subsequent UPDATE on
 * those rows then trips the CHECK because the post-update row still has the
 * invalid value, blocking all later data migrations.
 *
 * Backfill invalid rows with '[]' so the post-update CHECK passes, then
 * realign the column to nullable to match the entity definition.
 */
final class Version20260516120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair transactions.tags: backfill invalid JSON and allow NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE transactions SET tags = '[]' WHERE NOT JSON_VALID(tags)");
        $this->addSql('ALTER TABLE transactions MODIFY tags JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE transactions SET tags = '[]' WHERE tags IS NULL");
        $this->addSql('ALTER TABLE transactions MODIFY tags JSON NOT NULL');
    }
}
