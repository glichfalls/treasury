<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add news_items table and news_enabled / news_market_topic columns to assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE news_items (id BINARY(16) NOT NULL, source VARCHAR(32) NOT NULL, kind VARCHAR(16) DEFAULT \'headline\' NOT NULL, title VARCHAR(512) NOT NULL, url VARCHAR(1024) NOT NULL, publisher VARCHAR(200) DEFAULT NULL, summary LONGTEXT DEFAULT NULL, sentiment VARCHAR(8) DEFAULT NULL, published_at DATETIME NOT NULL, content_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, asset_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_news_asset_content (asset_id, content_hash), INDEX idx_news_asset_published (asset_id, published_at), INDEX idx_news_published (published_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE news_items ADD CONSTRAINT FK_news_items_asset FOREIGN KEY (asset_id) REFERENCES assets (id)');
        $this->addSql('ALTER TABLE assets ADD news_enabled TINYINT(1) DEFAULT 1 NOT NULL, ADD news_market_topic VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items DROP FOREIGN KEY FK_news_items_asset');
        $this->addSql('DROP TABLE news_items');
        $this->addSql('ALTER TABLE assets DROP COLUMN news_enabled, DROP COLUMN news_market_topic');
    }
}
