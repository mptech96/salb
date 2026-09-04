<?php

namespace Tests\Feature\PhaseE;

use Tests\TestCase;

class P0AccountingCorrectnessTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(app_path($path));
    }

    public function test_currency_and_tax_p0_guards_are_present(): void
    {
        $setup=$this->source('Http/Controllers/Api/FinancialSetupController.php');
        $bootstrap=$this->source('Domain/Accounting/Services/AccountingBootstrapService.php');

        self::assertStringContainsString("['is_base'=>1,'is_active'=>1",$bootstrap);
        self::assertStringContainsString("'currency_status'=>\$currencyStatus",$setup);
        self::assertStringContainsString("'active_base_count'=>\$bases->count()",$setup);
        self::assertStringContainsString("'tax_status'=>",$setup);
        self::assertStringContainsString('لا يمكن تغيير العملة الأساسية بعد وجود حركات مالية أو مخزنية تاريخية',$setup);
        self::assertStringContainsString("DB::table('currencies')->where('currency_code',\$code)->where('is_active',1)",$setup);
        self::assertStringNotContainsString("DB::table('currencies')->updateOrInsert",$setup);
        self::assertStringContainsString("where('company_id',\$cid)->where('id',\$id)->exists()",$setup);
        self::assertStringContainsString('after_or_equal:valid_from',$setup);
        self::assertStringNotContainsString('VAT15',$bootstrap);
        self::assertStringNotContainsString("'rate'=>15",$bootstrap);
    }

    public function test_opening_balance_p0_contract_is_enforced(): void
    {
        $service=$this->source('Services/OpeningBalanceService.php');
        $controller=$this->source('Http/Controllers/Api/OpeningBalanceController.php');
        $reports=$this->source('Services/ReportCenterService.php');

        self::assertStringContainsString('requires_balancing_confirmation',$service);
        self::assertStringContainsString('balancing_account_code',$service);
        self::assertStringContainsString('balancing_account_name',$service);
        self::assertStringContainsString('if(!$confirmBalancing)throw',$service);
        self::assertStringContainsString("\$partyType==='CUSTOMER'",$service);
        self::assertStringContainsString("'CUSTOMER_ACCOUNT'",$service);
        self::assertStringContainsString("\$partyType==='SUPPLIER'",$service);
        self::assertStringContainsString("'SUPPLIER_ACCOUNT'",$service);
        self::assertStringContainsString('assertAccessible($companyId,$type,$id,$branchId)',$service);
        self::assertStringContainsString('scopeQuery',$service);
        self::assertStringContainsString('تحتوي الدفعة على بيانات خارج نطاق الفرع',$service);
        self::assertStringContainsString('المبلغ الأجنبي وسعر الصرف لا يطابقان مبلغ الأساس',$service);
        self::assertStringContainsString("'confirm_balancing'=>'nullable|boolean'",$controller);
        self::assertStringContainsString('$c->branchFilter($r)',$controller);
        self::assertStringContainsString("where('j.source_type','OPENING_BALANCE')",$reports);
        self::assertStringNotContainsString('$opening=(float)$e->opening_balance',$reports);
    }

    public function test_financial_account_history_and_coa_permissions_are_guarded(): void
    {
        $financial=$this->source('Services/FinancialAccountService.php');
        $permissions=$this->source('Http/Middleware/EnsureRoutePermission.php');

        self::assertStringContainsString("where('financial_account_id',\$id)->exists()",$financial);
        self::assertStringContainsString('لا يمكن تغيير حساب الأستاذ أو العملة أو الفرع بعد وجود حركات تاريخية',$financial);
        self::assertStringContainsString("if (str_starts_with(\$uri, 'accounts')) return in_array(\$method,['GET','HEAD'],true) ? 'statements.view' : 'financial_setup.manage';",$permissions);
    }
}
