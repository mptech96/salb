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
use Carbon\Carbon;
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
        $service=app(FinancialAccountService::class);DB::enableQueryLog();DB::flushQueryLog();
        $response=app(FinancialAccountController::class)->index(Request::create('/api/financial-accounts'),$context,$service)->getData(true);$queries=DB::getQueryLog();DB::disableQueryLog();$row=$response['data']['data'][0];self::assertSame(10.0,(float)$row['tagged_balance']);self::assertSame(15.0,(float)$row['gl_balance']);self::assertSame(5.0,(float)$row['reconciliation_difference']);self::assertFalse($row['is_reconciled']);self::assertSame(1,$row['unmatched_lines_count']);self::assertCount(4,$queries,'Two pagination queries and two grouped aggregate queries, not per-row aggregates.');
    }

    public function test_close_and_reopen_are_balanced_idempotent_and_restore_open_reporting(): void
    {
        Carbon::setTestNow('2027-01-02 10:00:00');
        $this->journal('2026-05-01','POSTED',[[$this->asset,100,0],[$this->revenue,0,100]],null,'sale');
        $assets=Mockery::mock(FixedAssetYearEndService::class);$assets->shouldReceive('missingCount')->andReturn(0);$assets->shouldReceive('complete')->once()->andReturn([]);
        $service=new FinancialYearService(app(JournalService::class),$this->reports,app(\App\Services\Accounting\PostingSupport::class),$assets);
        try{$service->close(1,1,1);self::fail('Unconfirmed close accepted');}catch(\RuntimeException){}
        $closed=$service->close(1,1,1,true);self::assertNotNull($closed['profit_loss_entry_id']);self::assertNotNull($closed['retained_earnings_entry_id']);self::assertSame(2,DB::table('journal_entries')->where('is_closing_entry',1)->count());
        $retry=$service->close(1,1,1,true);self::assertTrue($retry['idempotent']);self::assertSame(2,DB::table('journal_entries')->where('is_closing_entry',1)->count());
        foreach(DB::table('journal_entries')->where('is_closing_entry',1)->pluck('id')as$id){$sum=DB::table('journal_entry_lines')->where('journal_entry_id',$id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertSame((float)$sum->d,(float)$sum->c);}
        $service->reopen(1,1,1);self::assertSame(0,(int)DB::table('financial_years')->where('id',1)->value('is_closed'));self::assertSame(4,DB::table('journal_entries')->where('is_closing_entry',1)->count());self::assertSame(100.0,$this->reports->incomeStatement(1,null,['from_date'=>'2026-01-01','to_date'=>'2026-12-31'])['net_result']);Carbon::setTestNow();
    }

    public function test_next_year_creation_is_company_scoped_and_idempotent(): void
    {
        $assets=Mockery::mock(FixedAssetYearEndService::class);$service=new FinancialYearService(app(JournalService::class),$this->reports,app(\App\Services\Accounting\PostingSupport::class),$assets);
        $first=$service->ensureNextYear(1,1);$second=$service->ensureNextYear(1,1);self::assertSame($first,$second);self::assertSame(1,DB::table('financial_years')->where('company_id',1)->where('year_name','2027')->count());
        try{$service->ensureNextYear(2,1);self::fail('Foreign company year accepted');}catch(\RuntimeException){}
    }

    public function test_year_end_review_reports_and_blocks_integrity_defects_without_closing(): void
    {
        Carbon::setTestNow('2027-01-02 10:00:00');
        $draft=$this->journal('2026-08-01','DRAFT',[[$this->asset,10,0],[$this->liability,0,10]],null,'draft');
        $bad=$this->journal('2026-08-02','POSTED',[[$this->asset,5,0]],null,'unbalanced');
        DB::table('accounting_settings')->where('company_id',1)->where('setting_key','RETAINED_EARNINGS_ACCOUNT')->delete();
        $assets=Mockery::mock(FixedAssetYearEndService::class);$assets->shouldReceive('missingCount')->andReturn(0);$service=new FinancialYearService(app(JournalService::class),$this->reports,app(\App\Services\Accounting\PostingSupport::class),$assets);
        $preview=$service->preview(1,1);self::assertSame('NEEDS_ATTENTION',$preview['status']);self::assertFalse($preview['can_close']);self::assertSame(1,$preview['unposted_entries_count']);self::assertNotEmpty($preview['blockers']);
        try{$service->close(1,1,1,true);self::fail('Unsafe close accepted');}catch(\RuntimeException){}
        self::assertSame(0,(int)DB::table('financial_years')->where('id',1)->value('is_closed'));self::assertSame(0,DB::table('financial_year_closures')->count());
        DB::table('journal_entry_lines')->where('journal_entry_id',$bad)->delete();DB::table('journal_entries')->whereIn('id',[$draft,$bad])->delete();Carbon::setTestNow();
    }

    public function test_closed_year_branch_balance_keeps_branch_result_without_synthetic_journal(): void
    {
        $branch=DB::table('branches')->insertGetId(['company_id'=>1,'branch_name'=>'B','is_active'=>1]);$this->journal('2026-06-01','POSTED',[[$this->asset,40,0],[$this->revenue,0,40]],$branch,'branch sale');DB::table('financial_years')->where('id',1)->update(['is_closed'=>1]);
        // محاكاة ترحيل النتيجة الموحد على مستوى الشركة.
        $this->journal('2026-12-31','POSTED',[[$this->revenue,40,0],[$this->currentResult,0,40]],null,'close','YEAR_CLOSE_PNL',1,true);$this->journal('2026-12-31','POSTED',[[$this->currentResult,40,0],[$this->retained,0,40]],null,'retained','YEAR_CLOSE_RETAINED',1,true);
        $branchBs=$this->reports->balanceSheet(1,$branch,['as_of'=>'2026-12-31']);self::assertSame(40.0,$branchBs['current_period_result']);self::assertSame(0.0,$branchBs['difference']);
        $companyBs=$this->reports->balanceSheet(1,null,['as_of'=>'2026-12-31']);self::assertSame(0.0,$companyBs['current_period_result']);self::assertSame(0.0,$companyBs['difference']);
    }

    public function test_parent_ledger_includes_deep_inactive_descendants_without_duplicates(): void
    {
        $parent=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'ROOT','account_name'=>'Parent','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>1,'allow_posting'=>0]);
        $root=$parent;
        for($i=0;$i<12;$i++) $parent=DB::table('accounts')->insertGetId(['company_id'=>1,'parent_id'=>$parent,'account_code'=>'DEEP'.$i,'account_name'=>'Deep '.$i,'account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>1,'allow_posting'=>0]);
        DB::table('accounts')->where('id',$this->asset)->update(['parent_id'=>$parent,'is_active'=>0]);
        $this->journal('2026-03-01','POSTED',[[$this->asset,800,0],[$this->equity,0,800]],1,'historical');
        $report=$this->reports->ledger(1,1,$root,['from_date'=>'2026-03-01','to_date'=>'2026-03-31']);
        self::assertSame(800.0,$report['total_debit']);
        self::assertSame(1,$report['data']['total']);
        self::assertSame(800.0,$report['closing_balance']);
    }

    public function test_real_expense_posting_from_custody_is_balanced_and_retry_has_no_duplicate(): void
    {
        Schema::dropIfExists('companies');Schema::dropIfExists('sulb_document_sequences');
        Schema::create('companies',fn(Blueprint$t)=>[$t->id(),$t->string('company_name')]);
        DB::table('companies')->insert(['id'=>1,'company_name'=>'Isolated expense company']);
        Schema::create('sulb_document_sequences',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->default(0);$t->string('document_type');$t->integer('document_year');$t->unsignedBigInteger('next_number');$t->timestamps();$t->unique(['company_id','branch_id','document_type','document_year']);});
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Test branch','is_active'=>1]);
        Schema::dropIfExists('branch_financial_settings');
        Schema::create('branch_financial_settings',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('branch_id'),$t->integer('default_cost_center_id')->nullable()]);
        Schema::dropIfExists('expenses'); Schema::dropIfExists('vouchers'); Schema::dropIfExists('voucher_types');
        Schema::create('expenses',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('journal_entry_id')->nullable();$t->integer('voucher_id')->nullable();$t->integer('financial_account_id')->nullable();$t->string('currency_code')->nullable();$t->decimal('exchange_rate',18,8)->nullable();$t->decimal('foreign_amount',18,3)->nullable();$t->timestamps();});
        Schema::create('voucher_types',fn(Blueprint $t)=>[$t->id(),$t->string('type_code')]);
        Schema::create('vouchers',function(Blueprint $t){$t->id();foreach(['company_id','branch_id','voucher_type_id','reference_id','journal_entry_id','cash_account_id','financial_account_id']as$c)$t->integer($c);foreach(['voucher_number','voucher_date','reference_type','currency_code','payment_method','notes']as$c)$t->string($c);$t->decimal('amount',18,3);$t->decimal('exchange_rate',18,8);$t->decimal('foreign_amount',18,3)->nullable();$t->integer('created_by')->nullable();$t->timestamps();});
        DB::table('voucher_types')->insert(['type_code'=>'PAYMENT']);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>$this->liability]);
        $fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>1,'gl_account_id'=>$this->asset,'account_code'=>'CUSTODY','account_name'=>'عهدة اختبار','account_type'=>'PETTY_CASH','currency_code'=>'SAR']);
        $journals=app(JournalService::class);
        $journals->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-01','description'=>'Test opening','source_type'=>'TEST','lines'=>[['account_id'=>$this->asset,'financial_account_id'=>$fa,'debit'=>5000,'credit'=>0,'currency_code'=>'SAR','exchange_rate'=>1],['account_id'=>$this->equity,'debit'=>0,'credit'=>5000]]]);
        $id=DB::table('expenses')->insertGetId(['company_id'=>1]);
        $data=['company_id'=>1,'branch_id'=>1,'expense_id'=>$id,'expense_date'=>'2026-03-02','amount'=>800,'expense_account_id'=>$this->expense,'payment_status'=>'PAID','payment_method'=>'CASH','financial_account_id'=>$fa,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>800];
        $posting=app(\App\Services\Accounting\ExpensePosting::class);
        $result=$posting->post($data);
        self::assertTrue($result->success,$result->message);
        $count=DB::table('journal_entries')->count();
        self::assertTrue($posting->post($data)->success);
        self::assertSame($count,DB::table('journal_entries')->count());
        self::assertSame(1,DB::table('vouchers')->count());
        self::assertSame(4200.0,(float)DB::table('journal_entry_lines')->where('financial_account_id',$fa)->sum(DB::raw('debit-credit')));
        $jid=DB::table('expenses')->where('id',$id)->value('journal_entry_id');
        self::assertSame(800.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('debit'));
        self::assertSame(800.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('credit'));
    }

    public function test_invoice_posting_uses_mapped_gl_accounts_and_preserves_history_on_retry(): void
    {
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Test branch','is_active'=>1]);
        Schema::create('branch_financial_settings',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('branch_id'),$t->integer('default_cost_center_id')->nullable()]);
        foreach(['customers','suppliers']as$table){Schema::create($table,fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('ledger_account_id')->nullable()]);DB::table($table)->insert(['id'=>1,'company_id'=>1,'ledger_account_id'=>$table==='customers'?$this->asset:$this->liability]);}
        Schema::create('items',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('item_name');foreach(['inventory_account_id','sales_account_id','cogs_account_id','purchase_expense_account_id','sales_return_account_id','purchase_return_account_id']as$c)$t->integer($c)->nullable();});
        DB::table('items')->insert(['id'=>1,'company_id'=>1,'item_name'=>'TEST service','sales_account_id'=>$this->revenue,'purchase_expense_account_id'=>$this->expense]);
        foreach(['sales','purchase']as$kind){
            Schema::create($kind.'_invoices',function(Blueprint $t)use($kind){$t->id();$t->integer('company_id');$t->integer('branch_id');$t->integer($kind==='sales'?'customer_id':'supplier_id');$t->string('invoice_number');$t->date('invoice_date');$t->string('currency_code');$t->decimal('exchange_rate',18,8);$t->decimal('total_amount',18,3);$t->decimal('vat_amount',18,3)->default(0);$t->integer('journal_entry_id')->nullable();$t->timestamps();});
            Schema::create($kind.'_invoice_lines',function(Blueprint $t)use($kind){$t->id();$t->integer('company_id');$t->integer($kind.'_invoice_id');$t->integer('item_id');$t->integer('tax_code_id')->nullable();$t->decimal('total_before_vat',18,3);$t->decimal('base_vat_amount',18,3)->default(0);$t->decimal('vat_amount',18,3)->default(0);$t->boolean('track_inventory_snapshot')->default(false);$t->string('item_type_snapshot')->default('SERVICE');});
            DB::table($kind.'_invoices')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,$kind==='sales'?'customer_id':'supplier_id'=>1,'invoice_number'=>'TEST-'.$kind,'invoice_date'=>'2026-03-05','currency_code'=>'SAR','exchange_rate'=>1,'total_amount'=>100]);
            DB::table($kind.'_invoice_lines')->insert(['company_id'=>1,$kind.'_invoice_id'=>1,'item_id'=>1,'total_before_vat'=>100]);
        }
        Schema::create('stock_movements',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->string('source_type'),$t->integer('source_id'),$t->integer('journal_entry_id')->nullable(),$t->timestamps()]);
        $historical=$this->journal('2026-02-01','POSTED',[[$this->asset,37,0],[$this->equity,0,37]],1,'Historical sentinel');
        $before=DB::table('journal_entry_lines')->where('journal_entry_id',$historical)->get()->toJson();
        $posting=app(\App\Services\Accounting\EnterpriseAccountingPostingService::class);
        $sale=$posting->postSale(1,1,1);$purchase=$posting->postPurchase(1,1,1);
        self::assertSame($sale,$posting->postSale(1,1,1));self::assertSame($purchase,$posting->postPurchase(1,1,1));
        self::assertSame(3,DB::table('journal_entries')->count());
        foreach([$sale,$purchase]as$id){self::assertSame(100.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum('debit'));self::assertSame(100.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum('credit'));}
        self::assertSame(100.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$sale)->where('account_id',$this->revenue)->sum('credit'));
        self::assertSame(100.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$purchase)->where('account_id',$this->expense)->sum('debit'));
        self::assertSame($sale,(int)DB::table('sales_invoices')->value('journal_entry_id'));self::assertSame($purchase,(int)DB::table('purchase_invoices')->value('journal_entry_id'));
        self::assertSame($before,DB::table('journal_entry_lines')->where('journal_entry_id',$historical)->get()->toJson());
        self::assertSame(100.0,$this->reports->ledger(1,1,$this->revenue,['from_date'=>'2026-03-01','to_date'=>'2026-03-31'])['total_credit']);
        Schema::create('voucher_types',fn(Blueprint $t)=>[$t->id(),$t->string('type_code')]);
        Schema::create('vouchers',function(Blueprint $t){$t->id();foreach(['company_id','branch_id','voucher_type_id','reference_id','financial_account_id','cash_account_id','journal_entry_id','created_by']as$c)$t->integer($c)->nullable();foreach(['voucher_number','reference_type','payment_method','currency_code']as$c)$t->string($c);$t->date('voucher_date');foreach(['amount','exchange_rate','foreign_amount']as$c)$t->decimal($c,18,3)->default(0);$t->timestamps();});
        DB::table('voucher_types')->insert([['id'=>1,'type_code'=>'RECEIPT'],['id'=>2,'type_code'=>'PAYMENT']]);
        // Deliberately different defaults expose an invoice/settlement GL mismatch.
        DB::table('accounting_settings')->insert([['company_id'=>1,'setting_key'=>'CUSTOMER_ACCOUNT','account_id'=>$this->equity],['company_id'=>1,'setting_key'=>'SUPPLIER_ACCOUNT','account_id'=>$this->expense]]);
        $cash=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'TEST-CASH','account_name'=>'TEST cash','account_type'=>'ASSET','normal_side'=>'DEBIT']);
        $fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>1,'gl_account_id'=>$cash,'account_code'=>'TEST','account_name'=>'TEST','account_type'=>'CASH','currency_code'=>'SAR']);
        foreach([[1,'CUSTOMER'],[2,'SUPPLIER']]as[$type,$party]){
            foreach([40,60]as$amount){
                $id=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>$type,'reference_type'=>$party,'reference_id'=>1,'financial_account_id'=>$fa,'voucher_number'=>'TEST-'.$party.'-'.$amount,'voucher_date'=>'2026-03-06','payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'amount'=>$amount]);
                $result=app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1]);self::assertTrue($result->success,$result->message);
                $partyBalance=(float)DB::table('journal_entry_lines')->where('company_id',1)->where('party_type',$party)->where('party_id',1)->sum(DB::raw('debit-credit'));
                self::assertSame($amount===40?($party==='CUSTOMER'?60.0:-60.0):0.0,$partyBalance);
                self::assertSame($party==='CUSTOMER'?$this->asset:$this->liability,(int)DB::table('journal_entry_lines')->where('journal_entry_id',$result->journalEntryId)->where('party_type',$party)->value('account_id'));
                self::assertTrue(app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1])->success);
            }
        }
        self::assertSame(7,DB::table('journal_entries')->count());
        self::assertSame($before,DB::table('journal_entry_lines')->where('journal_entry_id',$historical)->get()->toJson());
        foreach(['sales_invoices','purchase_invoices'] as $table){
            Schema::table($table,fn(Blueprint $t)=>$t->string('document_status')->default('POSTED'));
        }
        DB::table('customers')->where('id',1)->update(['ledger_account_id'=>$this->equity]);
        DB::table('suppliers')->where('id',1)->update(['ledger_account_id'=>$this->expense]);
        $original=app(\App\Services\Accounting\HistoricalInvoiceAccountingService::class);
        self::assertSame($this->asset,$original->partyAccount(1,1,'SALE',1));
        self::assertSame($this->liability,$original->partyAccount(1,1,'PURCHASE',1));
        foreach([[2,1],[1,2]] as [$company,$branch]){
            try{$original->partyAccount($company,$branch,'SALE',1);self::fail('Foreign source accepted');}catch(\RuntimeException $e){self::assertStringContainsString('نطاق',$e->getMessage());}
        }
        $sourceLines=DB::table('journal_entry_lines')->where('journal_entry_id',$sale)->get()->toJson();
        DB::table('journal_entries')->where('id',$sale)->update(['source_id'=>999]);
        try{$original->partyAccount(1,1,'SALE',1);self::fail('Mismatched source accepted');}catch(\RuntimeException $e){self::assertStringContainsString('لا يطابق',$e->getMessage());}
        DB::table('journal_entries')->where('id',$sale)->update(['source_id'=>1]);
        DB::table('journal_entries')->where('id',$sale)->update(['status'=>'DRAFT']);
        try{$original->partyAccount(1,1,'SALE',1);self::fail('Draft source accepted');}catch(\RuntimeException $e){self::assertStringContainsString('لا يطابق',$e->getMessage());}
        DB::table('journal_entries')->where('id',$sale)->update(['status'=>'POSTED']);
        self::assertSame($sourceLines,DB::table('journal_entry_lines')->where('journal_entry_id',$sale)->get()->toJson());
        self::assertSame(7,DB::table('journal_entries')->count());
    }

    public function test_customer_receipt_and_supplier_payment_use_selected_operational_account_once(): void
    {
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'TEST','is_active'=>1]);
        Schema::create('branch_financial_settings',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('branch_id'),$t->integer('default_cost_center_id')->nullable()]);
        Schema::create('voucher_types',fn(Blueprint $t)=>[$t->id(),$t->string('type_code')]);
        Schema::create('vouchers',function(Blueprint $t){$t->id();foreach(['company_id','branch_id','voucher_type_id','reference_id','financial_account_id','cash_account_id','journal_entry_id','created_by']as$c)$t->integer($c)->nullable();foreach(['voucher_number','reference_type','payment_method','currency_code']as$c)$t->string($c);$t->date('voucher_date');foreach(['amount','exchange_rate','foreign_amount']as$c)$t->decimal($c,18,3)->default(0);$t->timestamps();});
        DB::table('voucher_types')->insert([['id'=>1,'type_code'=>'RECEIPT'],['id'=>2,'type_code'=>'PAYMENT']]);
        DB::table('accounting_settings')->insert([['company_id'=>1,'setting_key'=>'CUSTOMER_ACCOUNT','account_id'=>$this->asset],['company_id'=>1,'setting_key'=>'SUPPLIER_ACCOUNT','account_id'=>$this->liability]]);
        $cash=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'1111','account_name'=>'TEST cash','account_type'=>'ASSET','normal_side'=>'DEBIT']);
        $fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>1,'gl_account_id'=>$cash,'account_code'=>'TEST','account_name'=>'TEST','account_type'=>'CASH','currency_code'=>'SAR']);
        $posting=app(\App\Services\Accounting\VoucherPosting::class);
        foreach(['customers','suppliers'] as $table){
            Schema::create($table,fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('ledger_account_id')->nullable()]);
            DB::table($table)->insert(['id'=>1,'company_id'=>1,'ledger_account_id'=>$table==='customers'?$this->equity:$this->expense]);
        }
        foreach([[1,'CUSTOMER',100],[2,'SUPPLIER',50]]as[$type,$party,$amount]){
            $id=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>$type,'reference_type'=>$party,'reference_id'=>1,'financial_account_id'=>$fa,'voucher_number'=>'TEST-'.$type,'voucher_date'=>'2026-03-06','payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'amount'=>$amount]);
            $result=$posting->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1]);self::assertTrue($result->success,$result->message);
            $jid=DB::table('vouchers')->where('id',$id)->value('journal_entry_id');
            self::assertSame($party==='CUSTOMER'?$this->equity:$this->expense,(int)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('party_type',$party)->value('account_id'));
            self::assertSame((float)$amount,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('debit'));
            self::assertSame((float)$amount,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('credit'));
            self::assertSame($cash,(int)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->where('financial_account_id',$fa)->value('account_id'));
            self::assertTrue($posting->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1])->success);
        }
        self::assertSame(2,DB::table('journal_entries')->count());
        self::assertSame(50.0,(float)DB::table('journal_entry_lines')->where('financial_account_id',$fa)->sum(DB::raw('debit-credit')));
        self::assertSame(50.0,$this->reports->ledger(1,1,$cash,['from_date'=>'2026-03-01','to_date'=>'2026-03-31'])['closing_balance']);
        foreach([[1,'CUSTOMER','customers',$this->asset],[2,'SUPPLIER','suppliers',$this->liability]] as [$type,$party,$table,$expected]){
            DB::table($table)->insert(['id'=>2,'company_id'=>1,'ledger_account_id'=>null]);
            $id=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>$type,'reference_type'=>$party,'reference_id'=>2,'financial_account_id'=>$fa,'voucher_number'=>'TEST-GENERAL-'.$type,'voucher_date'=>'2026-03-06','payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'amount'=>25]);
            $result=$posting->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1]);self::assertTrue($result->success,$result->message);
            self::assertSame($expected,(int)DB::table('journal_entry_lines')->where('journal_entry_id',$result->journalEntryId)->where('party_type',$party)->value('account_id'));
            self::assertSame(25.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$result->journalEntryId)->sum('debit'));
            self::assertSame(25.0,(float)DB::table('journal_entry_lines')->where('journal_entry_id',$result->journalEntryId)->sum('credit'));
        }
    }

    public function test_stock_returns_use_original_lot_sources_mapped_gl_and_are_retry_safe(bool $changedMapping = false): void
    {
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'TEST','is_active'=>1]);
        Schema::create('branch_financial_settings',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('branch_id'),$t->integer('default_cost_center_id')->nullable()]);
        foreach(['customers','suppliers']as$table){Schema::create($table,fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('ledger_account_id')->nullable()]);DB::table($table)->insert(['id'=>1,'company_id'=>1,'ledger_account_id'=>$table==='customers'?$this->asset:$this->liability]);}
        Schema::create('items',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('item_code');$t->string('item_name');foreach(['inventory_account_id','cogs_account_id','sales_return_account_id','purchase_return_account_id']as$c)$t->integer($c);});
        DB::table('items')->insert(['id'=>1,'company_id'=>1,'item_code'=>'TEST','item_name'=>'TEST stock','inventory_account_id'=>$this->asset,'cogs_account_id'=>$this->cogs,'sales_return_account_id'=>$this->revenue,'purchase_return_account_id'=>$this->expense]);
        $tables=[
            'commercial_returns'=>['company_id branch_id party_id source_invoice_id posted_by journal_entry_id','return_type return_number document_status return_date currency_code','exchange_rate base_total_amount'],
            'commercial_return_lines'=>['company_id return_id item_id source_invoice_line_id track_inventory_snapshot tax_code_id','item_type_snapshot','base_total_before_vat base_vat_amount qty_kg inventory_cost'],
            'commercial_return_lot_sources'=>['company_id return_line_id inventory_lot_id','','qty_kg unit_cost_per_kg total_cost'],
            'inventory_lots'=>['company_id branch_id item_id purchase_invoice_line_id','received_at lot_status','qty_remaining_kg qty_sold_kg unit_cost_per_kg'],
            'sales_line_lot_sources'=>['company_id sales_invoice_line_id inventory_lot_id','','qty_kg unit_cost_per_kg total_cost'],
            'inventory_lot_movements'=>['company_id branch_id inventory_lot_id item_id source_id created_by','movement_type source_type movement_at notes','qty_kg unit_cost_per_kg total_cost'],
            'stock_movements'=>['company_id branch_id item_id inventory_lot_id source_id created_by','movement_type source_type movement_date notes','qty qty_kg unit_cost unit_cost_per_kg total_cost'],
        ];
        foreach($tables as$table=>[$ids,$strings,$decimals])Schema::create($table,function(Blueprint $t)use($ids,$strings,$decimals){$t->id();foreach(array_filter(explode(' ',$ids))as$c)$t->integer($c)->nullable();foreach(array_filter(explode(' ',$strings))as$c)$t->string($c)->nullable();foreach(array_filter(explode(' ',$decimals))as$c)$t->decimal($c,18,6)->default(0);$t->timestamp('posted_at')->nullable();$t->timestamps();});
        DB::table('inventory_lots')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'item_id'=>1,'purchase_invoice_line_id'=>1,'received_at'=>'2026-03-01','lot_status'=>'OPEN','qty_remaining_kg'=>20,'qty_sold_kg'=>10,'unit_cost_per_kg'=>5]);
        DB::table('sales_line_lot_sources')->insert(['company_id'=>1,'sales_invoice_line_id'=>1,'inventory_lot_id'=>1,'qty_kg'=>10,'unit_cost_per_kg'=>5,'total_cost'=>50]);
        if($changedMapping){
            DB::table('items')->where('id',1)->update(['inventory_account_id'=>$this->equity,'cogs_account_id'=>$this->liability]);
        }
        $service=app(\App\Services\CommercialReturnService::class);
        foreach(['SALES_RETURN','PURCHASE_RETURN']as$index=>$type){
            $id=DB::table('commercial_returns')->insertGetId(['company_id'=>1,'branch_id'=>1,'party_id'=>1,'source_invoice_id'=>1,'return_type'=>$type,'return_number'=>'TEST-'.$index,'document_status'=>'DRAFT','return_date'=>'2026-03-07','currency_code'=>'SAR','exchange_rate'=>1,'base_total_amount'=>100]);
            DB::table('commercial_return_lines')->insert(['company_id'=>1,'return_id'=>$id,'item_id'=>1,'source_invoice_line_id'=>1,'track_inventory_snapshot'=>1,'item_type_snapshot'=>'STOCK','base_total_before_vat'=>100,'qty_kg'=>10]);
            $result=$service->post(1,$id,1,1);$jid=$result['return']->journal_entry_id;
            self::assertSame('POSTED',$result['return']->document_status);
            self::assertSame((float)($index===0?150:100),(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('debit'));
            self::assertSame((float)($index===0?150:100),(float)DB::table('journal_entry_lines')->where('journal_entry_id',$jid)->sum('credit'));
            self::assertSame($jid,$service->post(1,$id,1,1)['return']->journal_entry_id);
            self::assertSame($index===0?30.0:20.0,(float)DB::table('inventory_lots')->value('qty_remaining_kg'));
        }
        self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(2,DB::table('stock_movements')->count());self::assertSame(2,DB::table('inventory_lot_movements')->count());
        self::assertSame([1,1],DB::table('commercial_return_lot_sources')->orderBy('id')->pluck('inventory_lot_id')->all());
        self::assertSame(50.0,(float)DB::table('journal_entry_lines')->where('account_id',$this->expense)->sum('credit'));
        if($changedMapping){
            // Characterization: current return posting follows mutable mappings, not source GL.
            // Do not treat this as approval of the accounting policy.
            self::assertSame(0,DB::table('journal_entry_lines')->where('account_id',$this->cogs)->count());
            self::assertSame(50.0,(float)DB::table('journal_entry_lines')->where('account_id',$this->liability)->sum('credit'));
            self::assertSame(2,DB::table('journal_entry_lines')->where('account_id',$this->equity)->count());
        }
    }

    public function test_characterizes_return_inventory_gl_drift_after_mapping_change(): void
    {
        $this->test_stock_returns_use_original_lot_sources_mapped_gl_and_are_retry_safe(true);
    }

    private function journal(string $date,string $status,array $lines,?int $branch,string $description,string $source='MANUAL',?int $sourceId=null,bool $closing=false,int $company=1): int
    {
        $id=DB::table('journal_entries')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'financial_year_id'=>1,'entry_number'=>'J-'.uniqid(),'entry_date'=>$date,'source_type'=>$source,'source_id'=>$sourceId,'description'=>$description,'status'=>$status,'is_closing_entry'=>$closing?1:0,'is_system_generated'=>0]);
        foreach($lines as[$account,$debit,$credit])DB::table('journal_entry_lines')->insert(['journal_entry_id'=>$id,'company_id'=>$company,'branch_id'=>$branch,'financial_year_id'=>1,'account_id'=>$account,'debit'=>$debit,'credit'=>$credit,'description'=>$description]);return$id;
    }
}
