<?php

namespace Tests\Feature\PhaseE;

use Tests\TestCase;

class P1OperationalReadinessTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(app_path($path));
    }

    public function test_cost_center_hierarchy_and_posting_guards_are_present(): void
    {
        $setup=$this->source('Http/Controllers/Api/FinancialSetupController.php');
        $journal=$this->source('Domain/Accounting/Services/JournalService.php');
        self::assertStringContainsString("where('company_id',\$cid)->where('id',\$parentId)",$setup);
        self::assertStringContainsString('!(int)$parent->is_active||!(int)$parent->is_group',$setup);
        self::assertStringContainsString('costCenterDescendsFrom',$setup);
        self::assertStringContainsString('نطاق فرع المركز الأب غير متوافق',$setup);
        self::assertStringContainsString("where('is_active',1)",$journal);
        self::assertStringContainsString("orWhere('branch_id',\$lineBranch)",$journal);
    }

    public function test_financial_account_defaults_and_history_remain_safe(): void
    {
        $service=$this->source('Services/FinancialAccountService.php');
        self::assertStringContainsString('deactivateOrDelete',$service);
        self::assertStringContainsString('default_cash_financial_account_id',$service);
        self::assertStringContainsString('default_bank_financial_account_id',$service);
        self::assertStringContainsString('default_wallet_financial_account_id',$service);
        self::assertStringContainsString("where('financial_account_id',\$id)->exists()",$service);
        self::assertStringContainsString("where('is_active',1)",$service);
        self::assertStringNotContainsString("DB::table('currencies')->insert",$service);
    }

    public function test_opening_asset_validation_is_performed_at_draft_time(): void
    {
        $service=$this->source('Services/OpeningBalanceService.php');
        self::assertStringContainsString("['STRAIGHT_LINE','DECLINING_BALANCE','NO_DEPRECIATION']",$service);
        self::assertStringContainsString('$acc>$cost-$salvage',$service);
        self::assertStringContainsString("assetAccount(\$companyId",$service);
        self::assertStringContainsString("'ASSET'",$service);
        self::assertStringContainsString("'EXPENSE'",$service);
        self::assertStringContainsString('$starts<$acquired',$service);
        self::assertStringContainsString("where('allow_posting',1)",$service);
    }

    public function test_readiness_is_explicit_and_does_not_claim_tax_compliance(): void
    {
        $controller=$this->source('Http/Controllers/Api/FinancialSetupController.php');
        foreach(['base_currency','active_financial_year','accounting_mappings','company_cost_center','main_branch_cost_center','main_branch_cash_account','branch_defaults','opening_balance_capable'] as $check) self::assertStringContainsString($check,$controller);
        self::assertStringContainsString("'CONFIGURED':'NOT_CONFIGURED'",$controller);
        self::assertStringContainsString("'READY'",$controller);
        self::assertStringContainsString("'NOT_READY'",$controller);
        self::assertStringContainsString("'tax_status'=>\$taxStatus",$controller);
    }

    public function test_required_lists_are_server_paginated_and_bounded(): void
    {
        $accounts=$this->source('Services/FinancialAccountService.php');
        $opening=$this->source('Services/OpeningBalanceService.php');
        $setup=$this->source('Http/Controllers/Api/FinancialSetupController.php');
        self::assertStringContainsString("paginate(\$perPage,['*'],'page'",$accounts);
        self::assertStringContainsString("(int)(\$filters['per_page']??25)",$opening);
        self::assertStringContainsString('min(100, max(25',$accounts);
        self::assertStringContainsString('min(100,max(25',$opening);
        self::assertStringContainsString("'exchange_page'",$setup);
        self::assertStringContainsString("'exchange_per_page'",$setup);
        self::assertStringContainsString('paginate(min(100,max(25',$setup);
        self::assertStringContainsString("orderByDesc('id')",$setup);
    }
}
