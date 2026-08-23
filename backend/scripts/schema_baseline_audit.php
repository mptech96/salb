<?php

declare(strict_types=1);

/**
 * SULB ERP schema baseline audit (READ ONLY).
 *
 * Usage from backend/:
 *   php scripts/schema_baseline_audit.php
 *   php scripts/schema_baseline_audit.php > schema-audit.json
 *
 * The script does not boot Laravel and executes SELECT/metadata statements only.
 * It reads connection settings from the process environment, falling back to .env.
 */

const EXPECTED_SCHEMA = [
    'companies' => ['id','legal_name','tax_number','country_code','default_language','timezone'],
    'branches' => ['id','company_id','branch_code','branch_name','is_active'],
    'company_settings' => ['company_id','base_currency_code','default_sales_tax_code_id','default_purchase_tax_code_id','tax_inclusive_prices','shipment_item_tolerance_kg','logo_path','signature_path','stamp_path'],
    'users' => ['id','company_id','branch_id'],
    'roles' => ['id','role_code'],
    'permissions' => ['id','permission_code','module_name'],
    'role_permissions' => ['role_id','permission_id','company_id'],
    'user_permission_overrides' => ['company_id','user_id','permission_id','effect'],
    'items' => ['id','company_id','item_code','item_name','item_type','track_inventory','allow_negative_stock','can_purchase','can_sell','costing_method','inventory_account_id','sales_account_id','cogs_account_id','purchase_expense_account_id'],
    'customers' => ['id','company_id','customer_name','ledger_account_id','is_active'],
    'suppliers' => ['id','company_id','supplier_name','ledger_account_id','is_active'],
    'cars' => ['id','company_id','branch_id','plate_number','is_active'],
    'drivers' => ['id','company_id','driver_name','is_active'],
    'shipments' => ['id','company_id','branch_id','shipment_number','shipment_type','status','commercial_status','supplier_id','customer_id','physical_net_weight_kg','accepted_weight_kg','weight_variance_kg','currency_code','exchange_rate','vat_amount','total_amount','ready_at','invoiced_at'],
    'shipment_items' => ['id','company_id','shipment_id','item_id','gross_qty_kg','deduction_qty_kg','accepted_qty_kg','unit_price_per_kg','tax_code_id','tax_rate_snapshot','total_before_vat','vat_amount','total_after_vat','inventory_lot_id','remaining_qty_kg','sold_qty_kg'],
    'weighbridge_cards' => ['id','company_id','branch_id','shipment_id','item_id','card_number','flow_type','direction','status','transport_mode','loaded_weight_kg','empty_weight_kg','net_weight_kg','opened_at','closed_at'],
    'shipment_weights' => ['id','company_id','branch_id','weighbridge_card_id','shipment_id','event_type','effective_weight_type','weight_kg','recorded_at','cancelled_at'],
    'weighbridge_card_item_allocations' => ['id','company_id','shipment_id','weighbridge_card_id','shipment_item_id','item_id','gross_qty_kg'],
    'purchase_invoices' => ['id','company_id','branch_id','supplier_id','invoice_number','invoice_date','document_status','currency_code','exchange_rate','total_before_vat','vat_amount','total_amount','journal_entry_id','posted_at','voided_at'],
    'purchase_invoice_lines' => ['id','company_id','purchase_invoice_id','item_id','shipment_id','shipment_item_id','qty_kg','track_inventory_snapshot','base_total_before_vat','vat_amount'],
    'sales_invoices' => ['id','company_id','branch_id','customer_id','invoice_number','invoice_date','document_status','currency_code','exchange_rate','total_before_vat','vat_amount','total_amount','journal_entry_id','posted_at','voided_at'],
    'sales_invoice_lines' => ['id','company_id','sales_invoice_id','item_id','qty_kg','track_inventory_snapshot','base_total_before_vat','vat_amount'],
    'invoice_shipment_links' => ['id','company_id','invoice_type','invoice_id','shipment_id'],
    'inventory_lots' => ['id','company_id','branch_id','item_id','lot_number','received_at','qty_received_kg','qty_remaining_kg','qty_sold_kg','base_cost','allocated_cost','total_cost','unit_cost_per_kg','lot_status','purchase_invoice_id','purchase_invoice_line_id'],
    'inventory_lot_movements' => ['id','company_id','branch_id','inventory_lot_id','item_id','movement_type','source_type','source_id','qty_kg','unit_cost_per_kg','total_cost'],
    'sales_line_lot_sources' => ['id','company_id','sales_invoice_line_id','inventory_lot_id','qty_kg','unit_cost_per_kg','total_cost'],
    'stock_movements' => ['id','company_id','branch_id','item_id','inventory_lot_id','movement_type','source_type','source_id','qty_kg','unit_cost_per_kg','total_cost','journal_entry_id'],
    'inventory_operations' => ['id','company_id','operation_number','operation_type','status','from_branch_id','to_branch_id','input_weight_kg','output_weight_kg','journal_entry_id'],
    'inventory_operation_lines' => ['id','company_id','operation_id','line_type','item_id','qty_kg','unit_cost_per_kg','total_cost','input_lot_id','output_lot_id'],
    'inventory_operation_lot_links' => ['id','company_id','operation_id','operation_line_id','source_lot_id','produced_lot_id','qty_kg','total_cost'],
    'commercial_returns' => ['id','company_id','branch_id','return_type','document_status','source_invoice_id','total_before_vat','vat_amount','total_amount','journal_entry_id','posted_at','voided_at'],
    'commercial_return_lines' => ['id','company_id','return_id','source_invoice_line_id','item_id','qty_kg','total_before_vat','vat_amount','total_after_vat','inventory_cost'],
    'commercial_return_lot_sources' => ['id','company_id','return_line_id','inventory_lot_id','qty_kg','total_cost'],
    'tax_codes' => ['id','company_id','tax_code','rate','is_exempt','is_out_of_scope','sales_tax_account_id','purchase_tax_account_id','valid_from','valid_to','is_active'],
    'accounts' => ['id','company_id','account_code','account_name','is_group','allow_posting','is_active'],
    'accounting_settings' => ['company_id','setting_key','account_id'],
    'financial_years' => ['id','company_id','start_date','end_date','is_closed'],
    'journal_entries' => ['id','company_id','branch_id','financial_year_id','entry_number','entry_date','status','source_type','source_id','reversal_of_id','reversed_by_id'],
    'journal_entry_lines' => ['id','journal_entry_id','account_id','branch_id','cost_center_id','party_type','party_id','debit','credit'],
    'shipment_costs' => ['id','company_id','shipment_id','amount','currency_code','exchange_rate','capitalizable','cost_status','journal_entry_id','distributed'],
    'sulb_document_sequences' => ['id','company_id','branch_id','document_type','document_year','next_number'],
    'migrations' => ['id','migration','batch'],
];

