<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Configures only the next owned root; never starts or commits a transaction. */
final class ShipmentCostReadIsolation
{
    public static function prepare(array $shipmentScope): ?\Closure
    {
        if (!$shipmentScope || !config('shipment_cost_read.enabled')) return null;
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        if ($connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new \RuntimeException('COST_READ_ISOLATION_REQUIRES_ROOT_ENTRY');
        }
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('COST_READ_ENGINE_REQUIRED');
        }
        // All metadata is read on the actual future owner's PDO, not a read replica.
        if ((int) $pdo->query('SELECT @@session.foreign_key_checks')->fetchColumn() !== 1) {
            throw new \RuntimeException('COST_READ_FK_CHECKS_REQUIRED');
        }
        $foreignKey = $pdo->query("SELECT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='shipment_costs' AND k.COLUMN_NAME='shipment_id'
              AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='shipments' AND k.REFERENCED_COLUMN_NAME='id'
              AND r.DELETE_RULE IN ('RESTRICT','NO ACTION') AND r.UPDATE_RULE IN ('RESTRICT','NO ACTION')
              AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE x WHERE x.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND x.TABLE_NAME=k.TABLE_NAME AND x.CONSTRAINT_NAME=k.CONSTRAINT_NAME)=1")->fetchColumn();
        if (!$foreignKey) throw new \RuntimeException('COST_READ_PARENT_FK_REQUIRED');
        $engines = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('shipment_costs','shipments') AND ENGINE='InnoDB'")->fetchColumn();
        if ((int) $engines !== 2) throw new \RuntimeException('COST_READ_INNODB_REQUIRED');
        $default = str_replace('-', ' ', (string) $pdo->query('SELECT @@session.tx_isolation')->fetchColumn());
        if (!in_array($default, ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) {
            throw new \RuntimeException('COST_READ_ISOLATION_UNKNOWN');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return static function () use ($connection, $pdo, $default): void {
            if ($connection->getPdo() !== $pdo || $connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
                throw new \RuntimeException('COST_READ_ROOT_CLEANUP_UNCONFIRMED');
            }
            // Also clears an unconsumed setting if BEGIN itself failed. No SESSION/GLOBAL change.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL '.$default);
        };
    }
}
