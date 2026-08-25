<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const APPROVED_COMPANY_ID = 4;
const APPROVED_BRANCH_ID = 6;
const APPROVED_YEAR = ['company_id' => 4, 'year_name' => '2026', 'start_date' => '2026-06-17', 'end_date' => '2027-02-24', 'is_closed' => 0, 'closed_at' => null, 'closed_by' => null];
const APPROVED_HEADER_HASHES = [
    1 => '4891b48057555729fb7c6e99505f2c1b5280fb61f5b197cc4a2f8b3bb418cde1', 2 => '7f183bcc5163fdddcb423eacbddcd75db375ad78f3f638adcd40e02f68a18253',
    3 => 'aba50b7359e1ae79e4983f53e0d98a086a592cedc8f0b52a22b6c17327e89850', 4 => 'fcc40dd2ac1954998905d57edc8dd461ff7dd06806f5dc71638a489cf3df5345',
    7 => '07b93affbe88a4e721fa74f577cd8638320a0ded315d3bc3b210a13a9445ac4c', 8 => '859e1384015a24217b10a55378857a614f97a25bd1c5502414761558ba03e41c',
    9 => '99d96577619c0964d558cd3875a7e49b49717181f57c23711fc42016d9f263fd', 10 => 'd73b399a13ff0bb0460d9a7b587264665c1cd500fa33cb2e6f466cb3b03a52d4',
    11 => '9095a26ca46c2fcfc6d048688de00ca34265410ec59e5977c25f269c2f286257', 12 => '1956f75b99a254d6634c1ec84f2750de3db5954ab060d22401d37d96d1d09047',
    13 => '5cbe58b329e2aab74b633e7a56a3b891a1ac366c5f769991224e124b3f5694c8', 14 => '78330727c03b64bc07d8b012539c66f34a8d4246ad84faa263a117e7abbb27f7',
    15 => '7a9d76d6cf03e7d2a7f2cc55143a968acdf9ddb99299e6f7b2018895105874e0',
];
const APPROVED_LINE_HASHES = [
    1 => 'c85e10938b4f8287a442bf9b9d0bc9f2c7a1930491108b96a06393655b4fc0c2', 2 => '893972fb225778558c85bb3d13503cd3c7e500870757ecf28e81998b2d194d1e',
    3 => '307433c26925b3df44bca5b7768e917fb92c2eaa3ce57e7e8d668cccd4ce43cc', 4 => '30f69852e0529ab308284d44e41cc42d94247f62b3d8df75235148e04b189f08',
    5 => '888a81bb17230ff533bb39e074bb40ce512b09e058970846bf6bfca8cb9c8498', 6 => '792bb9cfb404b73d11c26afbb3b95454a8dfab07dc4b41c0446bdeec523f18bb',
    7 => 'fa9e9843abe19a8b408c4e4b9281b78c84411e45de78ac6f52decea3add1950b', 8 => '266272897e2fa02dea1604343873c36d160d02f01ae994a3358d8e8e7ac0caa8',
    13 => '41c61506799d679509ed0e9a61f12ce1fc0e8f3136929101c61985f5fb9b4799', 14 => 'f36a6d966f7bce93064fa6a33745558de66da0521fa945c66ed6035bfa12c7b0',
    15 => '8513b08ba8f62566150a02ba482c97addf54ef7c8121e3032e4033afa7c5da74', 16 => 'f80a6fd7dcecc6fc3da5a2be772cd7d2864acb5cf1749e10b7b2c0b82d261816',
    17 => '3be08f65d757766372ccc7186af68f9fda6da66098fe971966c493c6cc8c450a', 18 => 'edf05e5116847af3ecc068e7d7a71d4cdf789cc3c02e786ae661273bc71eea3d',
    19 => '9417418efcac01901a8d42ba77fcfbf999551ec2a6a2b1d67155577c752eaf3e', 20 => 'fe1a208c8ca77adf03158f78a91b80c9f551333e2c6a61b239cacad6c2a012a6',
    21 => '72a9dfe4a4128607bf6653f12a0e010b8b6db0e3238b7166a1f1674c7387f134', 22 => 'edbb6ff2496c4d40fba88f29d3063202370e0ab474d1713ec9fe8b837d5f64e3',
    23 => '65c5046cd93365bc7792fb3e062d34779660d0838385766d3108c3bbe5072f49', 24 => 'bc4ec964f0f63cb3f9c90b9e3818b5b91bd3d0821af4e201deb144f089d5e597',
    25 => '14a5ac624a091f458cc8df62d8d161d03c125a45b74229d39e9c99721001f576', 26 => 'bb52797aa1dabe5cfe992c5e87aeba650617c081a0e6aca107da18e72404ab50',
    27 => '6081b978e20052cd7155b8ce137f953e6699a1350e5e760ba13f5c6194f204c9', 28 => '76f31a5b6ddba570dc7918ba037a34e93ad5f761f61b18fcf85ed491dc400722',
    29 => 'cfce3c52dcff6cf35d1e0449770ae94d7171e6eb79069b8b972ce913a64d9563', 30 => '8294cdaa6547cafbb5907c679f1588889fc84ddc33b59866744d7593cd9ab198',
    31 => '790897da733d242ec0a1e3f6a4f5886982e2b3bde4175f9b88f63e9c15ebb7c3', 32 => '0c8e68977e5867299ad3dad3445819531060eae5765408caa7b1cbece83d8792',
];

