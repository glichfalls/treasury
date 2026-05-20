<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider, provider_config, last_synced_at to accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD provider VARCHAR(32) DEFAULT NULL, ADD provider_config JSON DEFAULT NULL, ADD last_synced_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP COLUMN provider, DROP COLUMN provider_config, DROP COLUMN last_synced_at');
    }
}
