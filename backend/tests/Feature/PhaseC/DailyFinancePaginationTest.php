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
        foreach (['journal_entry_lines','journal_entries','expenses','vouchers','expense_types','voucher_types','financial_accounts','accounts','branches','users','shipments','cars','drivers','workers','purchase_invoices','sales_invoices','sulb_document_sequences','companies'] as $table) Schema::dropIfExists($table);
        Schema::create('companies',fn(Blueprint$t)=>[$t->id(),$t->string('company_name')]);
        DB::table('companies')->insert([['id'=>1,'company_name'=>'TEST A'],['id'=>2,'company_name'=>'TEST B']]);
        Schema::create('sulb_document_sequences',function(Blueprint$t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->default(0);$t->string('document_type');$t->integer('document_year');$t->unsignedBigInteger('next_number');$t->timestamps();$t->unique(['company_id','branch_id','document_type','document_year']);});
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

    public function test_expense_account_options_are_complete_scoped_arrays_without_financial_writes(): void
    {
        Schema::table('expense_types', function (Blueprint $t) { $t->unsignedBigInteger('account_id')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::table('cars', function (Blueprint $t) { $t->string('plate_number')->nullable(); $t->boolean('is_active')->default(true); });
        foreach (['drivers', 'workers'] as $table) Schema::table($table, fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        Schema::create('company_settings', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->string('base_currency_code'), $t->string('currency_code')]);
        Schema::create('currencies', fn (Blueprint $t) => [$t->string('currency_code'), $t->string('currency_name')]);
        Schema::create('company_currencies', fn (Blueprint $t) => [$t->unsignedBigInteger('company_id'), $t->string('currency_code'), $t->boolean('is_active'), $t->boolean('is_base')]);
        DB::table('company_settings')->insert(['company_id'=>1, 'base_currency_code'=>'SAR', 'currency_code'=>'SAR']);
        $gl=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'1110','account_name'=>'Cash','account_type'=>'ASSET']);
        $otherBranch=DB::table('branches')->insertGetId(['company_id'=>1, 'branch_name'=>'Other', 'is_active'=>1]);
        for ($i=1; $i<=31; $i++) DB::table('financial_accounts')->insert(['company_id'=>1,'branch_id'=>$this->branchA,'gl_account_id'=>$gl,'account_code'=>'A'.$i,'account_name'=>'Account '.$i,'account_type'=>$i===31?'PETTY_CASH':'CASH','currency_code'=>'SAR','is_active'=>1]);
        foreach ([['company_id'=>1,'branch_id'=>null,'account_type'=>'OTHER'], ['company_id'=>1,'branch_id'=>$otherBranch,'account_type'=>'CASH'], ['company_id'=>2,'branch_id'=>$this->branchB,'account_type'=>'CASH']] as $i=>$values) DB::table('financial_accounts')->insert($values+['gl_account_id'=>$gl,'account_code'=>'S'.$i,'account_name'=>'Special '.$i,'currency_code'=>'SAR','is_active'=>1]);
        $service=app(FinancialAccountService::class);
        $payload=app(ExpenseController::class)->meta($this->request([], $this->branchA), $this->context, $service)->getData(true);
        $options=$payload['data']['financial_accounts'];
        self::assertTrue(array_is_list($options));
        self::assertCount(32, $options);
        self::assertContains('PETTY_CASH', array_column($options, 'account_type'));
        self::assertContains('OTHER', array_column($options, 'account_type'));
        foreach ($options as $account) {
            self::assertSame(1, $account['company_id']);
            self::assertTrue($account['branch_id']===null || $account['branch_id']===$this->branchA);
        }
        self::assertCount(25, $service->list(1, $this->branchA)->items());
        self::assertSame(32, $service->list(1, $this->branchA)->total());
        foreach (['expenses', 'vouchers', 'journal_entries', 'journal_entry_lines'] as $table) self::assertSame(0, DB::table($table)->count());
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

    public function test_operational_search_paginates_thousands_and_preserves_identity_and_scope(): void
    {
        $gl=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'111','account_name'=>'Custody','account_type'=>'ASSET']);
        $rows=[];
        for($i=0;$i<1100;$i++) $rows[]=['company_id'=>1,'branch_id'=>$this->branchA,'gl_account_id'=>$gl,'account_code'=>'P'.$i,'account_name'=>$i===1099?'عهدة عبدالسلام':'Account '.$i,'account_type'=>'PETTY_CASH','currency_code'=>'SAR','is_active'=>1];
        foreach(array_chunk($rows,100) as $chunk) DB::table('financial_accounts')->insert($chunk);
        $service=app(FinancialAccountService::class);
        self::assertSame(1100,$service->selectionPage(1,$this->branchA)->total());
        self::assertCount(25,$service->selectionPage(1,$this->branchA)->items());
        $hit=$service->selectionPage(1,$this->branchA,['search'=>'عبدالسلام'])->items();
        self::assertCount(1,$hit);
        self::assertSame($gl,(int)$hit[0]->gl_account_id);
        self::assertSame((int)$hit[0]->id,(int)$service->selectionPage(1,$this->branchA,['selected_id'=>$hit[0]->id])->items()[0]->id);
        self::assertSame(0,$service->selectionPage(2,null,['selected_id'=>$hit[0]->id])->total());
        self::assertSame(0,$service->selectionPage(1,$this->branchB)->total());
        DB::table('accounts')->where('id',$gl)->update(['is_active'=>0]);
        self::assertSame(0,$service->selectionPage(1,$this->branchA)->total());
    }

    public function test_gl_selection_searches_deep_paths_and_separates_browse_from_posting(): void
    {
        Schema::table('accounts',fn(Blueprint $t)=>$t->unsignedBigInteger('parent_id')->nullable());
        $parent=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'ROOT','account_name'=>'العهد','account_type'=>'ASSET','is_group'=>1,'allow_posting'=>0]);
        $deep=$parent;
        for($i=0;$i<15;$i++) $deep=DB::table('accounts')->insertGetId(['company_id'=>1,'parent_id'=>$deep,'account_code'=>'G'.$i,'account_name'=>'Group','account_type'=>'ASSET','is_group'=>1,'allow_posting'=>0]);
        $id=DB::table('accounts')->insertGetId(['company_id'=>1,'parent_id'=>$deep,'account_code'=>'LEAF','account_name'=>'عبدالسلام','account_type'=>'ASSET']);
        $repo=app(\App\Domain\Accounting\Repositories\AccountRepository::class);
        $page=$repo->selectionPage(1,['search'=>'العهد']);
        self::assertSame(1,$page->total());
        self::assertSame($id,(int)$page->items()[0]->id);
        self::assertStringContainsString('العهد',$page->items()[0]->account_path);
        DB::table('accounts')->where('id',$id)->update(['is_active'=>0]);
        self::assertSame(0,$repo->selectionPage(1)->total());
        self::assertSame(17,$repo->selectionPage(1,['mode'=>'BROWSE'])->total());
        self::assertSame(0,$repo->selectionPage(2,['selected_id'=>$id,'mode'=>'BROWSE'])->total());
    }

    public function test_expense_creation_uses_selected_petty_cash_and_posted_edit_remains_rejected(): void
    {
        Schema::table('expense_types', function (Blueprint $t) { $t->unsignedBigInteger('account_id')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::table('expenses', function (Blueprint $t) {
            $t->string('scope_type')->nullable(); $t->string('reference_type')->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('payment_method')->nullable(); $t->string('currency_code')->nullable(); $t->decimal('exchange_rate',18,8)->nullable();
            $t->decimal('foreign_amount',18,3)->nullable(); $t->string('expense_effect')->nullable(); $t->timestamps();
        });
        DB::table('expense_types')->where('id',1)->update(['account_id'=>10]);
        $gl=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'1111','account_name'=>'Petty cash','account_type'=>'ASSET']);
        $fa=DB::table('financial_accounts')->insertGetId(['company_id'=>1,'branch_id'=>$this->branchA,'gl_account_id'=>$gl,'account_code'=>'P1','account_name'=>'عهد','account_type'=>'PETTY_CASH','currency_code'=>'SAR','is_active'=>1]);
        $request=Request::create('/api/expenses','POST',['branch_id'=>$this->branchA,'expense_type_id'=>1,'expense_date'=>'2026-08-01','scope_type'=>'GENERAL','amount'=>10,'payment_status'=>'PAID','payment_method'=>'CASH','financial_account_id'=>$fa,'currency_code'=>'SAR','exchange_rate'=>1]);
        $context=Mockery::mock(AccountingContext::class);
        $context->shouldReceive('companyId')->andReturn(1); $context->shouldReceive('branchForOperation')->andReturn($this->branchA); $context->shouldReceive('userId')->andReturn(1);
        $engine=Mockery::mock(AccountingEngine::class);
        $engine->shouldReceive('expense')->once()->with(Mockery::on(fn(array $values) => $values['company_id']===1 && $values['branch_id']===$this->branchA && $values['financial_account_id']===$fa && $values['expense_account_id']===10 && $values['amount']===10))->andReturn(PostingResult::success('ok',77));
        $controller=app(ExpenseController::class);
        $response=$controller->store($request, $engine, $context, app(FinancialAccountService::class));
        self::assertSame(201,$response->getStatusCode());
        self::assertSame($fa,DB::table('expenses')->value('financial_account_id'));
        $id=(int)$response->getData(true)['id'];
        DB::table('expenses')->where('id',$id)->update(['journal_entry_id'=>77]);
        $before=(array)DB::table('expenses')->where('id',$id)->first();
        $edit=$controller->update($this->request(['amount'=>999]),$id,$this->context);
        self::assertSame(422,$edit->getStatusCode());
        self::assertSame($before,(array)DB::table('expenses')->where('id',$id)->first());
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

    public function test_gl_options_enforce_asset_eligibility_and_restore_selected_identity(): void
    {
        Schema::table('accounts',fn(Blueprint $t)=>$t->unsignedBigInteger('parent_id')->nullable());
        $asset=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'A','account_name'=>'Custody','account_type'=>'ASSET']);
        DB::table('accounts')->insert(['company_id'=>1,'account_code'=>'R','account_name'=>'Revenue','account_type'=>'REVENUE']);
        $controller=app(\App\Http\Controllers\Api\AccountController::class);
        $repo=app(\App\Domain\Accounting\Repositories\AccountRepository::class);
        $data=$controller->options($this->request(['account_type'=>'ASSET','selected_id'=>$asset]),$repo)->getData(true)['data'];
        self::assertSame(1,$data['total']);
        self::assertSame($asset,$data['data'][0]['id']);
        self::assertSame('A — Custody',$data['data'][0]['account_path']);
        self::assertSame(0,$repo->selectionPage(2,['selected_id'=>$asset])->total());
        DB::table('accounts')->where('id',$asset)->update(['is_group'=>1]);
        self::assertSame(0,$repo->selectionPage(1,['account_type'=>'ASSET'])->total());
    }

    public function test_gl_large_chart_database_pagination_benchmark(): void
    {
        Schema::table('accounts',fn(Blueprint $t)=>$t->unsignedBigInteger('parent_id')->nullable());
        $root=DB::table('accounts')->insertGetId(['company_id'=>1,'account_code'=>'ROOT','account_name'=>'العهد','account_type'=>'ASSET','is_group'=>1,'allow_posting'=>0]);
        $rows=[];
        for($i=0;$i<5000;$i++)$rows[]=['company_id'=>1,'parent_id'=>$root,'account_code'=>sprintf('A%05d',$i),'account_name'=>'عهدة '.$i,'account_type'=>'ASSET','is_active'=>1,'is_group'=>0,'allow_posting'=>1];
        foreach(array_chunk($rows,100)as$chunk)DB::table('accounts')->insert($chunk);
        $repo=app(\App\Domain\Accounting\Repositories\AccountRepository::class);
        foreach(['','A04999','العهد']as$search){
            $start=hrtime(true);
            $page=$repo->selectionPage(1,['search'=>$search,'per_page'=>25]);
            $milliseconds=(hrtime(true)-$start)/1e6;
            $bytes=strlen(json_encode($page->toArray(),JSON_UNESCAPED_UNICODE));
            self::assertLessThanOrEqual(25,count($page->items()));
            self::assertSame($search==='A04999'?1:5000,$page->total());
            fwrite(STDERR,sprintf("GL_BENCH search=%s rows=5000 response_bytes=%d milliseconds=%.3f\n",$search?:'PAGE',$bytes,$milliseconds));
        }
    }
}
