<?php

namespace Tests\Feature\PhaseE;

use App\Http\Controllers\Api\FinancialSetupController;
use App\Services\Accounting\AccountingContext;
use App\Services\FinancialAccountService;
use App\Services\OpeningBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class P1GovernanceBehaviorTest extends TestCase
{
    protected function setUp():void
    {
        parent::setUp();
        self::assertSame('sqlite',DB::connection()->getDriverName());
        self::assertSame(':memory:',DB::connection()->getDatabaseName());
        Schema::create('branches',function(Blueprint$t){$t->id();$t->integer('company_id');$t->string('branch_name');$t->boolean('is_active')->default(true);});
        Schema::create('cost_centers',function(Blueprint$t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();$t->integer('parent_id')->nullable();$t->string('cost_center_code');$t->string('cost_center_name');$t->boolean('is_active')->default(true);$t->boolean('is_group')->default(false);$t->timestamps();});
        Schema::create('financial_accounts',function(Blueprint$t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();$t->integer('gl_account_id')->default(1);$t->string('account_code')->default('FA');$t->string('account_name')->default('Account');$t->string('account_type')->default('CASH');$t->string('currency_code')->default('SAR');$t->boolean('is_active')->default(true);$t->boolean('is_default_receipt')->default(false);$t->boolean('is_default_payment')->default(false);$t->timestamps();});
        Schema::create('branch_financial_settings',function(Blueprint$t){$t->id();$t->integer('company_id');$t->integer('branch_id');foreach(['default_cash_financial_account_id','default_bank_financial_account_id','default_wallet_financial_account_id','default_cost_center_id']as$c)$t->integer($c)->nullable();$t->timestamps();});
        Schema::create('journal_entry_lines',function(Blueprint$t){$t->id();$t->integer('company_id');$t->integer('financial_account_id')->nullable();});
        Schema::create('accounts',function(Blueprint$t){$t->id();$t->integer('company_id');$t->string('account_code');$t->string('account_name');$t->boolean('is_active')->default(true);$t->boolean('is_group')->default(false);$t->boolean('allow_posting')->default(true);});
        Schema::create('items',function(Blueprint$t){$t->id();$t->integer('company_id');$t->string('item_code');$t->string('item_name');$t->boolean('is_active')->default(true);});
        DB::table('branches')->insert([['id'=>1,'company_id'=>1,'branch_name'=>'A'],['id'=>2,'company_id'=>1,'branch_name'=>'B'],['id'=>3,'company_id'=>2,'branch_name'=>'Foreign']]);
    }

    private function center(array $overrides=[]):int{return DB::table('cost_centers')->insertGetId(array_merge(['company_id'=>1,'branch_id'=>1,'cost_center_code'=>uniqid('CC'),'cost_center_name'=>'Center','is_group'=>1,'is_active'=>1],$overrides));}
    private function saveCenter(array$data,?int$branch=null):int{$r=Request::create('/api/financial-setup/cost-center','POST',array_merge(['cost_center_code'=>'NEW','cost_center_name'=>'New center'],$data));$r->attributes->set('tenant_company_id',1);$r->attributes->set('effective_role_code',$branch?'BRANCH_MANAGER':'COMPANY_OWNER');$r->attributes->set('tenant_branch_id',$branch);return app(FinancialSetupController::class)->costCenter($r,app(AccountingContext::class))->getStatusCode();}

    public function test_parent_company_activity_group_and_branch_are_enforced():void
    {
        foreach([['company_id'=>2,'branch_id'=>3],['is_active'=>0],['is_group'=>0],['branch_id'=>2]]as$case){$id=$this->center($case);self::assertSame(422,$this->saveCenter(['parent_id'=>$id,'branch_id'=>1]));}
        $id=$this->center(['branch_id'=>null]);self::assertSame(200,$this->saveCenter(['parent_id'=>$id,'branch_id'=>1]));
    }

    public function test_self_cycle_and_foreign_branch_updates_are_rejected():void
    {
        $a=$this->center();$b=$this->center(['parent_id'=>$a]);
        self::assertSame(422,$this->saveCenter(['id'=>$a,'parent_id'=>$a,'branch_id'=>1]));
        self::assertSame(422,$this->saveCenter(['id'=>$a,'parent_id'=>$b,'branch_id'=>1]));
        $foreign=$this->center(['branch_id'=>2]);self::assertSame(403,$this->saveCenter(['id'=>$foreign],1));
    }

    public function test_used_financial_account_is_deactivated_and_all_defaults_are_cleared():void
    {
        $id=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>null,'is_default_receipt'=>1,'is_default_payment'=>1]);
        DB::table('journal_entry_lines')->insert(['company_id'=>1,'financial_account_id'=>$id]);
        DB::table('branch_financial_settings')->insert(['company_id'=>1,'branch_id'=>1,'default_cash_financial_account_id'=>$id,'default_bank_financial_account_id'=>$id,'default_wallet_financial_account_id'=>$id]);
        app(FinancialAccountService::class)->deactivateOrDelete(1,$id);
        self::assertSame(1,DB::table('financial_accounts')->count());self::assertSame(0,(int)DB::table('financial_accounts')->value('is_active'));
        foreach(['default_cash_financial_account_id','default_bank_financial_account_id','default_wallet_financial_account_id']as$c)self::assertNull(DB::table('branch_financial_settings')->value($c));
        self::assertSame(1,DB::table('journal_entry_lines')->count());
    }

    public function test_financial_account_pagination_search_and_scope():void
    {
        DB::table('accounts')->insert(['id'=>1,'company_id'=>1,'account_code'=>'1110','account_name'=>'Cash']);
        for($i=1;$i<=31;$i++)DB::table('financial_accounts')->insert(['company_id'=>1,'branch_id'=>1,'account_code'=>'FA'.$i,'account_name'=>sprintf('Account %03d',$i)]);
        DB::table('financial_accounts')->insert(['company_id'=>2,'branch_id'=>3]);
        $service=app(FinancialAccountService::class);$page=$service->list(1,1,['per_page'=>25]);self::assertSame(31,$page->total());self::assertCount(25,$page->items());
        $search=$service->list(1,1,['search'=>'Account 031']);self::assertSame(1,$search->total());
        self::assertSame(100,$service->list(1,null,['per_page'=>1000])->perPage());
        $second=$service->list(1,1,['page'=>2]);self::assertSame(2,$second->currentPage());self::assertCount(6,$second->items());self::assertSame(31,$second->total());
        DB::table('financial_accounts')->insert(['company_id'=>1,'branch_id'=>null]);self::assertSame(32,$service->list(1,1)->total());
    }

    public function test_opening_lookup_is_bounded_and_searches_beyond_first_page_without_tenant_leak():void
    {
        for($i=1;$i<=31;$i++)DB::table('items')->insert(['company_id'=>1,'item_code'=>'I'.$i,'item_name'=>sprintf('Item %03d',$i)]);
        DB::table('items')->insert(['company_id'=>2,'item_code'=>'FOREIGN','item_name'=>'Item 031']);
        $service=app(OpeningBalanceService::class);self::assertCount(25,$service->lookup(1,'items'));$rows=$service->lookup(1,'items','Item 031');self::assertCount(1,$rows);self::assertSame('I31',$rows[0]->item_code);
    }
}
