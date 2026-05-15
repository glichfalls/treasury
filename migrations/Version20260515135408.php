<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515135408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recurring_transactions (id BINARY(16) NOT NULL, description VARCHAR(120) NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, type VARCHAR(16) NOT NULL, category VARCHAR(32) DEFAULT NULL, frequency VARCHAR(16) NOT NULL, day_of_month SMALLINT DEFAULT NULL, day_of_week SMALLINT DEFAULT NULL, month_of_year SMALLINT DEFAULT NULL, starts_at DATE NOT NULL, ends_at DATE DEFAULT NULL, active TINYINT NOT NULL, last_generated_at DATE DEFAULT NULL, created_at DATETIME NOT NULL, account_id BINARY(16) NOT NULL, INDEX IDX_2468994C9B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE recurring_transactions ADD CONSTRAINT FK_2468994C9B6B5FBA FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recurring_transactions DROP FOREIGN KEY FK_2468994C9B6B5FBA');
        $this->addSql('DROP TABLE recurring_transactions');
    }
}
