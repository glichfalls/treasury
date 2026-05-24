<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset_news_sources (per-asset custom news feeds) + news_items.news_source_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset_news_sources (
            id BINARY(16) NOT NULL,
            asset_id BINARY(16) NOT NULL,
            url VARCHAR(1024) NOT NULL,
            type VARCHAR(16) NOT NULL,
            feed_url VARCHAR(1024) DEFAULT NULL,
            scrape_mode VARCHAR(16) NOT NULL,
            label VARCHAR(200) DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
            etag VARCHAR(255) DEFAULT NULL,
            last_modified VARCHAR(255) DEFAULT NULL,
            last_fetched_at DATETIME DEFAULT NULL,
            last_status VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_ans_asset (asset_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE asset_news_sources ADD CONSTRAINT FK_ans_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE news_items ADD news_source_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE news_items ADD CONSTRAINT FK_news_items_news_source FOREIGN KEY (news_source_id) REFERENCES asset_news_sources (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_news_news_source ON news_items (news_source_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items DROP FOREIGN KEY FK_news_items_news_source');
        $this->addSql('DROP INDEX idx_news_news_source ON news_items');
        $this->addSql('ALTER TABLE news_items DROP news_source_id');
        $this->addSql('ALTER TABLE asset_news_sources DROP FOREIGN KEY FK_ans_asset');
        $this->addSql('DROP TABLE asset_news_sources');
    }
}
