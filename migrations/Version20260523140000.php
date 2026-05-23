<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snippet column to news_items (raw provider excerpt)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items ADD snippet LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items DROP COLUMN snippet');
    }
}
