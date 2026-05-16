<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516190604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plan_scenarios table for saved retirement-plan inputs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan_scenarios (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, INDEX IDX_23E62D307E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE plan_scenarios ADD CONSTRAINT FK_23E62D307E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_scenarios DROP FOREIGN KEY FK_23E62D307E3C61F9');
        $this->addSql('DROP TABLE plan_scenarios');
    }
}
