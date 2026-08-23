<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add only the read-path indexes approved for Wave 0B-1.
     */
    public function up(): void
    {
        $this->addIndexUnlessEquivalentExists(
            'shipment_weights',
            'idx_weight_card_active_effective_latest',
            ['weighbridge_card_id', 'cancelled_at', 'effective_weight_type', 'recorded_at', 'id'],
        );

        $this->addIndexUnlessEquivalentExists(
            'journal_entries',
            'idx_journal_company_source',
            ['company_id', 'source_type', 'source_id'],
        );

        $this->addIndexUnlessEquivalentExists(
            'journal_entries',
            'idx_journal_company_year_number',
            ['company_id', 'financial_year_id', 'entry_number'],
        );
    }

    /**
     * Remove only Wave 0B-1 indexes, and only when their definitions match.
     */
    public function down(): void
    {
        $this->dropIndexIfDefinitionMatches(
            'shipment_weights',
            'idx_weight_card_active_effective_latest',
            ['weighbridge_card_id', 'cancelled_at', 'effective_weight_type', 'recorded_at', 'id'],
        );

        $this->dropIndexIfDefinitionMatches(
            'journal_entries',
            'idx_journal_company_source',
            ['company_id', 'source_type', 'source_id'],
        );

        $this->dropIndexIfDefinitionMatches(
            'journal_entries',
            'idx_journal_company_year_number',
            ['company_id', 'financial_year_id', 'entry_number'],
        );
    }

    /**
     * Refuse a conflicting name and avoid duplicating an equivalent prefix.
     */
    private function addIndexUnlessEquivalentExists(string $table, string $name, array $columns): void
    {
        $indexes = $this->indexesFor($table);

        if (array_key_exists($name, $indexes)) {
            throw new RuntimeException(
                "Cannot create index {$name} on {$table}: that name already exists. " .
                'Reconcile the pre-existing index explicitly before retrying this migration.'
            );
        }

        foreach ($indexes as $existingName => $existingColumns) {
            if (array_slice($existingColumns, 0, count($columns)) === $columns) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * Never remove an index whose current definition differs from this wave.
     */
    private function dropIndexIfDefinitionMatches(string $table, string $name, array $columns): void
    {
        $indexes = $this->indexesFor($table);

        if (!array_key_exists($name, $indexes)) {
            return;
        }

        if ($indexes[$name] !== $columns) {
            throw new RuntimeException(
                "Refusing to drop index {$name} on {$table}: its definition does not match Wave 0B-1."
            );
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * Return ordered index columns keyed by index name for the current database.
     */
    private function indexesFor(string $table): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            <<<'SQL'
                SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            SQL,
            [$database, $table],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        return $indexes;
    }
};
