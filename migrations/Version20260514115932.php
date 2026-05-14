<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514115932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE accounts (id BINARY(16) NOT NULL, name VARCHAR(120) NOT NULL, institution VARCHAR(120) DEFAULT NULL, type VARCHAR(32) NOT NULL, currency VARCHAR(3) NOT NULL, created_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_CAC89EAC7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assets (id BINARY(16) NOT NULL, isin VARCHAR(32) NOT NULL, ticker VARCHAR(32) DEFAULT NULL, name VARCHAR(200) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, UNIQUE INDEX UNIQ_79D17D8E2FE82D2D (isin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fx_rates (id BINARY(16) NOT NULL, occurred_at DATE NOT NULL, from_currency VARCHAR(3) NOT NULL, to_currency VARCHAR(3) NOT NULL, rate NUMERIC(18, 8) NOT NULL, INDEX idx_fx_pair_date (from_currency, to_currency, occurred_at), UNIQUE INDEX uniq_fx_date_pair (occurred_at, from_currency, to_currency), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE prices (id BINARY(16) NOT NULL, occurred_at DATE NOT NULL, price_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, asset_id BINARY(16) NOT NULL, INDEX IDX_E4CB6D595DA1941 (asset_id), UNIQUE INDEX uniq_prices_asset_date (asset_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transactions (id BINARY(16) NOT NULL, occurred_at DATE NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, description VARCHAR(255) DEFAULT NULL, type VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL, external_ref VARCHAR(120) DEFAULT NULL, asset_isin VARCHAR(32) DEFAULT NULL, asset_quantity NUMERIC(24, 8) DEFAULT NULL, account_id BINARY(16) NOT NULL, INDEX IDX_EAA81A4C9B6B5FBA (account_id), INDEX idx_transactions_account_date (account_id, occurred_at), UNIQUE INDEX uniq_transactions_account_extref (account_id, external_ref), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE accounts ADD CONSTRAINT FK_CAC89EAC7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE prices ADD CONSTRAINT FK_E4CB6D595DA1941 FOREIGN KEY (asset_id) REFERENCES assets (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C9B6B5FBA FOREIGN KEY (account_id) REFERENCES accounts (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accounts DROP FOREIGN KEY FK_CAC89EAC7E3C61F9');
        $this->addSql('ALTER TABLE prices DROP FOREIGN KEY FK_E4CB6D595DA1941');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4C9B6B5FBA');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE assets');
        $this->addSql('DROP TABLE fx_rates');
        $this->addSql('DROP TABLE prices');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
