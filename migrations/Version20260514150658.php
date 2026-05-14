<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514150658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account_allocations (id BINARY(16) NOT NULL, asset_isin VARCHAR(32) NOT NULL, percent_basis_points INT NOT NULL, account_id BINARY(16) NOT NULL, INDEX IDX_11AEBEFD9B6B5FBA (account_id), UNIQUE INDEX uniq_account_isin (account_id, asset_isin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_allocations ADD CONSTRAINT FK_11AEBEFD9B6B5FBA FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_allocations DROP FOREIGN KEY FK_11AEBEFD9B6B5FBA');
        $this->addSql('DROP TABLE account_allocations');
    }
}
