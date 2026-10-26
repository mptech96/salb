<?php

namespace Tests\Feature\Shipments;

use App\Services\ShipmentCostService;
use App\Services\ShipmentCostDistributionService;
use App\Services\ShipmentCostPayee;
use App\Services\ShipmentCostVoucherGuard;
use App\Services\ShipmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

/** Self-contained fixtures; no migration history, financial writes only in :memory:. */
class ShipmentStageOneSafetyTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName()==='mysql') {
            self::assertMatchesRegularExpression('/^sulb_ship_test_[a-f0-9]{16}$/D',DB::connection()->getConfig('database'));
            self::assertSame('3339',(string)DB::connection()->getConfig('port'));
            self::assertNotFalse(getenv('SULB_SHIPMENT_TEST_DIR'));
        } else {
            self::assertSame('sqlite', DB::connection()->getDriverName());
            self::assertSame(':memory:', DB::connection()->getConfig('database'));
        }
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
        foreach(['companies','shipments','shipment_costs','expense_types','drivers','workers','cars','company_settings','vouchers','voucher_types','sulb_document_sequences'] as $table) Schema::dropIfExists($table);
        $this->table('companies', [], ['company_name']);
        $this->table('drivers', ['company_id','branch_id'], ['driver_name'], ['is_active']);
        $this->table('workers', ['company_id','branch_id'], ['worker_name'], ['is_active']);
        foreach(['suppliers','customers','supplier_branches','customer_branches']as$table)Schema::dropIfExists($table);
        foreach(['suppliers','customers']as$table)$this->table($table,['company_id','branch_id'],[],['is_active','scope_all_branches']);
        $this->table('supplier_branches',['company_id','supplier_id','branch_id'],[],['is_active']);
        $this->table('customer_branches',['company_id','customer_id','branch_id'],[],['is_active']);
        $this->table('cars', ['company_id','branch_id','driver_id'], ['plate_number'], ['is_active']);
        $this->table('company_settings', ['company_id'], ['base_currency_code','currency_code']);
        $this->table('shipments', ['company_id','branch_id','supplier_id','customer_id','driver_id','car_id','created_by'], ['shipment_number','shipment_date','shipment_type','plate_number','flow_type','currency_code','cost_allocation_method','commercial_status','status','notes'], ['exchange_rate']);
        Schema::dropIfExists('shipment_items');
        $this->table('shipment_items',['company_id','shipment_id','item_id']);
        $this->table('shipment_costs', ['company_id','branch_id','shipment_id','expense_type_id','financial_account_id','payee_id','created_by','posted_by','journal_entry_id','voucher_id'], ['expense_date','currency_code','payment_status','payment_method','payee_type','beneficiary_name','cost_code','cost_status','posted_at','notes'], ['amount','foreign_amount','exchange_rate','capitalizable','distributed']);
        $this->table('expense_types', ['company_id','account_id'], ['type_name','type_code','usage_type'], ['is_active','affects_cost']);
        $this->table('vouchers', ['company_id','branch_id','voucher_type_id','reference_id','journal_entry_id','financial_account_id','cash_account_id','created_by'], ['voucher_number','voucher_date','reference_type','payment_method','currency_code','notes'], ['amount','foreign_amount','exchange_rate']);
        $this->table('voucher_types', [], ['type_code']);
        Schema::create('sulb_document_sequences',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id');$t->string('document_type');$t->integer('document_year');$t->integer('next_number');$t->timestamps();$t->unique(['company_id','branch_id','document_type','document_year'],'uq_shipment_test_sequence');});
        DB::table('companies')->insert(['id'=>1,'company_name'=>'Shipment test only']);
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Branch','is_active'=>1]);
        DB::table('company_settings')->insert(['company_id'=>1,'base_currency_code'=>'SAR','currency_code'=>'SAR']);
        DB::table('shipments')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'shipment_number'=>'TEST','commercial_status'=>'DRAFT','shipment_type'=>'PURCHASE','supplier_id'=>99]);
        foreach([[1,1,1,1],[2,1,2,1],[3,1,null,1],[4,2,1,1],[5,1,1,0]] as [$id,$company,$branch,$active]) DB::table('drivers')->insert(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch,'driver_name'=>'Synthetic','is_active'=>$active]);
        DB::table('cars')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'driver_id'=>2,'plate_number'=>'TEST','is_active'=>1]);
        DB::table('expense_types')->insert(['id'=>1,'company_id'=>null,'type_name'=>'Shipment','type_code'=>'TEST','account_id'=>8,'usage_type'=>'SHIPMENT','is_active'=>1,'affects_cost'=>0]);
        DB::table('financial_accounts')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'gl_account_id'=>1,'account_code'=>'CASH','account_name'=>'Cash','account_type'=>'CASH','currency_code'=>'SAR','is_active'=>1]);
        DB::table('voucher_types')->insert(['id'=>2,'type_code'=>'PAYMENT']);
        DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>2]);
        Schema::dropIfExists('branch_financial_settings');
        $this->table('branch_financial_settings',['company_id','branch_id','default_cost_center_id']);
        $distribution=Mockery::mock(ShipmentCostDistributionService::class);
        $distribution->shouldReceive('distributeByShipmentId')->andReturn([]);
        $this->app->instance(ShipmentCostDistributionService::class,$distribution);
    }

    private function table(string $name,array $ids=[],array $strings=[],array $numbers=[]): void
    {
        Schema::create($name,function(Blueprint $t)use($ids,$strings,$numbers){$t->id();foreach($ids as $c)$t->integer($c)->nullable();foreach($strings as $c)$t->string($c)->nullable();foreach($numbers as $c)$t->decimal($c,18,3)->nullable();$t->timestamps();});
    }

    private function data(array $changes=[]): array
    {
        return array_replace(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>100,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>false,'payee_type'=>'DRIVER','payee_id'=>1,'created_by'=>1],$changes);
    }

    private function rejects(callable $call): void
    {
        try{$call();self::fail('Expected rejection');}catch(\RuntimeException|\Illuminate\Validation\ValidationException $e){self::assertNotEmpty($e->getMessage());}
    }

    public function test_driver_branch_company_and_car_default_validation(): void
    {
        $parameters=(new \ReflectionClass(ShipmentService::class))->getConstructor()->getParameters();
        $service=Mockery::mock(ShipmentService::class,array_map(fn($p)=>app($p->getType()->getName()),$parameters))->makePartial();
        $service->shouldReceive('recalculateShipment')->andReturnNull();
        $base=['shipment_type'=>'PURCHASE','shipment_date'=>'2026-03-02','currency_code'=>'SAR','exchange_rate'=>1];
        foreach([2,4,5,999] as $driver)$this->rejects(fn()=>$service->save([...$base,'driver_id'=>$driver],1,1,1));
        $this->rejects(fn()=>$service->save([...$base,'car_id'=>1],1,1,1));
        foreach([1,3] as $driver){$id=$service->save([...$base,'driver_id'=>$driver],1,1,1);self::assertSame($driver,(int)DB::table('shipments')->where('id',$id)->value('driver_id'));}
        DB::table('cars')->where('id',1)->update(['driver_id'=>3]);
        $id=$service->save([...$base,'car_id'=>1],1,1,1);self::assertSame(3,(int)DB::table('shipments')->where('id',$id)->value('driver_id'));
    }

    public function test_payee_scope_aliases_and_usage(): void
    {
        $service=app(ShipmentCostService::class);
        foreach([2,4,5,999] as $id)$this->rejects(fn()=>$service->storeDraft($this->data(['payee_id'=>$id])));
        $id=$service->storeDraft($this->data(['payee_id'=>3]));
        self::assertSame(3,(int)DB::table('shipment_costs')->where('id',$id)->value('payee_id'));
        self::assertSame(99,(int)DB::table('shipments')->value('supplier_id'));
        $legacy=$this->data();unset($legacy['payee_type'],$legacy['payee_id']);
        $legacy['beneficiary_type']='DRIVER';$legacy['beneficiary_id']=1;
        self::assertGreaterThan(0,$service->storeDraft($legacy));
        $this->rejects(fn()=>ShipmentCostPayee::normalize(['payee_type'=>'DRIVER','beneficiary_type'=>'SUPPLIER']));
        $this->rejects(fn()=>ShipmentCostPayee::normalize(['payee_id'=>1,'beneficiary_id'=>2]));
        DB::table('expense_types')->where('id',1)->update(['usage_type'=>'INVOICE']);
        $this->rejects(fn()=>$service->storeDraft($this->data()));
        self::assertSame(0,DB::table('journal_entries')->count());
        self::assertSame(0,DB::table('vouchers')->count());
    }

    public function test_supplier_is_explicit_independent_of_goods_supplier_and_branch_scoped(): void
    {
        DB::table('suppliers')->insert([['id'=>10,'company_id'=>1,'is_active'=>1,'scope_all_branches'=>0],['id'=>20,'company_id'=>2,'is_active'=>1,'scope_all_branches'=>1]]);
        DB::table('supplier_branches')->insert(['company_id'=>1,'supplier_id'=>10,'branch_id'=>1,'is_active'=>1]);
        $service=app(ShipmentCostService::class);
        $id=$service->storeDraft($this->data(['payee_type'=>'SUPPLIER','payee_id'=>10]));
        self::assertSame(10,(int)DB::table('shipment_costs')->where('id',$id)->value('payee_id'));
        $this->rejects(fn()=>$service->storeDraft($this->data(['payee_type'=>'SUPPLIER','payee_id'=>20])));
        DB::table('supplier_branches')->update(['branch_id'=>2]);
        $this->rejects(fn()=>$service->storeDraft($this->data(['payee_type'=>'SUPPLIER','payee_id'=>10])));
        $service->updateDraft(1,$id,['payee_type'=>null,'payee_id'=>null],1);
        self::assertNull(DB::table('shipment_costs')->where('id',$id)->value('payee_id'));
        self::assertNull(DB::table('shipment_costs')->where('id',$id)->value('payee_type'));
        $this->rejects(fn()=>$service->deleteDraft(1,$id,2));
        self::assertSame(1,DB::table('shipment_costs')->count());
    }

    public function test_paid_cost_one_balanced_journal_voucher_link_retry_and_legacy_guard(): void
    {
        $service=app(ShipmentCostService::class);$id=$service->storeDraft($this->data());
        $service->postPendingForShipment(1,1,1);$service->postPendingForShipment(1,1,1);
        self::assertSame(1,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
        $cost=DB::table('shipment_costs')->where('id',$id)->first();$voucher=DB::table('vouchers')->first();
        self::assertSame((int)$cost->journal_entry_id,(int)$voucher->journal_entry_id);
        self::assertStringStartsWith('PAY-2026-', $voucher->voucher_number);
        self::assertSame(100.0,(float)DB::table('journal_entry_lines')->sum('debit'));
        self::assertSame(100.0,(float)DB::table('journal_entry_lines')->sum('credit'));
        self::assertSame(0,DB::table('journal_entry_lines')->where('party_type','DRIVER')->where('debit','>',0)->count());
        self::assertSame(1,(int)DB::table('journal_entry_lines')->where('credit','>',0)->value('account_id'));
        $this->rejects(fn()=>$service->updateDraft(1,$id,$this->data(),1));
        $this->rejects(fn()=>$service->deleteDraft(1,$id,1));
        DB::table('vouchers')->where('id',$voucher->id)->update(['journal_entry_id'=>null]);
        $voucher->journal_entry_id=null;
        $this->rejects(fn()=>DB::transaction(fn()=>ShipmentCostVoucherGuard::assertDeletable($voucher)));
        $context=Mockery::mock(\App\Services\Accounting\AccountingContext::class);
        $context->shouldReceive('companyId')->andReturn(1);$context->shouldReceive('branchFilter')->andReturn(1);
        $response=app(\App\Http\Controllers\Api\VoucherController::class)->destroy(\Illuminate\Http\Request::create('/api/vouchers/'.$voucher->id,'DELETE'),$voucher->id,$context);
        self::assertSame(422,$response->getStatusCode());
        self::assertSame(1,DB::table('vouchers')->count());
    }

    public function test_fault_after_voucher_rolls_back_journal_voucher_number_and_cost_state(): void
    {
        $distribution=Mockery::mock(ShipmentCostDistributionService::class);
        $distribution->shouldReceive('distributeByShipmentId')->andThrow(new \RuntimeException('Injected distribution failure'));
        $this->app->instance(ShipmentCostDistributionService::class,$distribution);
        $service=app(ShipmentCostService::class);$id=$service->storeDraft($this->data());
        $this->rejects(fn()=>$service->postPendingForShipment(1,1,1));
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('journal_entry_lines')->count());
        self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('sulb_document_sequences')->count());
        self::assertSame('DRAFT',DB::table('shipment_costs')->where('id',$id)->value('cost_status'));
        $service->deleteDraft(1,$id,1);self::assertSame(0,DB::table('shipment_costs')->count());
    }
}
