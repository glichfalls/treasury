<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add brief column to news_items (in-depth AI analysis)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items ADD brief LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_items DROP COLUMN brief');
    }
}
