<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515114544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE registration_codes (id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_by_id BINARY(16) NOT NULL, used_by_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_6A0761A577153098 (code), INDEX IDX_6A0761A5B03A8386 (created_by_id), INDEX IDX_6A0761A54C2B72A8 (used_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE registration_codes ADD CONSTRAINT FK_6A0761A5B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE registration_codes ADD CONSTRAINT FK_6A0761A54C2B72A8 FOREIGN KEY (used_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registration_codes DROP FOREIGN KEY FK_6A0761A5B03A8386');
        $this->addSql('ALTER TABLE registration_codes DROP FOREIGN KEY FK_6A0761A54C2B72A8');
        $this->addSql('DROP TABLE registration_codes');
    }
}
