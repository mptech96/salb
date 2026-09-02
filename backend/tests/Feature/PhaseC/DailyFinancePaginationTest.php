<?php

namespace Tests\Feature\PhaseC;

use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\VoucherController;
use App\Services\Accounting\AccountingContext;
use App\Services\Accounting\AccountingEngine;
use App\Services\Accounting\PostingResult;
use App\Services\FinancialAccountService;
use App\Services\PartyBranchScopeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Mockery;

class DailyFinancePaginationTest extends TestCase
{
    private AccountingContext $context;
    private int $companyA;
    private int $companyB;
    private int $branchA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['journal_entry_lines','journal_entries','expenses','vouchers','expense_types','voucher_types','financial_accounts','accounts','branches','users','shipments','cars','drivers','workers','purchase_invoices','sales_invoices'] as $table) Schema::dropIfExists($table);
        Schema::create('branches',fn(Blueprint$t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string('branch_name'),$t->boolean('is_active')->default(true)]);
        Schema::create('users',fn(Blueprint$t)=>[$t->id(),$t->string('name')]);
        Schema::create('accounts',fn(Blueprint$t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string('account_code'),$t->string('account_name'),$t->string('account_type'),$t->boolean('is_active')->default(true),$t->boolean('is_group')->default(false),$t->boolean('allow_posting')->default(true)]);
        Schema::create('expense_types',fn(Blueprint$t)=>[$t->id(),$t->unsignedBigInteger('company_id')->nullable(),$t->string('type_name'),$t->string('type_code')->nullable(),$t->text('description')->nullable(),$t->boolean('affects_cost')->default(true)]);
        foreach(['shipments'=>['shipment_number'],'cars'=>['car_number'],'drivers'=>['driver_name'],'workers'=>['worker_name'],'purchase_invoices'=>['invoice_number'],'sales_invoices'=>['invoice_number']]as$table=>$cols)Schema::create($table,function(Blueprint$t)use($cols){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();foreach($cols as$c)$t->string($c)->nullable();});
        Schema::create('financial_accounts',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('gl_account_id');$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->string('currency_code');$t->boolean('is_active')->default(true);});
        Schema::create('voucher_types',fn(Blueprint$t)=>[$t->id(),$t->string('type_name'),$t->string('type_code')]);
        Schema::create('vouchers',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('voucher_type_id');$t->string('voucher_number');$t->date('voucher_date');$t->string('reference_type');$t->unsignedBigInteger('reference_id');$t->decimal('amount',18,3);$t->unsignedBigInteger('financial_account_id')->nullable();$t->unsignedBigInteger('cash_account_id')->nullable();$t->unsignedBigInteger('journal_entry_id')->nullable();$t->string('payment_method')->nullable();$t->string('currency_code')->nullable();$t->decimal('exchange_rate',18,8)->nullable();$t->decimal('foreign_amount',18,3)->nullable();$t->unsignedBigInteger('created_by')->nullable();$t->text('notes')->nullable();$t->timestamps();});
        Schema::create('expenses',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('expense_type_id');$t->date('expense_date');$t->decimal('amount',18,3);$t->string('payment_status');$t->unsignedBigInteger('financial_account_id')->nullable();$t->unsignedBigInteger('voucher_id')->nullable();$t->unsignedBigInteger('journal_entry_id')->nullable();foreach(['shipment_id','car_id','driver_id','worker_id','purchase_invoice_id','sales_invoice_id']as$c)$t->unsignedBigInteger($c)->nullable();$t->text('notes')->nullable();});
        Schema::create('journal_entries',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->date('entry_date');$t->string('entry_number');$t->string('reference_no')->nullable();$t->text('description')->nullable();$t->string('source_type');$t->unsignedBigInteger('source_id')->nullable();$t->string('status');$t->unsignedBigInteger('created_by')->nullable();});
        Schema::create('journal_entry_lines',function(Blueprint$t){$t->id();$t->unsignedBigInteger('journal_entry_id');$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('account_id');$t->unsignedBigInteger('financial_account_id')->nullable();$t->decimal('debit',18,3)->default(0);$t->decimal('credit',18,3)->default(0);$t->text('description')->nullable();$t->string('currency_code')->nullable();$t->decimal('foreign_debit',18,3)->default(0);$t->decimal('foreign_credit',18,3)->default(0);});
        $this->companyA=1;$this->companyB=2;$this->branchA=DB::table('branches')->insertGetId(['company_id'=>1,'branch_name'=>'A','is_active'=>1]);$this->branchB=DB::table('branches')->insertGetId(['company_id'=>2,'branch_name'=>'B','is_active'=>1]);$this->context=app(AccountingContext::class);
        DB::table('expense_types')->insert(['id'=>1,'type_name'=>'Fuel','type_code'=>'FUEL']);DB::table('voucher_types')->insert([['id'=>1,'type_name'=>'Receipt','type_code'=>'RECEIPT'],['id'=>2,'type_name'=>'Payment','type_code'=>'PAYMENT']]);
    }

    public function test_expenses_are_server_paginated_filtered_and_tenant_scoped(): void
    {
        for($i=1;$i<=31;$i++)DB::table('expenses')->insert(['company_id'=>1,'branch_id'=>$this->branchA,'expense_type_id'=>1,'expense_date'=>'2026-08-'.str_pad((string)(($i%28)+1),2,'0',STR_PAD_LEFT),'amount'=>$i,'payment_status'=>$i%2?'PAID':'UNPAID','notes'=>$i===30?'needle':null]);
        DB::table('expenses')->insert(['company_id'=>2,'branch_id'=>$this->branchB,'expense_type_id'=>1,'expense_date'=>'2026-08-01','amount'=>999,'payment_status'=>'PAID']);
        $response=app(ExpenseController::class)->index($this->request(['page'=>2,'per_page'=>25]),$this->context)->getData(true);
        self::assertSame(31,$response['data']['total']);self::assertCount(6,$response['data']['data']);self::assertSame(2,$response['data']['current_page']);
        $search=app(ExpenseController::class)->index($this->request(['search'=>'needle']),$this->context)->getData(true);self::assertSame(1,$search['data']['total']);self::assertSame(30,(int)$search['summary']['filtered_total']);
    }

    public function test_vouchers_and_journals_are_stably_paginated_without_cross_company_rows(): void
    {
        for($i=1;$i<=30;$i++){DB::table('vouchers')->insert(['company_id'=>1,'branch_id'=>$this->branchA,'voucher_type_id'=>$i%2?1:2,'voucher_number'=>'V-'.$i,'voucher_date'=>'2026-08-01','reference_type'=>'ACCOUNT','reference_id'=>1,'amount'=>$i]);DB::table('journal_entries')->insert(['company_id'=>1,'branch_id'=>$this->branchA,'entry_date'=>'2026-08-01','entry_number'=>'J-'.$i,'description'=>'Entry '.$i,'source_type'=>'MANUAL','status'=>'POSTED']);}
        DB::table('vouchers')->insert(['company_id'=>2,'branch_id'=>$this->branchB,'voucher_type_id'=>1,'voucher_number'=>'FOREIGN','voucher_date'=>'2026-08-01','reference_type'=>'ACCOUNT','reference_id'=>1,'amount'=>999]);
        $v=app(VoucherController::class)->index($this->request(['page'=>2,'per_page'=>25]),$this->context)->getData(true);self::assertSame(30,$v['data']['total']);self::assertCount(5,$v['data']['data']);
        $j=app(JournalEntryController::class)->index($this->request(['page'=>1,'per_page'=>25,'search'=>'J-30']),$this->context)->getData(true);self::assertSame(1,$j['data']['total']);self::assertSame('J-30',$j['data']['data'][0]['entry_number']);
    }

    public function test_financial_account_running_balance_carries_across_pages(): void
    {
        $gl=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'1110','account_name'=>'Cash','account_type'=>'ASSET','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);$fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>$this->branchA,'gl_account_id'=>$gl,'account_code'=>'C1','account_name'=>'Cash','account_type'=>'CASH','currency_code'=>'SAR','is_active'=>1]);
        for($i=1;$i<=30;$i++){$jid=DB::table('journal_entries')->insertGetId(['company_id'=>1,'branch_id'=>$this->branchA,'entry_date'=>'2026-08-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'entry_number'=>'J'.$i,'source_type'=>'VOUCHER','status'=>'POSTED']);DB::table('journal_entry_lines')->insert(['journal_entry_id'=>$jid,'company_id'=>1,'branch_id'=>$this->branchA,'account_id'=>$gl,'financial_account_id'=>$fa,'debit'=>10,'credit'=>0]);}
        $response=app(FinancialAccountController::class)->transactions($this->request(['page'=>2,'per_page'=>25]),$fa,$this->context)->getData(true);self::assertSame(250.0,(float)$response['summary']['opening_balance']);self::assertSame(300.0,(float)$response['summary']['closing_balance']);self::assertSame(260.0,(float)$response['data']['data'][0]['running_balance']);
    }

    public function test_voucher_number_is_truly_optional_and_is_generated(): void
    {
        $request=Request::create('/api/vouchers','POST',['branch_id'=>$this->branchA,'voucher_type_id'=>1,'voucher_date'=>'2026-08-01','reference_type'=>'ACCOUNT','reference_id'=>10,'amount'=>10,'payment_method'=>'CASH','financial_account_id'=>5,'currency_code'=>'SAR','exchange_rate'=>1]);
        $context=Mockery::mock(AccountingContext::class);$context->shouldReceive('companyId')->andReturn(1);$context->shouldReceive('branchForOperation')->andReturn($this->branchA);$context->shouldReceive('userId')->andReturn(1);
        $money=Mockery::mock(FinancialAccountService::class);$money->shouldReceive('resolve')->andReturn((object)['id'=>5,'gl_account_id'=>10,'currency_code'=>'SAR']);$money->shouldReceive('assertCurrency');
        $engine=Mockery::mock(AccountingEngine::class);$engine->shouldReceive('voucher')->andReturn(PostingResult::success('ok',77));
        $response=app(VoucherController::class)->store($request,$engine,$context,Mockery::mock(PartyBranchScopeService::class),$money);
        self::assertSame(201,$response->getStatusCode());self::assertStringStartsWith('REC-2026-',DB::table('vouchers')->value('voucher_number'));
    }

    public function test_branch_scoped_reads_never_include_another_branch(): void
    {
        $other=DB::table('branches')->insertGetId(['company_id'=>1,'branch_name'=>'A2','is_active'=>1]);
        foreach([$this->branchA,$other]as$i=>$branch){DB::table('expenses')->insert(['company_id'=>1,'branch_id'=>$branch,'expense_type_id'=>1,'expense_date'=>'2026-08-01','amount'=>10+$i,'payment_status'=>'PAID']);DB::table('vouchers')->insert(['company_id'=>1,'branch_id'=>$branch,'voucher_type_id'=>1,'voucher_number'=>'B'.$i,'voucher_date'=>'2026-08-01','reference_type'=>'ACCOUNT','reference_id'=>1,'amount'=>10]);DB::table('journal_entries')->insert(['company_id'=>1,'branch_id'=>$branch,'entry_date'=>'2026-08-01','entry_number'=>'B-J'.$i,'source_type'=>'MANUAL','status'=>'POSTED']);}
        self::assertSame(1,app(ExpenseController::class)->index($this->request([], $this->branchA),$this->context)->getData(true)['data']['total']);
        self::assertSame(1,app(VoucherController::class)->index($this->request([], $this->branchA),$this->context)->getData(true)['data']['total']);
        self::assertSame(1,app(JournalEntryController::class)->index($this->request([], $this->branchA),$this->context)->getData(true)['data']['total']);
    }

    private function request(array$query,?int$branch=null):Request{$r=Request::create('/api/test','GET',$query);$r->attributes->set('tenant_company_id',$this->companyA);$r->attributes->set('tenant_branch_id',$branch);$r->attributes->set('effective_role_code',$branch?'BRANCH_MANAGER':'MANAGER');app()->instance('request',$r);return$r;}
}
