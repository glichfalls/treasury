<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reddit_subreddit column to assets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assets ADD reddit_subreddit VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assets DROP COLUMN reddit_subreddit');
    }
}
