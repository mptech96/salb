<?php

use App\Http\Controllers\Api\FinancialSetupController;
use App\Services\Accounting\AccountingContext;
use App\Services\Provisioning\CompanyProvisioningService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if (! str_starts_with($database, 'sulb_fresh_')) {
    throw new \RuntimeException('Fresh-schema validation refuses to run outside a sulb_fresh_* database.');
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new \RuntimeException($message);
};

$assert(DB::table('companies')->count() === 0, 'Fresh database already contains tenant companies.');
$assert(DB::table('migrations')->where('migration', '2026_08_31_000026_add_print_branding_profile_to_company_settings')->exists(), 'Pending release migration was not applied.');
$assert(DB::table('currencies')->count() === 2, 'Currency baseline is incomplete.');
$assert(DB::table('voucher_types')->count() === 2, 'Voucher-type baseline is incomplete.');
$assert(DB::table('expense_types')->whereNull('company_id')->count() === 8, 'Expense-type baseline is incomplete.');
$assert(DB::table('tax_codes')->count() === 0, 'System baseline fabricated tax codes.');

$input = [
    'idempotency_key' => 'fresh-release-validation-20260905',
    'channel' => 'SYSTEM_ADMIN',
    'company_name' => 'Fresh Release Validation',
    'owner_name' => 'Validation Owner',
    'phone' => '0000000000',
    'username' => 'fresh_validation_owner_20260905',
    'password' => Str::random(24),
    'plan_id' => DB::table('plans')->where('plan_code', 'ENTERPRISE')->value('id'),
    'currency_code' => 'SAR',
    'start_date' => now()->subDay()->toDateString(),
    'end_date' => now()->addYear()->toDateString(),
    'subscription_mode' => 'TRIAL',
    'trial_allowed' => true,
    'company_is_active' => 1,
];

$service = $app->make(CompanyProvisioningService::class);
$result = $service->provision($input);
$replay = $service->provision($input);
$companyId = (int) $result['company_id'];
$branchId = (int) $result['branch_id'];

$assert(($replay['idempotent_replay'] ?? false) === true, 'Provisioning retry was not idempotent.');
$assert(DB::table('companies')->count() === 1, 'Provisioning created an unexpected company count.');
$assert(DB::table('company_currencies')->where('company_id', $companyId)->where('is_base', 1)->where('is_active', 1)->count() === 1, 'Provisioning did not create exactly one active base currency.');
$assert(DB::table('company_settings')->where('company_id', $companyId)->value('base_currency_code') === 'SAR', 'Base currency settings are inconsistent.');
$assert(DB::table('financial_years')->where('company_id', $companyId)->where('is_closed', 0)->count() === 1, 'Active financial year was not created.');
$assert(DB::table('accounting_settings')->where('company_id', $companyId)->count() >= 8, 'Accounting mappings are incomplete.');
$assert(DB::table('cost_centers')->where('company_id', $companyId)->count() >= 2, 'Required cost centers were not created.');
$assert(DB::table('financial_accounts')->where('company_id', $companyId)->where('branch_id', $branchId)->where('account_type', 'CASH')->where('is_active', 1)->exists(), 'Main branch cash account is missing.');
$assert(DB::table('branch_financial_settings')->where('company_id', $companyId)->where('branch_id', $branchId)->whereNotNull('default_cash_financial_account_id')->whereNotNull('default_cost_center_id')->exists(), 'Main branch defaults are incomplete.');
$assert(DB::table('tax_codes')->where('company_id', $companyId)->count() === 0, 'Provisioning fabricated tax codes.');

$request = Request::create('/api/financial-setup', 'GET');
$request->attributes->set('tenant_company_id', $companyId);
$request->attributes->set('tenant_branch_id', $branchId);
$request->attributes->set('effective_role_code', 'COMPANY_OWNER');
$setup = $app->make(FinancialSetupController::class)->index($request, $app->make(AccountingContext::class))->getData(true);
$readiness = $setup['data']['readiness'] ?? [];
$assert(($readiness['status'] ?? null) === 'READY', 'Financial Setup readiness is not READY.');
$assert(($readiness['tax_status'] ?? null) === 'NOT_CONFIGURED', 'Fresh company tax status is inaccurate.');

echo json_encode([
    'status' => 'PASS',
    'database' => $database,
    'company_id' => $companyId,
    'branch_id' => $branchId,
    'readiness' => $readiness['status'],
    'tax_status' => $readiness['tax_status'],
    'active_base_count' => $readiness['active_base_count'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
