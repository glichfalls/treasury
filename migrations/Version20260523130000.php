<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_settings table for admin-managed configuration (provider API keys)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_settings (id BINARY(16) NOT NULL, name VARCHAR(64) NOT NULL, value LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_app_settings_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_settings');
    }
}
