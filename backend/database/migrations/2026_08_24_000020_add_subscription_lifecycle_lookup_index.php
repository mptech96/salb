<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'subscriptions';
    private const INDEX = 'idx_subscriptions_company_lifecycle_dates';
    private const COLUMNS = ['company_id', 'status', 'start_date', 'end_date', 'id'];

    public function up(): void
    {
        $indexes = $this->indexes();

        if (array_key_exists(self::INDEX, $indexes)) {
            throw new RuntimeException(
                'The Wave 1 subscription lifecycle index name already exists; reconcile it before retrying.'
            );
        }

        foreach ($indexes as $columns) {
            if (array_slice($columns, 0, count(self::COLUMNS)) === self::COLUMNS) {
                return;
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    public function down(): void
    {
        $indexes = $this->indexes();
        if (!array_key_exists(self::INDEX, $indexes)) {
            return;
        }

        if ($indexes[self::INDEX] !== self::COLUMNS) {
            throw new RuntimeException(
                'Refusing to drop the Wave 1 index because its definition has changed.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexes(): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            SQL,
            [DB::connection()->getDatabaseName(), self::TABLE],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        return $indexes;
    }
};
