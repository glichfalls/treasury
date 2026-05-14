<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514152304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_account_isin ON account_allocations');
        // Add nullable first to seed existing rows, then enforce NOT NULL.
        $this->addSql('ALTER TABLE account_allocations ADD effective_from DATE NULL');
        $this->addSql("UPDATE account_allocations SET effective_from = '1970-01-01' WHERE effective_from IS NULL");
        $this->addSql('ALTER TABLE account_allocations MODIFY effective_from DATE NOT NULL');
        $this->addSql('CREATE INDEX idx_alloc_account_date ON account_allocations (account_id, effective_from)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_alloc_account_date ON account_allocations');
        $this->addSql('ALTER TABLE account_allocations DROP effective_from');
        $this->addSql('CREATE UNIQUE INDEX uniq_account_isin ON account_allocations (account_id, asset_isin)');
    }
}
