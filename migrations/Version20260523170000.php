<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add news_digests table (AI daily briefing)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE news_digests (id BINARY(16) NOT NULL, generated_at DATETIME NOT NULL, period_start DATETIME NOT NULL, period_end DATETIME NOT NULL, content LONGTEXT NOT NULL, item_count INT NOT NULL, owner_id BINARY(16) NOT NULL, INDEX idx_news_digests_owner_generated (owner_id, generated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE news_digests ADD CONSTRAINT FK_news_digests_owner FOREIGN KEY (owner_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_digests DROP FOREIGN KEY FK_news_digests_owner');
        $this->addSql('DROP TABLE news_digests');
    }
}
