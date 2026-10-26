<?php

// REVIEW COPY ONLY: deliberately outside database/migrations, so migrate cannot discover it.
// Do not move or execute before explicit schema/policy approval and isolated MySQL tests.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['invoice_accounting_snapshots', 'invoice_line_accounting_snapshots', 'voucher_invoice_allocations'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('This review migration requires MySQL/MariaDB with enforced CHECK constraints.');
        }
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        $maria = stripos($version, 'MariaDB') !== false;
        preg_match('/\d+\.\d+\.\d+/', preg_replace('/^5\.5\.5-/', '', $version), $matches);
        if (!$matches || version_compare($matches[0], $maria ? '10.2.1' : '8.0.16', '<')) {
            throw new RuntimeException('Enforced CHECK support must be verified before execution.');
        }
        // DDL is not transactionally rolled back by MySQL. Never skip partially created tables.
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException('Existing/partial historical accounting schema requires explicit review: '.$table);
            }
        }
        $expected = ['companies'=>'bigint', 'branches'=>'bigint unsigned', 'users'=>'bigint unsigned',
            'accounts'=>'bigint unsigned', 'items'=>'bigint unsigned', 'journal_entries'=>'bigint unsigned',
            'journal_entry_lines'=>'bigint unsigned', 'vouchers'=>'bigint', 'customers'=>'bigint unsigned',
            'suppliers'=>'bigint unsigned', 'sales_invoices'=>'bigint unsigned', 'purchase_invoices'=>'bigint unsigned',
            'sales_invoice_lines'=>'bigint unsigned', 'purchase_invoice_lines'=>'bigint unsigned'];
        foreach ($expected as $table=>$type) {
            $engine = DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (!$engine || strtoupper($engine->ENGINE) !== 'INNODB') throw new RuntimeException('InnoDB required: '.$table);
            $column = DB::selectOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'id']);
            $actual = $column ? preg_replace('/\(\d+\)/', '', strtolower($column->COLUMN_TYPE)) : '';
            if ($actual !== $type) throw new RuntimeException('Unexpected key type: '.$table);
        }
        $sql = file_get_contents(__DIR__.'/historical_invoice_accounting.sql');
        if ($sql === false) throw new RuntimeException('Missing reviewed SQL proposal.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) DB::unprepared($statement);
        foreach (require __DIR__.'/historical_invoice_accounting_triggers.php' as $statement) DB::unprepared($statement);
    }

    public function down(): void
    {
        // Once financial evidence exists, rollback application code, not financial evidence.
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Rollback refused: retain populated historical accounting evidence.');
            }
        }
        foreach (array_reverse($this->tables) as $table) Schema::dropIfExists($table);
    }
};
