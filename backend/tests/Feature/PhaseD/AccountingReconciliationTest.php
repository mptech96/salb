<?php

declare(strict_types=1);

namespace Tests\Feature\PhaseD;

use App\Domain\Accounting\Services\AccountingReportService;
use App\Domain\Accounting\Services\FinancialYearService;
use App\Domain\Accounting\Services\JournalService;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Services\Accounting\AccountingContext;
use App\Services\FinancialAccountService;
use App\Services\FixedAssets\FixedAssetYearEndService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AccountingReconciliationTest extends TestCase
{
    private AccountingReportService $reports;
    private int $asset;
    private int $liability;
    private int $equity;
    private int $retained;
    private int $currentResult;
    private int $revenue;
    private int $cogs;
    private int $expense;

    protected function setUp(): void
    {
        parent::setUp();
        foreach(['financial_year_closures','accounting_settings','financial_accounts','journal_entry_lines','journal_entries','cost_centers','branches','financial_years','accounts','users']as$t)Schema::dropIfExists($t);
        Schema::create('users',fn(Blueprint$t)=>[$t->id(),$t->string('name')]);
        Schema::create('branches',fn(Blueprint$t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->string('branch_name'),$t->boolean('is_active')->default(true)]);
        Schema::create('cost_centers',fn(Blueprint$t)=>[$t->id(),$t->unsignedBigInteger('company_id'),$t->unsignedBigInteger('branch_id')->nullable(),$t->string('cost_center_code')->nullable(),$t->string('cost_center_name')->nullable(),$t->boolean('is_active')->default(true)]);
        Schema::create('financial_years',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->string('year_name');$t->date('start_date');$t->date('end_date');$t->boolean('is_closed')->default(false);$t->timestamp('closed_at')->nullable();$t->unsignedBigInteger('closed_by')->nullable();$t->timestamps();});
        Schema::create('accounts',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->string('normal_side');$t->integer('account_level')->default(1);$t->boolean('is_group')->default(false);$t->boolean('allow_posting')->default(true);$t->boolean('allow_cost_center')->default(false);$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('journal_entries',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('financial_year_id')->nullable();$t->unsignedBigInteger('cost_center_id')->nullable();$t->string('entry_number')->nullable();$t->string('reference_no')->nullable();$t->date('entry_date');$t->string('source_type')->nullable();$t->unsignedBigInteger('source_id')->nullable();$t->unsignedBigInteger('reversal_of_id')->nullable();$t->unsignedBigInteger('reversed_by_id')->nullable();$t->timestamp('reversed_at')->nullable();$t->text('reversal_reason')->nullable();$t->text('description')->nullable();$t->string('status')->default('POSTED');$t->string('currency_code')->nullable();$t->decimal('exchange_rate',24,10)->nullable();$t->boolean('is_closing_entry')->default(false);$t->boolean('is_system_generated')->default(false);$t->unsignedBigInteger('created_by')->nullable();$t->timestamps();});
        Schema::create('journal_entry_lines',function(Blueprint$t){$t->id();$t->unsignedBigInteger('journal_entry_id');$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('financial_year_id')->nullable();$t->unsignedBigInteger('cost_center_id')->nullable();$t->unsignedBigInteger('account_id');$t->unsignedBigInteger('financial_account_id')->nullable();$t->unsignedBigInteger('counterparty_branch_id')->nullable();$t->string('party_type')->nullable();$t->unsignedBigInteger('party_id')->nullable();$t->string('currency_code')->nullable();$t->decimal('foreign_debit',18,3)->default(0);$t->decimal('foreign_credit',18,3)->default(0);$t->decimal('exchange_rate',24,10)->nullable();$t->decimal('debit',18,3)->default(0);$t->decimal('credit',18,3)->default(0);$t->text('description')->nullable();$t->timestamps();});
        Schema::create('financial_accounts',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->unsignedBigInteger('gl_account_id');$t->string('currency_code');$t->boolean('is_active')->default(true);});
        Schema::create('accounting_settings',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->string('setting_key');$t->unsignedBigInteger('account_id')->nullable();});
        Schema::create('financial_year_closures',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('financial_year_id');$t->string('closure_number');$t->date('close_date');$t->decimal('revenue_total',18,3);$t->decimal('expense_total',18,3);$t->decimal('net_result',18,3);$t->unsignedBigInteger('profit_loss_entry_id')->nullable();$t->unsignedBigInteger('retained_earnings_entry_id')->nullable();$t->unsignedBigInteger('next_financial_year_id')->nullable();$t->string('status');$t->unsignedBigInteger('closed_by')->nullable();$t->unsignedBigInteger('reopened_by')->nullable();$t->timestamp('reopened_at')->nullable();$t->timestamps();});
        DB::table('financial_years')->insert(['id'=>1,'company_id'=>1,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
        $accountIds=[];foreach([['1000','Assets','ASSET','DEBIT'],['2000','Liabilities','LIABILITY','CREDIT'],['3000','Equity','EQUITY','CREDIT'],['3300','Retained','EQUITY','CREDIT'],['3400','Current result','EQUITY','CREDIT'],['4000','Revenue','REVENUE','CREDIT'],['5100','COGS','EXPENSE','DEBIT'],['6900','Expense','EXPENSE','DEBIT']]as$a)$accountIds[]=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>$a[0],'account_name'=>$a[1],'account_type'=>$a[2],'normal_side'=>$a[3],'is_group'=>0,'allow_posting'=>1,'is_active'=>1]);
        [$this->asset,$this->liability,$this->equity,$this->retained,$this->currentResult,$this->revenue,$this->cogs,$this->expense]=$accountIds;
        DB::table('accounting_settings')->insert([['company_id'=>1,'setting_key'=>'CURRENT_YEAR_RESULT_ACCOUNT','account_id'=>$this->currentResult],['company_id'=>1,'setting_key'=>'RETAINED_EARNINGS_ACCOUNT','account_id'=>$this->retained]]);
        $this->reports=app(AccountingReportService::class);
    }

    public function test_posted_only_gl_pagination_full_scope_totals_and_export(): void
    {
        for($i=1;$i<=30;$i++)$this->journal('2026-02-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),'POSTED',[[$this->asset,10,0],[$this->liability,0,10]],1,'needle '.$i);
        $this->journal('2026-02-01','DRAFT',[[$this->asset,999,0],[$this->liability,0,999]],1,'draft needle');
        $page=$this->reports->ledger(1,1,$this->asset,['from_date'=>'2026-02-01','to_date'=>'2026-03-31','page'=>2,'per_page'=>25]);
        self::assertSame(30,$page['data']['total']);self::assertCount(5,$page['rows']);self::assertSame(300.0,$page['total_debit']);self::assertSame(0.0,$page['total_credit']);self::assertSame(250.0,(float)$page['page_opening_balance']);self::assertSame(260.0,(float)$page['rows'][0]->running_balance);self::assertSame(300.0,(float)$page['closing_balance']);
        self::assertCount(30,iterator_to_array($this->reports->ledgerExportRows(1,1,$this->asset,['from_date'=>'2026-02-01','to_date'=>'2026-03-31'])));
        $search=$this->reports->ledger(1,1,$this->asset,['from_date'=>'2026-02-01','to_date'=>'2026-03-31','search'=>'needle 30']);self::assertSame(1,$search['data']['total']);self::assertSame(10.0,$search['total_debit']);
    }

    public function test_trial_income_balance_reversal_dates_and_isolation_reconcile(): void
    {
        $this->journal('2026-03-01','POSTED',[[$this->asset,100,0],[$this->revenue,0,100]],1,'sale');
        $this->journal('2026-03-02','POSTED',[[$this->expense,25,0],[$this->asset,0,25]],1,'expense');
        $original=$this->journal('2026-03-03','POSTED',[[$this->asset,5,0],[$this->liability,0,5]],1,'manual');
        $this->journal('2026-03-04','POSTED',[[$this->liability,5,0],[$this->asset,0,5]],1,'reverse','REVERSAL',$original);
        $this->journal('2026-03-01','POSTED',[[$this->asset,777,0],[$this->liability,0,777]],2,'foreign company','MANUAL',null,false,2);
        $tb=$this->reports->trialBalance(1,1,['from_date'=>'2026-03-01','to_date'=>'2026-03-31']);self::assertSame(135.0,$tb['totals']['period_debit']);self::assertSame(135.0,$tb['totals']['period_credit']);self::assertSame(0.0,$tb['totals']['difference']);
        $income=$this->reports->incomeStatement(1,1,['from_date'=>'2026-03-01','to_date'=>'2026-03-31']);self::assertSame(100.0,$income['revenue_total']);self::assertSame(25.0,$income['operating_expenses_total']);self::assertSame(75.0,$income['net_result']);
        $bs=$this->reports->balanceSheet(1,1,['as_of'=>'2026-03-31']);self::assertSame(0.0,$bs['difference']);self::assertSame(75.0,$bs['current_period_result']);
        $before=$this->reports->ledger(1,1,$this->asset,['from_date'=>'2026-03-01','to_date'=>'2026-03-03']);$after=$this->reports->ledger(1,1,$this->asset,['from_date'=>'2026-03-01','to_date'=>'2026-03-04']);self::assertSame(80.0,$before['closing_balance']);self::assertSame(75.0,$after['closing_balance']);
    }

    public function test_invalid_foreign_year_and_out_of_year_period_are_rejected(): void
    {
        DB::table('financial_years')->insert(['id'=>2,'company_id'=>2,'year_name'=>'X','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
        $this->expectException(\RuntimeException::class);$this->reports->trialBalance(1,null,['financial_year_id'=>2]);
    }

    public function test_explicit_financial_year_rejects_dates_outside_its_bounds_and_cogs_prefix_is_characterized(): void
    {
        try{$this->reports->trialBalance(1,null,['financial_year_id'=>1,'from_date'=>'2025-12-31','to_date'=>'2026-01-31']);self::fail('Out-of-year period accepted');}catch(\RuntimeException){}
        $this->journal('2026-07-01','POSTED',[[$this->asset,100,0],[$this->revenue,0,100]],1,'revenue');$this->journal('2026-07-02','POSTED',[[$this->cogs,30,0],[$this->asset,0,30]],1,'cogs');
        $income=$this->reports->incomeStatement(1,1,['financial_year_id'=>1,'from_date'=>'2026-07-01','to_date'=>'2026-07-31']);self::assertSame(30.0,$income['cost_of_revenue_total']);self::assertSame(70.0,$income['net_result']);
    }

    public function test_financial_accounts_are_posted_only_and_show_truthful_gl_difference(): void
    {
        $branch=DB::table('branches')->insertGetId(['company_id'=>1,'branch_name'=>'Main','is_active'=>1]);$fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>$branch,'account_code'=>'C1','account_name'=>'Cash','account_type'=>'CASH','gl_account_id'=>$this->asset,'currency_code'=>'SAR','is_active'=>1]);
        $j=$this->journal('2026-04-01','POSTED',[[$this->asset,10,0],[$this->liability,0,10]],$branch,'untagged');
        DB::table('journal_entry_lines')->where('journal_entry_id',$j)->where('account_id',$this->asset)->update(['financial_account_id'=>$fa]);
        $this->journal('2026-04-02','POSTED',[[$this->asset,5,0],[$this->liability,0,5]],$branch,'unmatched');
        $this->journal('2026-04-03','DRAFT',[[$this->asset,999,0],[$this->liability,0,999]],$branch,'draft');
        $context=Mockery::mock(AccountingContext::class);$context->shouldReceive('companyId')->andReturn(1);$context->shouldReceive('branchFilter')->andReturn(null);
        $service=Mockery::mock(FinancialAccountService::class);$service->shouldReceive('list')->andReturn(DB::table('financial_accounts as fa')->leftJoin('branches as b','b.id','=','fa.branch_id')->leftJoin('accounts as a','a.id','=','fa.gl_account_id')->where('fa.company_id',1)->select('fa.*','b.branch_name','a.account_code as gl_account_code','a.account_name as gl_account_name')->get());
        $response=app(FinancialAccountController::class)->index(Request::create('/api/financial-accounts'),$context,$service)->getData(true);$row=$response['data'][0];self::assertSame(10.0,(float)$row['tagged_balance']);self::assertSame(15.0,(float)$row['gl_balance']);self::assertSame(5.0,(float)$row['reconciliation_difference']);self::assertFalse($row['is_reconciled']);self::assertSame(1,$row['unmatched_lines_count']);
    }

    public function test_close_and_reopen_are_balanced_idempotent_and_restore_open_reporting(): void
    {
        $this->journal('2026-05-01','POSTED',[[$this->asset,100,0],[$this->revenue,0,100]],null,'sale');
        $assets=Mockery::mock(FixedAssetYearEndService::class);$assets->shouldReceive('missingCount')->andReturn(0);$assets->shouldReceive('complete')->once()->andReturn([]);
        $service=new FinancialYearService(app(JournalService::class),$this->reports,app(\App\Services\Accounting\PostingSupport::class),$assets);
        $closed=$service->close(1,1,1);self::assertNotNull($closed['profit_loss_entry_id']);self::assertNotNull($closed['retained_earnings_entry_id']);self::assertSame(2,DB::table('journal_entries')->where('is_closing_entry',1)->count());
        try{$service->close(1,1,1);self::fail('Duplicate close accepted');}catch(\RuntimeException){}self::assertSame(2,DB::table('journal_entries')->where('is_closing_entry',1)->count());
        foreach(DB::table('journal_entries')->where('is_closing_entry',1)->pluck('id')as$id){$sum=DB::table('journal_entry_lines')->where('journal_entry_id',$id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertSame((float)$sum->d,(float)$sum->c);}
        $service->reopen(1,1,1);self::assertSame(0,(int)DB::table('financial_years')->where('id',1)->value('is_closed'));self::assertSame(4,DB::table('journal_entries')->where('is_closing_entry',1)->count());self::assertSame(100.0,$this->reports->incomeStatement(1,null,['from_date'=>'2026-01-01','to_date'=>'2026-12-31'])['net_result']);
    }

    public function test_closed_year_branch_balance_keeps_branch_result_without_synthetic_journal(): void
    {
        $branch=DB::table('branches')->insertGetId(['company_id'=>1,'branch_name'=>'B','is_active'=>1]);$this->journal('2026-06-01','POSTED',[[$this->asset,40,0],[$this->revenue,0,40]],$branch,'branch sale');DB::table('financial_years')->where('id',1)->update(['is_closed'=>1]);
        // محاكاة ترحيل النتيجة الموحد على مستوى الشركة.
        $this->journal('2026-12-31','POSTED',[[$this->revenue,40,0],[$this->currentResult,0,40]],null,'close','YEAR_CLOSE_PNL',1,true);$this->journal('2026-12-31','POSTED',[[$this->currentResult,40,0],[$this->retained,0,40]],null,'retained','YEAR_CLOSE_RETAINED',1,true);
        $branchBs=$this->reports->balanceSheet(1,$branch,['as_of'=>'2026-12-31']);self::assertSame(40.0,$branchBs['current_period_result']);self::assertSame(0.0,$branchBs['difference']);
        $companyBs=$this->reports->balanceSheet(1,null,['as_of'=>'2026-12-31']);self::assertSame(0.0,$companyBs['current_period_result']);self::assertSame(0.0,$companyBs['difference']);
    }

    private function journal(string $date,string $status,array $lines,?int $branch,string $description,string $source='MANUAL',?int $sourceId=null,bool $closing=false,int $company=1): int
    {
        $id=DB::table('journal_entries')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'financial_year_id'=>1,'entry_number'=>'J-'.uniqid(),'entry_date'=>$date,'source_type'=>$source,'source_id'=>$sourceId,'description'=>$description,'status'=>$status,'is_closing_entry'=>$closing?1:0,'is_system_generated'=>0]);
        foreach($lines as[$account,$debit,$credit])DB::table('journal_entry_lines')->insert(['journal_entry_id'=>$id,'company_id'=>$company,'branch_id'=>$branch,'financial_year_id'=>1,'account_id'=>$account,'debit'=>$debit,'credit'=>$credit,'description'=>$description]);return$id;
    }
}