const EXPECTED_INDEX_PREFIXES = [
    'inventory_lots' => [['company_id','branch_id','item_id'], ['company_id','lot_number']],
    'weighbridge_cards' => [['company_id','branch_id','status'], ['company_id','card_number']],
    'shipment_weights' => [['weighbridge_card_id','cancelled_at','effective_weight_type','recorded_at','id'], ['company_id','shipment_id']],
    'invoice_shipment_links' => [['company_id','invoice_type','invoice_id'], ['company_id','shipment_id']],
    'sales_line_lot_sources' => [['company_id','sales_invoice_line_id'], ['company_id','inventory_lot_id']],
    'journal_entries' => [['company_id','financial_year_id','entry_number'], ['company_id','source_type','source_id']],
    'journal_entry_lines' => [['journal_entry_id'], ['account_id']],
];

function loadEnvFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) return [];
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

function setting(array $env, string $key, ?string $default = null): ?string
{
    $process = getenv($key);
    return $process !== false ? $process : ($env[$key] ?? $default);
}

function query(PDO $pdo, string $sql, array $params = []): array
{
    $normalized = strtoupper(ltrim($sql));
    if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'SHOW')) {
        throw new RuntimeException('Safety guard rejected a non-read-only SQL statement.');
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function jsonOut(array $report, int $exitCode): never
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($exitCode);
}

