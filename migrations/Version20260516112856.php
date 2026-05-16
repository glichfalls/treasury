<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds is_close to prices so the refresh loop can tell intraday quotes from
 * locked-in daily closes and upgrade the former when the market closes.
 */
final class Version20260516112856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_close flag to prices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices ADD is_close TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices DROP is_close');
    }
}