function requireSafe(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function protectedRowHash(object $row): string
{
    $fields = (array) $row;
    unset($fields['financial_year_id'], $fields['created_at'], $fields['updated_at']);
    ksort($fields);

    return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
}

function inspectApprovedState(bool $lock = false): array
{
    $headerIds = array_keys(APPROVED_HEADER_HASHES);
    $lineIds = array_keys(APPROVED_LINE_HASHES);
    $companyQuery = DB::table('companies')->where('id', APPROVED_COMPANY_ID);
    $company = ($lock ? $companyQuery->lockForUpdate() : $companyQuery)->first();
    requireSafe($company !== null, 'Approved company 4 no longer exists.');
    requireSafe(DB::table('subscriptions')->where('company_id', APPROVED_COMPANY_ID)->where('start_date', APPROVED_YEAR['start_date'])->where('end_date', APPROVED_YEAR['end_date'])->exists(), 'Approved financial-year period no longer matches a company-4 subscription.');

    $yearQuery = DB::table('financial_years')->where('company_id', APPROVED_COMPANY_ID);
    $years = ($lock ? $yearQuery->lockForUpdate() : $yearQuery)->get();
    requireSafe(!$years->contains(fn (object $year): bool => $year->start_date <= APPROVED_YEAR['end_date'] && $year->end_date >= APPROVED_YEAR['start_date']), 'Approved financial year overlaps an existing company-4 financial year.');
    requireSafe(!$years->contains(fn (object $year): bool => $year->year_name === APPROVED_YEAR['year_name']), 'Approved financial-year name already exists for company 4.');

    $headerQuery = DB::table('journal_entries')->whereIn('id', $headerIds)->orderBy('id');
    $headers = ($lock ? $headerQuery->lockForUpdate() : $headerQuery)->get();
    requireSafe($headers->count() === 13, 'Expected exactly 13 approved journal headers.');
    $headerActions = [];
    foreach ($headers as $header) {
        requireSafe(isset(APPROVED_HEADER_HASHES[(int) $header->id]), 'Unexpected journal header returned.');
        requireSafe((int) $header->company_id === APPROVED_COMPANY_ID && (int) $header->branch_id === APPROVED_BRANCH_ID, 'Journal header company or branch changed.');
        requireSafe($header->financial_year_id === null && $header->status === 'POSTED', 'Journal header year or posting status changed.');
        requireSafe($header->entry_date >= APPROVED_YEAR['start_date'] && $header->entry_date <= APPROVED_YEAR['end_date'], 'Journal date is outside the approved financial year.');
        requireSafe(hash_equals(APPROVED_HEADER_HASHES[(int) $header->id], protectedRowHash($header)), 'Protected journal header amount, date, source, status, or dimension changed.');
        $headerActions[] = ['journal_id' => (int) $header->id, 'entry_number' => $header->entry_number, 'entry_date' => $header->entry_date, 'old_financial_year_id' => null, 'new_financial_year_id' => 'CREATED_FINANCIAL_YEAR_ID'];
    }

    $parentLineQuery = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $headerIds)->orderBy('id');
    $parentLines = ($lock ? $parentLineQuery->lockForUpdate() : $parentLineQuery)->get();
    requireSafe($parentLines->count() === 28, 'Approved journals must contain exactly 28 lines; an omitted or new line exists.');
    requireSafe($parentLines->pluck('id')->map(fn ($id): int => (int) $id)->all() === $lineIds, 'Approved journal-line IDs do not exactly match the allowlist.');
    $lineActions = [];
    foreach ($parentLines as $line) {
        requireSafe(isset(APPROVED_LINE_HASHES[(int) $line->id]) && isset(APPROVED_HEADER_HASHES[(int) $line->journal_entry_id]), 'Approved journal line has an unexpected parent.');
        requireSafe((int) $line->company_id === APPROVED_COMPANY_ID && $line->financial_year_id === null, 'Approved journal line company or financial year changed.');
        requireSafe(hash_equals(APPROVED_LINE_HASHES[(int) $line->id], protectedRowHash($line)), 'Protected journal-line account, amount, description, or dimension changed.');
        $lineActions[] = ['line_id' => (int) $line->id, 'journal_entry_id' => (int) $line->journal_entry_id, 'old_financial_year_id' => null, 'new_financial_year_id' => 'CREATED_FINANCIAL_YEAR_ID'];
    }
    $approvedLineQuery = DB::table('journal_entry_lines')->whereIn('id', $lineIds);
    requireSafe(($lock ? $approvedLineQuery->lockForUpdate() : $approvedLineQuery)->count() === 28, 'An approved journal line is missing or moved outside the approved parent set.');

    $unbalanced = 0;
    foreach ($headers as $header) {
        $lines = $parentLines->where('journal_entry_id', $header->id);
        if (round((float) $lines->sum('debit'), 3) !== round((float) $lines->sum('credit'), 3)) {
            $unbalanced++;
        }
    }
    requireSafe($unbalanced === 0, 'One or more approved journal entries are unbalanced.');
    $numbers = $headers->pluck('entry_number')->all();
    requireSafe(count(array_unique($numbers)) === 13, 'Approved journal-entry numbers are not unique within the proposed financial year.');
    $duplicateExisting = DB::table('journal_entries')->where('company_id', APPROVED_COMPANY_ID)->whereNotIn('id', $headerIds)->whereIn('entry_number', $numbers)->count();
    requireSafe($duplicateExisting === 0, 'An existing company-4 journal would collide with an approved journal-entry number.');

    $debit = round((float) $parentLines->sum('debit'), 3);
    $credit = round((float) $parentLines->sum('credit'), 3);
    requireSafe($debit === 58007.0 && $credit === 58007.0 && $debit === $credit, 'Approved journal debit/credit totals changed or are not balanced.');
    $globalDebit = round((float) DB::table('journal_entry_lines')->sum('debit'), 3);
    $globalCredit = round((float) DB::table('journal_entry_lines')->sum('credit'), 3);
    requireSafe($globalDebit === $globalCredit, 'Global general-ledger debit and credit are not balanced.');

    return ['financial_year' => APPROVED_YEAR, 'headers' => $headerActions, 'lines' => $lineActions, 'before_invariants' => ['header_count' => 13, 'line_count' => 28, 'debit_total' => $debit, 'credit_total' => $credit, 'global_gl_debit_total' => $globalDebit, 'global_gl_credit_total' => $globalCredit, 'unbalanced_journal_count' => $unbalanced, 'journal_line_parent_mismatch_count' => 0, 'invalid_year_count' => 13, 'duplicate_number_collision_count' => 0], 'safety_gates' => ['company' => 'PASS', 'subscription_period' => 'PASS', 'financial_year_overlap' => 'PASS', 'header_allowlist_and_fingerprints' => 'PASS', 'line_allowlist_and_fingerprints' => 'PASS', 'parent_completeness' => 'PASS', 'journal_balancing' => 'PASS', 'numbering_collisions' => 'PASS', 'global_general_ledger' => 'PASS']];
}

