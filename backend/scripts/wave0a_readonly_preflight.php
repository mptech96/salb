<?php

declare(strict_types=1);

/**
 * SULB ERP Wave 0A preflight (READ ONLY).
 *
 * Uses information_schema and SELECT statements only, inside a READ ONLY
 * transaction. It never boots Laravel and cannot mutate application data.
 */

function envFile(string $path): array
{
    $out = [];
    foreach (is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $out[trim($key)] = $value;
    }
    return $out;
}

function cfg(array $env, string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? ($env[$key] ?? $default) : $value;
}

function selectRows(PDO $pdo, string $sql, array $params = []): array
{
    if (!str_starts_with(strtoupper(ltrim($sql)), 'SELECT')) {
        throw new RuntimeException('Rejected non-SELECT statement.');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function existsTable(PDO $pdo, string $db, string $table): bool
{
    return selectRows($pdo, 'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$db, $table])[0]['n'] > 0;
}

function columns(PDO $pdo, string $db, string $table): array
{
    return selectRows($pdo, 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$db, $table]);
}

$env = envFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
$db = cfg($env, 'DB_DATABASE');
$user = cfg($env, 'DB_USERNAME');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', cfg($env, 'DB_HOST', '127.0.0.1'), cfg($env, 'DB_PORT', '3306'), $db),
    $user,
    cfg($env, 'DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();

try {
    $tables = ['shipment_weights','sales_line_lot_sources','journal_entries'];
    $indexes = [];
    foreach ($tables as $table) {
        $indexes[$table] = selectRows($pdo, 'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, CARDINALITY, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$db, $table]);
    }

    $profiles = [
        'shipment_weights' => selectRows($pdo, 'SELECT COUNT(*) rows_total, COUNT(weighbridge_card_id) non_null_card, COUNT(DISTINCT weighbridge_card_id) distinct_cards, SUM(cancelled_at IS NULL) active_rows, COUNT(DISTINCT effective_weight_type) distinct_weight_types FROM shipment_weights'),
        'sales_line_lot_sources' => selectRows($pdo, 'SELECT COUNT(*) rows_total, SUM(company_id IS NULL) null_company, SUM(sales_invoice_line_id IS NULL) null_sales_line, SUM(inventory_lot_id IS NULL) null_lot, COUNT(DISTINCT company_id) distinct_companies, COUNT(DISTINCT sales_invoice_line_id) distinct_sales_lines, COUNT(DISTINCT inventory_lot_id) distinct_lots FROM sales_line_lot_sources'),
        'journal_entries' => selectRows($pdo, 'SELECT COUNT(*) rows_total, SUM(company_id IS NULL) null_company, SUM(financial_year_id IS NULL) null_year, SUM(entry_number IS NULL) null_entry_number, SUM(source_type IS NULL) null_source_type, SUM(source_id IS NULL) null_source_id, COUNT(DISTINCT company_id) distinct_companies, COUNT(DISTINCT financial_year_id) distinct_years, COUNT(DISTINCT source_type) distinct_source_types FROM journal_entries'),
    ];

    $duplicates = [
        'journal_entry_number' => selectRows($pdo, 'SELECT company_id, financial_year_id, entry_number, COUNT(*) duplicate_count FROM journal_entries GROUP BY company_id, financial_year_id, entry_number HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC LIMIT 100'),
        'journal_source' => selectRows($pdo, 'SELECT company_id, source_type, source_id, COUNT(*) duplicate_count FROM journal_entries WHERE source_type IS NOT NULL AND source_id IS NOT NULL GROUP BY company_id, source_type, source_id HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC LIMIT 100'),
    ];

    $orphans = [
        'shipment_weights_to_cards' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM shipment_weights c LEFT JOIN weighbridge_cards p ON p.id=c.weighbridge_card_id AND p.company_id=c.company_id WHERE c.weighbridge_card_id IS NOT NULL AND p.id IS NULL'),
        'sale_sources_to_lines' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM sales_line_lot_sources c LEFT JOIN sales_invoice_lines p ON p.id=c.sales_invoice_line_id AND p.company_id=c.company_id WHERE c.sales_invoice_line_id IS NOT NULL AND p.id IS NULL'),
        'sale_sources_to_lots' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM sales_line_lot_sources c LEFT JOIN inventory_lots p ON p.id=c.inventory_lot_id AND p.company_id=c.company_id WHERE c.inventory_lot_id IS NOT NULL AND p.id IS NULL'),
        'return_sources_to_lines' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM commercial_return_lot_sources c LEFT JOIN commercial_return_lines p ON p.id=c.return_line_id AND p.company_id=c.company_id WHERE c.return_line_id IS NOT NULL AND p.id IS NULL'),
        'return_sources_to_lots' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM commercial_return_lot_sources c LEFT JOIN inventory_lots p ON p.id=c.inventory_lot_id AND p.company_id=c.company_id WHERE c.inventory_lot_id IS NOT NULL AND p.id IS NULL'),
        'operation_links_to_operations' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM inventory_operation_lot_links c LEFT JOIN inventory_operations p ON p.id=c.operation_id AND p.company_id=c.company_id WHERE c.operation_id IS NOT NULL AND p.id IS NULL'),
        'operation_links_to_lines' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM inventory_operation_lot_links c LEFT JOIN inventory_operation_lines p ON p.id=c.operation_line_id AND p.company_id=c.company_id WHERE c.operation_line_id IS NOT NULL AND p.id IS NULL'),
        'operation_links_to_source_lots' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM inventory_operation_lot_links c LEFT JOIN inventory_lots p ON p.id=c.source_lot_id AND p.company_id=c.company_id WHERE c.source_lot_id IS NOT NULL AND p.id IS NULL'),
        'operation_links_to_produced_lots' => selectRows($pdo, 'SELECT COUNT(*) orphan_count FROM inventory_operation_lot_links c LEFT JOIN inventory_lots p ON p.id=c.produced_lot_id AND p.company_id=c.company_id WHERE c.produced_lot_id IS NOT NULL AND p.id IS NULL'),
    ];
    $integrityCardinality = [];
    foreach (['shipment_weights','weighbridge_cards','sales_line_lot_sources','sales_invoice_lines','inventory_lots','commercial_return_lot_sources','commercial_return_lines','inventory_operation_lot_links','inventory_operations','inventory_operation_lines','journal_entries'] as $table) {
        $integrityCardinality[$table] = selectRows($pdo, "SELECT COUNT(*) row_count FROM `{$table}`")[0]['row_count'];
    }

    $sourceTypes = selectRows($pdo, 'SELECT source_type, COUNT(*) row_count, COUNT(DISTINCT source_id) distinct_source_ids, SUM(source_id IS NULL) null_source_ids FROM journal_entries GROUP BY source_type ORDER BY row_count DESC');
    $sourceMap = [
        'PURCHASE' => 'purchase_invoices',
        'PURCHASE_INVOICE' => 'purchase_invoices',
        'SHIPMENT_COST' => 'shipment_costs',
        'PAYROLL' => 'worker_salary_runs',
        'PAYROLL_PAYMENT' => 'worker_salary_runs',
        'WORKER_LOAN' => 'worker_loans',
        'WORKER_COMMISSION' => 'worker_commissions',
        'WORKER_COMMISSION_PAYMENT' => 'worker_commissions',
        'PURCHASE_REVERSAL' => 'journal_entries',
    ];
    $sourceIntegrity = [];
    foreach ($sourceMap as $sourceType => $parentTable) {
        if (!existsTable($pdo, (string)$db, $parentTable)) {
            $sourceIntegrity[$sourceType] = ['parent_table' => $parentTable, 'check' => 'parent table absent'];
            continue;
        }
        $sourceIntegrity[$sourceType] = [
            'parent_table' => $parentTable,
            'result' => selectRows($pdo, "SELECT COUNT(*) checked_rows, SUM(p.id IS NULL) orphan_count FROM journal_entries e LEFT JOIN `{$parentTable}` p ON p.id=e.source_id AND p.company_id=e.company_id WHERE e.source_type=? AND e.source_id IS NOT NULL", [$sourceType]),
        ];
    }

    $migrationTables = ['users','password_reset_tokens','sessions','cache','cache_locks','jobs','job_batches','failed_jobs','shipments'];
    $migrationState = [];
    foreach ($migrationTables as $table) {
        $migrationState[$table] = ['exists' => existsTable($pdo, (string)$db, $table), 'columns' => columns($pdo, (string)$db, $table)];
    }
    $migrationState['inventory_permission'] = selectRows($pdo, "SELECT p.id permission_id, p.permission_code, r.id role_id, r.role_code, rp.company_id, rp.is_active FROM permissions p CROSS JOIN roles r LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND rp.role_id=r.id AND rp.company_id IS NULL WHERE p.permission_code='inventory.view' AND r.role_code='STORE'");
    $migrationState['pending_rows'] = selectRows($pdo, "SELECT migration, batch FROM migrations WHERE migration IN ('0001_01_01_000000_create_users_table','0001_01_01_000001_create_cache_table','0001_01_01_000002_create_jobs_table','2026_06_20_163416_create_shipments_table','2026_08_12_000008_fix_store_inventory_permission') ORDER BY migration");

    $report = compact('indexes','profiles','duplicates','orphans','integrityCardinality','sourceTypes','sourceIntegrity','migrationState');
    $report['safety'] = ['transaction' => 'READ ONLY', 'statements' => 'SELECT/information_schema only', 'database_writes' => false];
    $pdo->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, json_encode(['error' => $e->getMessage(), 'database_writes' => false], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(2);
}
