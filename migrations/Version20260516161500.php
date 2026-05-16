<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair opening-balance fan-out rows whose date drifted from their deposit.
 *
 * Before TransactionController cascaded date edits, editing an opening_balance
 * deposit's date left the auto-generated "3a allocation:" trade_buy/trade_sell
 * rows behind on the original date. This migration realigns them: for each
 * opening_balance that has no trades on its date, any "3a allocation:" trades
 * on the same account whose own date has no deposit/opening_balance are moved
 * onto the opening_balance's date.
 */
final class Version20260516161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Realign orphaned 3a allocation trades with their opening_balance';
    }

    public function up(Schema $schema): void
    {
        // MySQL forbids referencing the target table in any subquery of UPDATE,
        // even via a derived table. Collect the IDs into a temp table first,
        // then drive the UPDATE off that.
        $this->addSql('DROP TEMPORARY TABLE IF EXISTS _orphan_3a_trades');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE _orphan_3a_trades (
                id BINARY(16) PRIMARY KEY,
                target_date DATE NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO _orphan_3a_trades (id, target_date)
            SELECT t.id, opening.occurred_at
            FROM transactions t
            INNER JOIN transactions opening
                ON opening.account_id = t.account_id
                AND opening.type = 'opening_balance'
            WHERE t.type IN ('trade_buy', 'trade_sell')
              AND t.description LIKE '3a allocation:%'
              AND t.occurred_at <> opening.occurred_at
              -- The opening_balance row has no trades on its date (nothing to clash with).
              AND NOT EXISTS (
                  SELECT 1 FROM transactions x
                  WHERE x.account_id = opening.account_id
                    AND x.occurred_at = opening.occurred_at
                    AND x.type IN ('trade_buy', 'trade_sell')
              )
              -- The trade's current date has no deposit/opening_balance anchoring
              -- it (i.e. it's truly orphaned, not part of a separate contribution).
              AND NOT EXISTS (
                  SELECT 1 FROM transactions y
                  WHERE y.account_id = t.account_id
                    AND y.occurred_at = t.occurred_at
                    AND y.type IN ('deposit', 'opening_balance')
              )
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE transactions t
            INNER JOIN _orphan_3a_trades o ON o.id = t.id
            SET t.occurred_at = o.target_date
        SQL);
        $this->addSql('DROP TEMPORARY TABLE _orphan_3a_trades');
    }

    public function down(Schema $schema): void
    {
        // No safe inverse — the original "wrong" dates aren't recoverable.
        $this->throwIrreversibleMigrationException();
    }
}