function writeExecutionRecord(string $path, array $record): void
{
    requireSafe(file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Could not write the financial-year execution manifest.');
}

try {
    $options = getopt('', ['company:', 'scope:', 'include-journal-lines', 'dry-run', 'execute']);
    requireSafe(($options['company'] ?? null) === '4', 'Only --company=4 is permitted.');
    requireSafe(($options['scope'] ?? null) === 'journal-financial-year', 'Only --scope=journal-financial-year is permitted.');
    requireSafe(isset($options['include-journal-lines']), '--include-journal-lines is mandatory.');
    requireSafe(isset($options['dry-run']) xor isset($options['execute']), 'Specify exactly one of --dry-run or --execute.');

    if (isset($options['dry-run'])) {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION READ ONLY');
        }
        $inspection = DB::transaction(fn (): array => inspectApprovedState());
        echo json_encode(['mode' => 'DRY_RUN', 'database_writes' => 0, 'file_writes' => 0, ...$inspection, 'expected_after_invariants' => ['assigned_headers' => 13, 'assigned_lines' => 28, 'header_line_year_mismatches' => 0, 'debit_total' => 58007, 'credit_total' => 58007, 'account_balances_unchanged' => true, 'vat_unchanged' => true, 'inventory_unchanged' => true, 'source_documents_unchanged' => true], 'expected_audit_dispositions' => ['ACTION_REQUIRED' => 0, 'ACCEPTED_LEGACY' => 3, 'NON_OPERATIONAL_CONFIGURATION' => 7, 'INFORMATIONAL' => 8]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $initial = inspectApprovedState();
    $executionId = 'wave6-fy-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    $path = dirname(__DIR__, 2).'/docs/erp-baseline/wave6-financial-year-execution-'.date('Ymd-His').'.json';
    requireSafe(!file_exists($path), 'Financial-year execution manifest already exists.');
    $audit = ['execution_id' => $executionId, 'timestamp' => now()->toIso8601String(), 'company_id' => APPROVED_COMPANY_ID, 'created_financial_year_id' => null, 'financial_year' => APPROVED_YEAR, 'headers' => $initial['headers'], 'lines' => $initial['lines'], 'before_invariants' => $initial['before_invariants'], 'safety_gates' => $initial['safety_gates'], 'transaction' => 'PENDING', 'rollback' => ['approved_line_ids' => array_keys(APPROVED_LINE_HASHES), 'approved_header_ids' => array_keys(APPROVED_HEADER_HASHES), 'only_created_financial_year_id' => null, 'requires_no_new_year_journals' => true, 'requires_year_not_closed' => true]];
    writeExecutionRecord($path, $audit);

    try {
        $result = DB::transaction(function () use (&$audit): array {
            $before = inspectApprovedState(true);
            $timestamp = now();
            $yearId = DB::table('financial_years')->insertGetId([...APPROVED_YEAR, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            $updatedHeaders = DB::table('journal_entries')->where('company_id', APPROVED_COMPANY_ID)->where('branch_id', APPROVED_BRANCH_ID)->whereIn('id', array_keys(APPROVED_HEADER_HASHES))->whereNull('financial_year_id')->update(['financial_year_id' => $yearId]);
            requireSafe($updatedHeaders === 13, 'Financial-year assignment did not update exactly 13 approved journal headers.');
            $updatedLines = DB::table('journal_entry_lines')->where('company_id', APPROVED_COMPANY_ID)->whereIn('id', array_keys(APPROVED_LINE_HASHES))->whereIn('journal_entry_id', array_keys(APPROVED_HEADER_HASHES))->whereNull('financial_year_id')->update(['financial_year_id' => $yearId]);
            requireSafe($updatedLines === 28, 'Financial-year assignment did not update exactly 28 approved journal lines.');

            $headers = DB::table('journal_entries')->whereIn('id', array_keys(APPROVED_HEADER_HASHES))->get();
            $lines = DB::table('journal_entry_lines')->whereIn('id', array_keys(APPROVED_LINE_HASHES))->get();
            foreach ($headers as $header) {
                requireSafe((int) $header->financial_year_id === $yearId && hash_equals(APPROVED_HEADER_HASHES[(int) $header->id], protectedRowHash($header)), 'A journal header changed beyond its financial_year_id.');
                requireSafe(round((float) $lines->where('journal_entry_id', $header->id)->sum('debit'), 3) === round((float) $lines->where('journal_entry_id', $header->id)->sum('credit'), 3), 'A journal became unbalanced during financial-year assignment.');
            }
            foreach ($lines as $line) {
                requireSafe((int) $line->financial_year_id === $yearId && hash_equals(APPROVED_LINE_HASHES[(int) $line->id], protectedRowHash($line)), 'A journal line changed beyond its financial_year_id.');
            }
            $mismatch = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->whereIn('l.id', array_keys(APPROVED_LINE_HASHES))->whereColumn('l.financial_year_id', '<>', 'j.financial_year_id')->count();
            requireSafe($mismatch === 0, 'Journal headers and lines disagree on their assigned financial year.');
            $duplicates = DB::table('journal_entries')->where('company_id', APPROVED_COMPANY_ID)->where('financial_year_id', $yearId)->select('entry_number')->groupBy('entry_number')->havingRaw('COUNT(*) > 1')->get()->count();
            requireSafe($duplicates === 0, 'Financial-year assignment created a journal numbering collision.');
            $debit = round((float) $lines->sum('debit'), 3);
            $credit = round((float) $lines->sum('credit'), 3);
            requireSafe($debit === $before['before_invariants']['debit_total'] && $credit === $before['before_invariants']['credit_total'], 'Journal debit or credit changed during financial-year reconciliation.');
            $audit['created_financial_year_id'] = $yearId;
            $audit['rollback']['only_created_financial_year_id'] = $yearId;

            return ['created_financial_year_id' => $yearId, 'updated_headers' => $updatedHeaders, 'updated_lines' => $updatedLines, 'header_line_year_mismatches' => $mismatch, 'duplicate_number_collisions' => $duplicates, 'debit_total' => $debit, 'credit_total' => $credit];
        });
        $audit['transaction'] = 'COMMITTED';
        $audit['after_invariants'] = $result;
        writeExecutionRecord($path, $audit);
        echo json_encode(['mode' => 'EXECUTE', 'execution_manifest' => $path, ...$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        $audit['transaction'] = 'ROLLED_BACK';
        $audit['created_financial_year_id'] = null;
        $audit['rollback']['only_created_financial_year_id'] = null;
        $audit['failure'] = $error->getMessage();
        writeExecutionRecord($path, $audit);
        throw $error;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'WAVE6 FINANCIAL-YEAR RECONCILIATION ABORTED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
