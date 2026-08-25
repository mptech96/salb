<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const WAVE6_LOT_IDS = [1, 2, 3, 4, 5, 6, 7];
const WAVE6_BRANCH_RECORDS = ['INVENTORY_LOT:3', 'INVENTORY_LOT:4', 'PURCHASE_INVOICE:3', 'PURCHASE_INVOICE:4'];
const WAVE6_MARKER_PREFIX = 'WAVE6_LEGACY_OPENING_RECONCILIATION:LOT:';
const WAVE6_APPROVED_MANIFEST_SHA256 = 'fb8b822052cbc195718316328595035d55202a9309c23095998669c2b83e7f0b';
const WAVE6_APPROVED_LOT_BASELINE = [
    1 => ['company_id' => 1, 'branch_id' => 1, 'qty_remaining_kg' => '1000.000', 'unit_cost_per_kg' => '0.020000', 'total_cost' => '20.000', 'movement_at' => '2026-06-13 00:00:00'],
    2 => ['company_id' => 1, 'branch_id' => 1, 'qty_remaining_kg' => '1000000.000', 'unit_cost_per_kg' => '0.030000', 'total_cost' => '30000.000', 'movement_at' => '2026-06-13 00:00:00'],
    3 => ['company_id' => 4, 'branch_id' => 1, 'qty_remaining_kg' => '12000000.000', 'unit_cost_per_kg' => '0.031000', 'total_cost' => '372000.000', 'movement_at' => '2026-06-18 00:00:00'],
    4 => ['company_id' => 4, 'branch_id' => 1, 'qty_remaining_kg' => '250080000.000', 'unit_cost_per_kg' => '1.250000', 'total_cost' => '312600000.000', 'movement_at' => '2026-06-19 00:00:00'],
    5 => ['company_id' => 4, 'branch_id' => 6, 'qty_remaining_kg' => '4000000.000', 'unit_cost_per_kg' => '0.000000', 'total_cost' => '0.000', 'movement_at' => '2026-06-20 00:00:00'],
    6 => ['company_id' => 4, 'branch_id' => 6, 'qty_remaining_kg' => '11784000.000', 'unit_cost_per_kg' => '0.004984', 'total_cost' => '58735.006', 'movement_at' => '2026-06-20 00:00:00'],
    7 => ['company_id' => 4, 'branch_id' => 6, 'qty_remaining_kg' => '6000000.000', 'unit_cost_per_kg' => '0.000000', 'total_cost' => '0.000', 'movement_at' => '2026-06-20 00:00:00'],
];