$env = loadEnvFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
$driver = strtolower((string) setting($env, 'DB_CONNECTION', 'mysql'));
if ($driver !== 'mysql') {
    jsonOut(['status'=>'ERROR','message'=>'This baseline auditor currently supports MySQL only.','driver'=>$driver], 2);
}

$host = setting($env, 'DB_HOST', '127.0.0.1');
$port = setting($env, 'DB_PORT', '3306');
$database = setting($env, 'DB_DATABASE');
$username = setting($env, 'DB_USERNAME');
$password = setting($env, 'DB_PASSWORD', '');
if (!$database || !$username) {
    jsonOut(['status'=>'ERROR','message'=>'DB_DATABASE and DB_USERNAME must be configured.'], 2);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();

    $tableRows = query($pdo, 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]);
    $columnRows = query($pdo, 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]);
    $indexRows = query($pdo, 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$database]);
    $fkRows = query($pdo, 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION', [$database]);

    $tables = [];
    foreach ($tableRows as $row) $tables[$row['TABLE_NAME']] = $row;
    $columns = [];
    foreach ($columnRows as $row) $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    $indexes = [];
    foreach ($indexRows as $row) $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME'];

    $missingTables = [];
    $missingColumns = [];
    foreach (EXPECTED_SCHEMA as $table => $requiredColumns) {
        if (!isset($tables[$table])) {
            $missingTables[] = $table;
            continue;
        }
        foreach ($requiredColumns as $column) {
            if (!isset($columns[$table][$column])) $missingColumns[$table][] = $column;
        }
    }

    $missingIndexPrefixes = [];
    foreach (EXPECTED_INDEX_PREFIXES as $table => $prefixes) {
        if (!isset($tables[$table])) continue;
        foreach ($prefixes as $prefix) {
            $found = false;
            foreach ($indexes[$table] ?? [] as $actual) {
                if (array_slice($actual, 0, count($prefix)) === $prefix) { $found = true; break; }
            }
            if (!$found) $missingIndexPrefixes[$table][] = $prefix;
        }
    }

    $migrationRows = isset($tables['migrations'])
        ? query($pdo, 'SELECT migration, batch FROM migrations ORDER BY id')
        : [];
    $filesystemMigrations = array_map(
        static fn(string $path): string => pathinfo($path, PATHINFO_FILENAME),
        glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php') ?: []
    );
    sort($filesystemMigrations);
    $applied = array_column($migrationRows, 'migration');

    $report = [
        'status' => (!$missingTables && !$missingColumns && !$missingIndexPrefixes) ? 'PASS' : 'DRIFT',
        'safety' => ['transaction'=>'READ ONLY','queries'=>'information_schema plus SELECT migrations','database_writes'=>false],
        'connection' => ['driver'=>'mysql','host'=>$host,'port'=>$port,'database'=>$database],
        'summary' => [
            'actual_table_count'=>count($tables),
            'expected_critical_table_count'=>count(EXPECTED_SCHEMA),
            'missing_table_count'=>count($missingTables),
            'tables_with_missing_columns'=>count($missingColumns),
            'tables_with_missing_index_prefixes'=>count($missingIndexPrefixes),
            'filesystem_migration_count'=>count($filesystemMigrations),
            'applied_migration_count'=>count($applied),
        ],
        'drift' => [
            'missing_tables'=>$missingTables,
            'missing_columns'=>$missingColumns,
            'missing_index_prefixes'=>$missingIndexPrefixes,
            'pending_migrations'=>array_values(array_diff($filesystemMigrations,$applied)),
            'database_only_migrations'=>array_values(array_diff($applied,$filesystemMigrations)),
        ],
        'actual' => [
            'tables'=>$tableRows,
            'columns'=>$columnRows,
            'indexes'=>$indexRows,
            'foreign_keys'=>$fkRows,
            'migrations'=>$migrationRows,
        ],
    ];

    $pdo->rollBack();
    jsonOut($report, $report['status'] === 'PASS' ? 0 : 1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    jsonOut(['status'=>'ERROR','message'=>$e->getMessage(),'database_writes'=>false], 2);
}