function guard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function beforeHash(array $before): string
{
    return hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function sameDecimal(mixed $left, mixed $right, int $precision = 3): bool
{
    return number_format((float) $left, $precision, '.', '') === number_format((float) $right, $precision, '.', '');
}

function loadManifest(string $path): array
{
    guard(is_file($path) && is_readable($path), 'Discovery manifest does not exist or is not readable.');
    $raw = file_get_contents($path);
    guard($raw !== false, 'Discovery manifest could not be read.');
    $hash = hash('sha256', $raw);
    guard(hash_equals(WAVE6_APPROVED_MANIFEST_SHA256, $hash), 'Manifest SHA-256 does not match the explicitly approved execution baseline.');
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    guard(($manifest['manifest_version'] ?? null) === 1, 'Unsupported or missing manifest version.');
    guard(($manifest['mode'] ?? null) === 'MYSQL_READ_ONLY_DISCOVERY', 'Manifest is not a read-only discovery manifest.');
    guard(($manifest['database'] ?? null) === DB::connection()->getDatabaseName(), 'Manifest database does not match the active connection.');
    guard(($manifest['summary']['auto_safe'] ?? null) === 11, 'Manifest must contain exactly 11 AUTO_SAFE records.');

    $lots = array_values(array_filter($manifest['inventory_lot_mismatches'] ?? [], fn (array $row): bool => ($row['repair_bucket'] ?? null) === 'AUTO_SAFE'));
    $branches = array_values(array_filter($manifest['cross_company_branches'] ?? [], fn (array $row): bool => ($row['repair_bucket'] ?? null) === 'AUTO_SAFE'));
    $lotIds = array_map(fn (array $row): int => (int) $row['inventory_lot_id'], $lots);
    sort($lotIds);
    guard($lotIds === WAVE6_LOT_IDS, 'AUTO_SAFE inventory lot IDs do not match the approved allowlist.');
    $branchKeys = array_map(fn (array $row): string => $row['resource_type'].':'.$row['record_id'], $branches);
    sort($branchKeys);
    guard($branchKeys === WAVE6_BRANCH_RECORDS, 'AUTO_SAFE branch records do not match the approved allowlist.');

    return ['manifest' => $manifest, 'hash' => $hash, 'lots' => $lots, 'branches' => $branches];
}

function inspectBranch(array $row, bool $lock = false): array
{
    guard(($row['classification'] ?? null) === 'BRANCH_ID_WRONG', 'Branch repair classification changed.');
    guard(($row['before_checksum'] ?? null) === beforeHash($row['before'] ?? []), 'Branch manifest before-value checksum is invalid.');
    guard((int) $row['company_id'] === 4 && (int) $row['branch_id'] === 1, 'Branch manifest tenant or old branch is not approved.');
    guard((int) ($row['proposed_after']['branch_id'] ?? 0) === 6 && (int) ($row['proposed_after']['company_id'] ?? 0) === 4, 'Branch manifest proposed target is not approved.');
    guard(count($row['candidate_branches'] ?? []) === 1 && (int) $row['candidate_branches'][0]['id'] === 6, 'Branch target is not uniquely supported.');

    $table = $row['resource_type'] === 'PURCHASE_INVOICE' ? 'purchase_invoices' : 'inventory_lots';
    $query = DB::table($table)->where('id', $row['record_id']);
    $record = ($lock ? $query->lockForUpdate() : $query)->first();
    guard($record !== null && (int) $record->company_id === 4 && (int) $record->branch_id === 1, "{$table}:{$row['record_id']} before-state changed.");
    guard((int) DB::table('branches')->where('id', 1)->value('company_id') === 1, 'Wrong branch 1 no longer belongs to company 1.');
    guard((int) DB::table('branches')->where('id', 6)->value('company_id') === 4, 'Target branch 6 no longer belongs to company 4.');
    guard(DB::table('branches')->where('company_id', 4)->count() === 1, 'Company 4 no longer has exactly one authoritative branch.');

    if ($row['resource_type'] === 'PURCHASE_INVOICE') {
        guard(($record->document_status ?? null) === 'DRAFT', 'Approved purchase invoice is no longer DRAFT.');
        guard(($record->document_status ?? null) === ($row['source_document']['status'] ?? null), 'Purchase invoice status changed since discovery.');
        guard(($record->invoice_number ?? null) === ($row['source_document']['invoice_number'] ?? null), 'Purchase invoice identity changed since discovery.');
        guard(empty($record->journal_entry_id) && empty($record->shipment_id), 'Purchase invoice gained a journal or shipment relationship.');
        guard(!DB::table('journal_entries')->where('company_id', 4)->where('source_id', $record->id)->whereIn('source_type', ['PURCHASE', 'PURCHASE_INVOICE'])->exists(), 'Purchase invoice gained a related accounting journal.');
        guard(!DB::table('inventory_lots')->where('company_id', 4)->where('purchase_invoice_id', $record->id)->exists(), 'Purchase invoice gained a dependent inventory lot.');
    } else {
        guard($record->source_type === 'LEGACY_OPENING' && $record->source_id === null, 'Legacy lot source changed since discovery.');
        guard(empty($record->purchase_invoice_id) && empty($record->shipment_id), 'Legacy lot gained a source document.');
        guard(!DB::table('inventory_lot_movements')->where('inventory_lot_id', $record->id)->exists(), 'Legacy lot gained a dependent movement.');
    }

    return ['family' => 'branch_references', 'table' => $table, 'record_id' => (int) $record->id, 'company_id' => 4, 'before' => ['branch_id' => 1], 'after' => ['branch_id' => 6], 'rollback' => ['branch_id' => 1, 'only_if_current_branch_id' => 6]];
}

function inspectLot(array $row, bool $afterBranchFamily = false, bool $lock = false): array
{
    guard(($row['classification'] ?? null) === 'LEDGER_INCONSISTENCY', 'Inventory lot classification changed.');
    guard(($row['before_checksum'] ?? null) === beforeHash($row['before'] ?? []), 'Inventory lot manifest before-value checksum is invalid.');
    guard(($row['source_type'] ?? null) === 'LEGACY_OPENING' && ($row['source_id'] ?? null) === null, 'Manifest inventory lot source is not approved.');
    guard(($row['movements'] ?? null) === [] && ($row['fifo_allocation_count'] ?? null) === 0 && ($row['has_been_consumed'] ?? true) === false && ($row['return_or_void_affected'] ?? true) === false, 'Manifest lot is no longer an unconsumed opening lot.');
    guard(($row['proposed_after']['action'] ?? null) === 'CREATE_MISSING_OPENING_LEDGER_MOVEMENT', 'Manifest inventory lot action is not approved.');

    $query = DB::table('inventory_lots')->where('id', $row['inventory_lot_id'])->where('company_id', $row['company_id']);
    $lot = ($lock ? $query->lockForUpdate() : $query)->first();
    guard($lot !== null, "Inventory lot {$row['inventory_lot_id']} no longer exists in its original company.");
    $approved = WAVE6_APPROVED_LOT_BASELINE[(int) $lot->id] ?? null;
    guard($approved !== null && (int) $lot->company_id === $approved['company_id'], 'Inventory lot company differs from the approved execution baseline.');
    guard((int) $row['company_id'] === $approved['company_id'] && (int) $row['branch_id'] === $approved['branch_id'], 'Inventory lot manifest tenant or before-branch differs from the approved baseline.');
    guard(sameDecimal($row['qty_remaining_kg'], $approved['qty_remaining_kg']) && sameDecimal($row['proposed_after']['total_cost'], $approved['total_cost']), 'Inventory lot manifest quantity or valuation differs from the approved baseline.');
    guard(($row['proposed_after']['movement_at'] ?? null) === $approved['movement_at'], 'Inventory lot manifest movement date differs from the approved baseline.');
    $expectedBranch = $afterBranchFamily && in_array((int) $lot->id, [3, 4], true) ? 6 : (int) $row['branch_id'];
    guard((int) $lot->branch_id === $expectedBranch && (int) $lot->item_id === (int) $row['item_id'], 'Inventory lot branch or item changed since discovery.');
    guard($lot->source_type === 'LEGACY_OPENING' && $lot->source_id === null, 'Inventory lot source changed since discovery.');
    guard(sameDecimal($lot->qty_remaining_kg, $row['qty_remaining_kg']) && sameDecimal($lot->qty_received_kg, $row['original_quantity_kg']), 'Inventory lot quantities changed since discovery.');
    guard(sameDecimal($lot->total_cost, $row['proposed_after']['total_cost']), 'Inventory lot valuation changed since discovery.');
    guard(sameDecimal($lot->unit_cost_per_kg, $approved['unit_cost_per_kg'], 6), 'Inventory lot unit cost differs from the approved execution baseline.');
    guard($lot->received_at === $approved['movement_at'], 'Inventory lot receipt date differs from the approved execution baseline.');
    guard(sameDecimal($lot->qty_sold_kg, 0), 'Inventory lot has been consumed since discovery.');

    $movements = DB::table('inventory_lot_movements')->where('inventory_lot_id', $lot->id);
    guard(!$movements->exists(), 'Inventory lot gained a movement or an equivalent opening movement.');
    guard(!DB::table('sales_line_lot_sources')->where('inventory_lot_id', $lot->id)->exists(), 'Inventory lot gained a FIFO allocation.');
    guard(!DB::table('commercial_return_lot_sources')->where('inventory_lot_id', $lot->id)->exists(), 'Inventory lot gained a commercial return reference.');
    guard(!DB::table('inventory_operation_lot_links')->where(function ($query) use ($lot): void { $query->where('source_lot_id', $lot->id)->orWhere('produced_lot_id', $lot->id); })->exists(), 'Inventory lot gained an inventory-operation or transformation link.');
    guard(sameDecimal($row['movement_ledger_balance'], 0) && sameDecimal(abs((float) $row['difference']), $lot->qty_remaining_kg), 'Inventory lot ledger discrepancy is no longer exactly the approved amount.');

    $marker = WAVE6_MARKER_PREFIX.$lot->id;
    guard(!DB::table('inventory_lot_movements')->where('notes', $marker)->exists(), 'Deterministic reconciliation marker already exists.');

    return ['family' => 'opening_lot_movements', 'table' => 'inventory_lot_movements', 'inventory_lot_id' => (int) $lot->id, 'item_id' => (int) $lot->item_id, 'company_id' => (int) $lot->company_id, 'branch_id' => $afterBranchFamily ? (int) $lot->branch_id : (in_array((int) $lot->id, [3, 4], true) ? 6 : (int) $lot->branch_id), 'qty_remaining_kg' => (float) $lot->qty_remaining_kg, 'movement_type' => 'IN', 'source_type' => 'LEGACY_OPENING', 'source_id' => null, 'qty_kg' => (float) $lot->qty_remaining_kg, 'unit_cost_per_kg' => (float) $lot->unit_cost_per_kg, 'total_cost' => (float) $lot->total_cost, 'movement_at' => $lot->received_at, 'repair_marker' => $marker, 'before' => ['movement_count' => 0, 'movement_balance' => 0, 'qty_remaining_kg' => (float) $lot->qty_remaining_kg], 'after' => ['movement_count' => 1, 'movement_balance' => (float) $lot->qty_remaining_kg, 'qty_remaining_kg' => (float) $lot->qty_remaining_kg], 'rollback' => ['only_created_movement_id' => null, 'required_marker' => $marker, 'requires_no_consumption_or_fifo' => true]];
}

function inspectAll(array $loaded): array
{
    return ['branch_repairs' => array_map(fn (array $row): array => inspectBranch($row), $loaded['branches']), 'lot_movement_repairs' => array_map(fn (array $row): array => inspectLot($row), $loaded['lots'])];
}

function invariantSnapshot(): array
{
    $lots = DB::table('inventory_lots')->whereIn('id', WAVE6_LOT_IDS)->orderBy('id')->get(['id', 'qty_received_kg', 'qty_remaining_kg', 'qty_sold_kg', 'total_cost', 'unit_cost_per_kg'])->all();
    $purchases = DB::table('purchase_invoices')->whereIn('id', [3, 4])->orderBy('id')->get(['id', 'company_id', 'invoice_number', 'document_status', 'total_amount', 'vat_amount', 'journal_entry_id'])->all();
    $fifo = DB::table('sales_line_lot_sources')->orderBy('id')->get(['id', 'company_id', 'inventory_lot_id', 'qty_kg', 'total_cost'])->all();
    $stock = DB::selectOne('SELECT COUNT(*) record_count, COALESCE(SUM(qty_kg),0) quantity, COALESCE(SUM(total_cost),0) valuation FROM stock_movements');
    $journals = DB::selectOne('SELECT COUNT(*) line_count, COALESCE(SUM(debit),0) debit, COALESCE(SUM(credit),0) credit FROM journal_entry_lines');
    $purchaseVat = DB::table('purchase_invoices')->sum('vat_amount');
    $salesVat = DB::table('sales_invoices')->sum('vat_amount');

    return ['lots' => $lots, 'purchase_invoices' => $purchases, 'fifo_sources' => $fifo, 'stock_movements' => (array) $stock, 'general_ledger' => (array) $journals, 'vat' => ['purchases' => $purchaseVat, 'sales' => $salesVat], 'zero_error_checks' => [
        'negative_lots' => DB::table('inventory_lots')->where('qty_remaining_kg', '<', -0.001)->count(),
        'orphan_stock_movements' => (int) DB::selectOne('SELECT COUNT(*) total FROM stock_movements m LEFT JOIN items i ON i.id=m.item_id AND i.company_id=m.company_id WHERE i.id IS NULL')->total + (int) DB::selectOne('SELECT COUNT(*) total FROM stock_movements m LEFT JOIN inventory_lots l ON l.id=m.inventory_lot_id AND l.company_id=m.company_id WHERE m.inventory_lot_id IS NOT NULL AND l.id IS NULL')->total,
        'unbalanced_journals' => count(DB::select("SELECT j.id FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id AND l.company_id=j.company_id WHERE j.status='POSTED' GROUP BY j.id HAVING ABS(SUM(l.debit)-SUM(l.credit))>0.01")),
        'duplicate_source_journals' => count(DB::select("SELECT company_id,source_type,source_id FROM journal_entries WHERE source_type IS NOT NULL AND source_id IS NOT NULL AND status='POSTED' GROUP BY company_id,source_type,source_id HAVING COUNT(*)>1")),
        'posted_purchases_without_journals' => DB::table('purchase_invoices')->where('document_status', 'POSTED')->whereNull('journal_entry_id')->count(),
        'posted_sales_without_journals' => DB::table('sales_invoices')->where('document_status', 'POSTED')->whereNull('journal_entry_id')->count(),
    ]];
}

function persistExecutionManifest(string $path, array $manifest): void
{
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    guard(file_put_contents($path, $json, LOCK_EX) !== false, 'Execution manifest could not be written.');
}

function validateExecutionGates(array $loaded, array $actions, array $baseline): array
{
    guard(hash_equals(WAVE6_APPROVED_MANIFEST_SHA256, $loaded['hash']), 'Approved manifest SHA-256 changed during preflight.');
    guard(count($actions['branch_repairs']) === 4 && count($actions['lot_movement_repairs']) === 7, 'Approved execution target counts changed during preflight.');
    guard(DB::table('inventory_lot_movements')->whereIn('inventory_lot_id', WAVE6_LOT_IDS)->count() === 0, 'Affected inventory lots already contain ledger movements.');
    guard(DB::table('sales_line_lot_sources')->whereIn('inventory_lot_id', WAVE6_LOT_IDS)->count() === 0, 'Affected inventory lots gained FIFO allocations.');
    guard(DB::table('commercial_return_lot_sources')->whereIn('inventory_lot_id', WAVE6_LOT_IDS)->count() === 0, 'Affected inventory lots gained return dependencies.');
    guard(DB::table('inventory_operation_lot_links')->where(function ($query): void { $query->whereIn('source_lot_id', WAVE6_LOT_IDS)->orWhereIn('produced_lot_id', WAVE6_LOT_IDS); })->count() === 0, 'Affected inventory lots gained transformation dependencies.');
    guard(sameDecimal($baseline['general_ledger']['debit'], $baseline['general_ledger']['credit']), 'General ledger debit and credit are not balanced before repair.');
    foreach (['negative_lots', 'unbalanced_journals', 'duplicate_source_journals'] as $name) {
        guard(($baseline['zero_error_checks'][$name] ?? null) === 0, "Required pre-execution accounting invariant failed: {$name}.");
    }

    return ['manifest_sha256' => 'PASS', 'exact_target_set' => 'PASS', 'before_state' => 'PASS', 'dependencies' => 'PASS', 'accounting_invariants' => 'PASS', 'gl_debit' => $baseline['general_ledger']['debit'], 'gl_credit' => $baseline['general_ledger']['credit']];
}

try {
    $options = getopt('', ['manifest:', 'scope:', 'dry-run', 'execute']);
    guard(($options['scope'] ?? null) === 'auto-safe', 'Only --scope=auto-safe is supported.');
    guard(isset($options['dry-run']) xor isset($options['execute']), 'Specify exactly one of --dry-run or --execute.');
    guard(isset($options['manifest']), 'The --manifest option is required.');
    $root = dirname(__DIR__, 2);
    $candidate = $options['manifest'];
    $path = is_file($candidate) ? $candidate : $root.DIRECTORY_SEPARATOR.ltrim($candidate, '/\\');
    $loaded = loadManifest($path);

    if (isset($options['dry-run'])) {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION READ ONLY');
        }
        $inspection = DB::transaction(function () use ($loaded): array {
            $actions = inspectAll($loaded);
            $baseline = invariantSnapshot();

            return ['actions' => $actions, 'baseline' => $baseline, 'execution_gates' => validateExecutionGates($loaded, $actions, $baseline)];
        });
        $actions = $inspection['actions'];
        echo json_encode(['mode' => 'DRY_RUN', 'database_writes' => 0, 'file_writes' => 0, 'manifest_version' => 1, 'manifest_sha256' => $loaded['hash'], 'auto_safe_count' => 11, 'branch_repair_count' => count($actions['branch_repairs']), 'lot_movement_repair_count' => count($actions['lot_movement_repairs']), 'execution_gates' => $inspection['execution_gates'], 'actions' => $actions, 'excluded_families' => ['journal_financial_years', 'shipments_without_closed_card', 'missing_account_mappings'], 'transaction_design' => ['branch_references' => 'one atomic transaction', 'opening_lot_movements' => 'one atomic transaction after successful branch corrections'], 'invariants' => ['lot_quantities_unchanged', 'inventory_valuation_unchanged', 'stock_movements_unchanged', 'gl_unchanged', 'vat_unchanged', 'fifo_unchanged', 'purchase_totals_and_status_unchanged'], 'zero_error_checks' => $inspection['baseline']['zero_error_checks']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $actions = inspectAll($loaded);
    $executionId = 'wave6-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    $executionPath = $root.'/docs/erp-baseline/wave6-repair-execution-'.date('Ymd-His').'.json';
    guard(!file_exists($executionPath), 'Execution manifest already exists; refusing to overwrite.');
    $baseline = invariantSnapshot();
    $executionGates = validateExecutionGates($loaded, $actions, $baseline);
    $audit = ['execution_id' => $executionId, 'timestamp' => now()->toIso8601String(), 'source_manifest_sha256' => $loaded['hash'], 'execution_gates' => $executionGates, 'actions' => $actions, 'baseline' => $baseline, 'families' => ['branch_references' => 'PENDING', 'opening_lot_movements' => 'PENDING'], 'created_movement_ids' => [], 'invariants' => []];
    persistExecutionManifest($executionPath, $audit);

    try {
        DB::transaction(function () use ($loaded): void {
            foreach ($loaded['branches'] as $row) {
                $action = inspectBranch($row, true);
                $updated = DB::table($action['table'])->where('id', $action['record_id'])->where('company_id', 4)->where('branch_id', 1)->update(['branch_id' => 6]);
                guard($updated === 1, 'Optimistic branch repair updated an unexpected number of records.');
            }
        });
        $audit['families']['branch_references'] = 'COMMITTED';
        persistExecutionManifest($executionPath, $audit);

        DB::transaction(function () use ($loaded, $baseline, &$audit): void {
            foreach ($loaded['lots'] as $row) {
                $action = inspectLot($row, true, true);
                $movementId = DB::table('inventory_lot_movements')->insertGetId(['company_id' => $action['company_id'], 'branch_id' => $action['branch_id'], 'inventory_lot_id' => $action['inventory_lot_id'], 'item_id' => $action['item_id'], 'movement_type' => 'IN', 'source_type' => 'LEGACY_OPENING', 'source_id' => null, 'movement_at' => $action['movement_at'], 'qty_kg' => $action['qty_kg'], 'unit_cost_per_kg' => $action['unit_cost_per_kg'], 'total_cost' => $action['total_cost'], 'notes' => $action['repair_marker'], 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]);
                $audit['created_movement_ids'][] = ['inventory_lot_id' => $action['inventory_lot_id'], 'movement_id' => $movementId, 'marker' => $action['repair_marker']];
                $balance = (float) DB::table('inventory_lot_movements')->where('inventory_lot_id', $action['inventory_lot_id'])->selectRaw("COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty_kg ELSE -qty_kg END),0) AS balance")->value('balance');
                guard(sameDecimal($balance, $action['qty_remaining_kg']), 'Inventory lot movement equation failed after reconstruction.');
            }
            $audit['invariants']['approved_branch_reference_count'] = DB::table('purchase_invoices')->whereIn('id', [3, 4])->where('company_id', 4)->where('branch_id', 6)->count() + DB::table('inventory_lots')->whereIn('id', [3, 4])->where('company_id', 4)->where('branch_id', 6)->count();
            guard($audit['invariants']['approved_branch_reference_count'] === 4, 'Post-repair branch verification failed.');
            $after = invariantSnapshot();
            guard(json_encode($baseline, JSON_THROW_ON_ERROR) === json_encode($after, JSON_THROW_ON_ERROR), 'A protected inventory, accounting, VAT, FIFO, purchase, or zero-error invariant changed.');
            foreach ($after['zero_error_checks'] as $name => $count) {
                guard($count === 0, "Post-repair zero-error invariant failed: {$name}.");
            }
            $audit['invariants']['protected_snapshot_unchanged'] = true;
            $audit['invariants']['zero_error_checks'] = $after['zero_error_checks'];
        });
        $audit['families']['opening_lot_movements'] = 'COMMITTED';
        persistExecutionManifest($executionPath, $audit);
        echo json_encode(['mode' => 'EXECUTE', 'execution_manifest' => $executionPath, 'families' => $audit['families'], 'created_movement_ids' => $audit['created_movement_ids']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        $family = $audit['families']['branch_references'] !== 'COMMITTED' ? 'branch_references' : 'opening_lot_movements';
        $audit['families'][$family] = 'ROLLED_BACK';
        if ($family === 'opening_lot_movements') {
            $audit['created_movement_ids'] = [];
        }
        $audit['failure'] = $error->getMessage();
        persistExecutionManifest($executionPath, $audit);
        throw $error;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'WAVE6 RECONCILIATION ABORTED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
