<?php

namespace Tests\Feature\PhaseE;

use App\Services\OpeningBalanceService;
use App\Services\InventoryLotService;
use App\Services\FixedAssets\FixedAssetDepreciationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class P1OpeningReconciliationTest extends TestCase
{
    // Isolated service-contract schema, never the historical migration chain or local MySQL.
    private function table(string$name,string$ids='',string$text='',string$money=''):void
    {
        Schema::create($name,function(Blueprint$t)use($ids,$text,$money){$t->id();foreach(array_filter(explode(' ',$ids))as$c)$t->integer($c)->nullable();foreach(array_filter(explode(' ',$text))as$c)$t->string($c)->nullable();foreach(array_filter(explode(' ',$money))as$c)$t->decimal($c,24,10)->nullable();$t->timestamps();});
    }
    protected function setUp():void
    {
        parent::setUp();self::assertSame('sqlite',DB::connection()->getDriverName());self::assertSame(':memory:',DB::connection()->getDatabaseName());
        $this->table('branches','company_id is_active','branch_name');
        $this->table('financial_years','company_id is_closed','year_name start_date end_date');
        $this->table('company_settings','company_id','base_currency_code currency_code');
        $this->table('currencies','is_active','currency_code');$this->table('company_currencies','company_id is_active is_base','currency_code');
        $this->table('accounts','company_id is_active is_group allow_posting allow_cost_center','account_code account_name account_type');
        $this->table('accounting_settings','company_id account_id','setting_key');
        $this->table('branch_financial_settings','company_id branch_id default_cost_center_id');$this->table('cost_centers','company_id branch_id is_active','cost_center_code cost_center_name');
        $this->table('financial_accounts','company_id branch_id gl_account_id is_active','currency_code');
        foreach(['customers','suppliers']as$name)$this->table($name,'company_id is_active scope_all_branches','name','opening_balance');
        $this->table('items','company_id is_active','item_code item_name item_grade');
        $this->table('fixed_asset_categories','company_id is_active useful_life_months asset_account_id accumulated_depreciation_account_id depreciation_expense_account_id','category_name depreciation_method','annual_depreciation_rate');
        $this->table('opening_balance_batches','company_id financial_year_id journal_entry_id created_by posted_by','opening_date batch_number status notes posted_at','total_debit total_credit');
        $this->table('opening_balance_lines','company_id batch_id branch_id account_id financial_account_id cost_center_id party_id','party_type currency_code description','debit credit foreign_debit foreign_credit exchange_rate');
        $this->table('opening_inventory_lines','company_id batch_id branch_id item_id inventory_lot_id','lot_number notes','qty_kg total_cost unit_cost_per_kg');
        $this->table('opening_fixed_asset_lines','company_id batch_id branch_id category_id useful_life_months asset_account_id accumulated_account_id expense_account_id fixed_asset_id','asset_code asset_name acquisition_date depreciation_start_date depreciation_method notes','historical_cost opening_accumulated_depreciation salvage_value annual_depreciation_rate');
        $this->table('journal_entries','company_id branch_id financial_year_id cost_center_id source_id reversal_of_id is_closing_entry is_system_generated created_by','entry_number reference_no entry_date source_type description status currency_code','exchange_rate');
        $this->table('journal_entry_lines','journal_entry_id company_id branch_id financial_year_id cost_center_id account_id financial_account_id counterparty_branch_id party_id','party_type currency_code description','foreign_debit foreign_credit exchange_rate debit credit');
        $this->table('inventory_lots','parent_lot_id origin_lot_id company_id branch_id item_id car_id shipment_id shipment_item_id purchase_invoice_id purchase_invoice_line_id source_id inventory_operation_id created_by opening_balance_batch_id','lot_number source_type received_at lot_status notes','qty_received_kg qty_remaining_kg qty_sold_kg base_cost allocated_cost total_cost unit_cost_per_kg');
        $this->table('inventory_lot_movements','company_id branch_id inventory_lot_id item_id source_id created_by','movement_type source_type movement_at notes','qty_kg unit_cost_per_kg total_cost');
        $this->table('stock_movements','company_id branch_id item_id inventory_lot_id source_id journal_entry_id created_by','movement_type source_type movement_date notes','qty qty_kg unit_cost unit_cost_per_kg total_cost');
        $this->table('fixed_assets','company_id branch_id category_id useful_life_months asset_account_id accumulated_account_id expense_account_id journal_entry_id is_active opening_balance_batch_id created_by updated_by responsible_worker_id','asset_code asset_name description purchase_date depreciation_method depreciation_start_date last_depreciation_date asset_status acquisition_type','purchase_cost salvage_value current_book_value annual_depreciation_rate accumulated_depreciation opening_accumulated_depreciation');
        $this->table('fixed_asset_depreciation','company_id branch_id asset_id journal_entry_id created_by','depreciation_month status','opening_book_value depreciation_amount accumulated_depreciation closing_book_value');
        $this->table('fixed_asset_movements','company_id branch_id asset_id from_branch_id to_branch_id worker_id journal_entry_id created_by','movement_type movement_date reference_no notes','amount');
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'is_active'=>1,'branch_name'=>'UAT']);DB::table('financial_years')->insert(['id'=>1,'company_id'=>1,'is_closed'=>0,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
        DB::table('company_settings')->insert(['company_id'=>1,'base_currency_code'=>'SAR','currency_code'=>'SAR']);DB::table('currencies')->insert(['currency_code'=>'SAR','is_active'=>1]);DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR','is_active'=>1,'is_base'=>1]);
        foreach([1=>'ASSET',2=>'LIABILITY',3=>'ASSET',4=>'ASSET',5=>'ASSET',6=>'EXPENSE',7=>'EQUITY']as$id=>$type)DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'is_active'=>1,'is_group'=>0,'allow_posting'=>1,'allow_cost_center'=>0,'account_type'=>$type,'account_code'=>(string)$id,'account_name'=>'Account '.$id]);
        foreach(['CUSTOMER_ACCOUNT'=>1,'SUPPLIER_ACCOUNT'=>2,'INVENTORY_ACCOUNT'=>3,'OPENING_BALANCE_ACCOUNT'=>7]as$key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
        DB::table('items')->insert(['id'=>1,'company_id'=>1,'is_active'=>1,'item_code'=>'TEST','item_name'=>'Test item']);
        foreach(['customers','suppliers']as$t)DB::table($t)->insert(['id'=>1,'company_id'=>1,'is_active'=>1,'scope_all_branches'=>1,'opening_balance'=>999]);
        DB::table('fixed_asset_categories')->insert(['id'=>1,'company_id'=>1,'is_active'=>1,'category_name'=>'Test','depreciation_method'=>'STRAIGHT_LINE','useful_life_months'=>12,'asset_account_id'=>4,'accumulated_depreciation_account_id'=>5,'depreciation_expense_account_id'=>6]);
    }
    private function payload():array{return ['financial_year_id'=>1,'opening_date'=>'2026-07-31','notes'=>'PHASEE-P1-UAT-isolated','account_lines'=>[['branch_id'=>1,'account_id'=>1,'party_type'=>'CUSTOMER','party_id'=>1,'debit'=>10],['branch_id'=>1,'account_id'=>2,'party_type'=>'SUPPLIER','party_id'=>1,'credit'=>5]],'inventory_lines'=>[['branch_id'=>1,'item_id'=>1,'qty_kg'=>2,'total_cost'=>6,'lot_number'=>'PHASEE-P1-UAT-LOT']],'asset_lines'=>[['branch_id'=>1,'category_id'=>1,'asset_code'=>'PHASEE-P1-UAT-ASSET','asset_name'=>'Test asset','acquisition_date'=>'2026-01-01','depreciation_start_date'=>'2026-01-01','historical_cost'=>12,'opening_accumulated_depreciation'=>2,'salvage_value'=>0,'useful_life_months'=>12]]];}
    public function test_opening_reconciles_inventory_assets_party_dimensions_and_retry():void
    {
        $service=app(OpeningBalanceService::class);$id=$service->saveDraft(1,$this->payload());$preview=$service->preview(1,$id);self::assertSame(28.0,$preview['total_debit']);self::assertSame(7.0,$preview['total_credit']);self::assertSame(21.0,$preview['difference']);
        try{$service->post(1,$id);self::fail('Unconfirmed balancing accepted');}catch(\RuntimeException$e){self::assertStringContainsString('تأكيد',$e->getMessage());}
        self::assertSame(0,DB::table('journal_entries')->count());$result=$service->post(1,$id,null,true);$service->post(1,$id,null,true);
        foreach(['journal_entries','inventory_lots','inventory_lot_movements','stock_movements','fixed_assets']as$t)self::assertSame(1,DB::table($t)->count(),$t);
        $lot=DB::table('inventory_lots')->first();self::assertSame(2.0,(float)$lot->qty_remaining_kg);self::assertSame(6.0,(float)$lot->total_cost);
        $gl=DB::table('journal_entry_lines')->where('journal_entry_id',$result['journal_entry_id']);self::assertSame(28.0,(float)(clone$gl)->sum('debit'));self::assertSame(28.0,(float)(clone$gl)->sum('credit'));self::assertSame(6.0,(float)(clone$gl)->where('account_id',3)->sum('debit'));
        self::assertSame(10.0,(float)(clone$gl)->where('party_type','CUSTOMER')->where('party_id',1)->sum('debit'));self::assertSame(5.0,(float)(clone$gl)->where('party_type','SUPPLIER')->where('party_id',1)->sum('credit'));
        $asset=DB::table('fixed_assets')->first();self::assertSame(10.0,(float)$asset->current_book_value);self::assertSame(12.0,(float)$asset->purchase_cost);self::assertSame(2.0,(float)$asset->accumulated_depreciation);
        self::assertSame(2.0,(float)app(InventoryLotService::class)->summary(1,1)[0]->balance_kg);
        $fifo=DB::transaction(fn()=>app(InventoryLotService::class)->consumeFifo(1,1,1,0.5,'TEST',1));self::assertSame((int)$lot->id,$fifo['allocations'][0]['inventory_lot_id']);self::assertSame(1.5,$fifo['total_cost']);self::assertSame(1.5,(float)DB::table('inventory_lots')->value('qty_remaining_kg'));
    }
    public function test_opening_batch_pagination_search_and_stable_second_page():void
    {
        for($i=1;$i<=31;$i++)DB::table('opening_balance_batches')->insert(['company_id'=>1,'financial_year_id'=>1,'opening_date'=>'2026-07-31','batch_number'=>'TEST-'.$i,'status'=>'DRAFT','notes'=>$i===1?'needle':null]);
        DB::table('opening_balance_batches')->insert(['company_id'=>2,'financial_year_id'=>1,'opening_date'=>'2026-07-31','batch_number'=>'FOREIGN','status'=>'DRAFT']);
        $s=app(OpeningBalanceService::class);$page=$s->index(1,null,['page'=>2]);self::assertSame(31,$page->total());self::assertCount(6,$page->items());self::assertSame(2,$page->currentPage());self::assertSame('TEST-6',$page->items()[0]->batch_number);self::assertSame(1,$s->index(1,null,['search'=>'needle'])->total());self::assertSame(100,$s->index(1,null,['per_page'=>999])->perPage());
    }

    public function test_exchange_rates_are_paged_and_searchable_across_the_full_company_scope():void
    {
        Schema::table('currencies',function(Blueprint$t){$t->string('currency_name')->nullable();$t->string('symbol')->nullable();$t->integer('decimal_places')->default(3);});Schema::table('cost_centers',fn(Blueprint$t)=>$t->boolean('is_group')->default(false));
        $this->table('tax_codes','company_id is_active sales_tax_account_id purchase_tax_account_id','tax_code');$this->table('exchange_rates','company_id','currency_code source valid_from','rate_to_base');
        for($i=1;$i<=31;$i++)DB::table('exchange_rates')->insert(['company_id'=>1,'currency_code'=>'USD','valid_from'=>'2026-07-31','rate_to_base'=>3.75,'source'=>$i===1?'needle':'TEST']);
        DB::table('exchange_rates')->insert(['company_id'=>2,'currency_code'=>'USD','valid_from'=>'2026-07-31','rate_to_base'=>99,'source'=>'needle']);
        $call=function(array$params){$r=\Illuminate\Http\Request::create('/api/financial-setup','GET',$params);$r->attributes->set('tenant_company_id',1);$r->attributes->set('effective_role_code','COMPANY_OWNER');return app(\App\Http\Controllers\Api\FinancialSetupController::class)->index($r,app(\App\Services\Accounting\AccountingContext::class))->getData(true)['data'];};
        $page=$call(['exchange_page'=>2]);self::assertSame(31,$page['exchange_rates_pagination']['total']);self::assertSame(2,$page['exchange_rates_pagination']['current_page']);self::assertCount(6,$page['exchange_rates']);self::assertSame(6,$page['exchange_rates'][0]['id']);self::assertSame(1,$call(['exchange_search'=>'needle'])['exchange_rates_pagination']['total']);
    }

    public function test_branch_view_is_filtered_and_mixed_batch_mutation_is_rejected():void
    {
        $s=app(OpeningBalanceService::class);$id=$s->saveDraft(1,$this->payload());
        DB::table('opening_balance_lines')->where('batch_id',$id)->where('party_type','SUPPLIER')->update(['branch_id'=>2]);
        $view=$s->show(1,$id,1);self::assertCount(1,$view['account_lines']);self::assertSame(2.0,$view['batch']->total_credit);self::assertNull($view['batch']->notes);
        try{$s->saveDraft(1,$this->payload(),null,1,$id);self::fail('Mixed branch draft overwritten');}catch(\RuntimeException$e){self::assertStringContainsString('خارج نطاق الفرع',$e->getMessage());}
        self::assertSame(2,DB::table('opening_balance_lines')->where('batch_id',$id)->count());
        try{$s->post(1,$id,null,true,1);self::fail('Mixed branch batch posted');}catch(\RuntimeException$e){self::assertStringContainsString('خارج نطاق الفرع',$e->getMessage());}self::assertSame(0,DB::table('journal_entries')->count());
    }

    public function test_invalid_asset_drafts_roll_back_without_partial_opening_records():void
    {
        foreach([['asset_account_id'=>6],['expense_account_id'=>4],['historical_cost'=>1,'opening_accumulated_depreciation'=>2],['salvage_value'=>11],['depreciation_method'=>'DECLINING_BALANCE','annual_depreciation_rate'=>0],['acquisition_date'=>'2027-01-01']]as$change){
            $payload=$this->payload();$payload['asset_lines'][0]=array_merge($payload['asset_lines'][0],$change);
            try{app(OpeningBalanceService::class)->saveDraft(1,$payload);self::fail('Invalid opening asset accepted');}catch(\RuntimeException$e){self::assertNotSame('',$e->getMessage());}
            self::assertSame(0,DB::table('opening_balance_batches')->count());self::assertSame(0,DB::table('journal_entries')->count());
        }
    }

    public function test_posting_rejects_inactive_and_wrong_branch_cost_centers():void
    {
        DB::table('accounts')->where('id',6)->update(['allow_cost_center'=>1]);
        foreach([['branch_id'=>1,'is_active'=>0],['branch_id'=>2,'is_active'=>1],['branch_id'=>1,'is_active'=>1,'company_id'=>2]]as$case){
            $id=DB::table('cost_centers')->insertGetId(array_merge(['company_id'=>1],$case));
            try{app(\App\Domain\Accounting\Services\JournalService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-08-01','description'=>'Test invalid center','lines'=>[['account_id'=>6,'cost_center_id'=>$id,'debit'=>1],['account_id'=>7,'credit'=>1]]]);self::fail('Invalid center accepted');}catch(\RuntimeException$e){self::assertStringContainsString('مركز التكلفة',$e->getMessage());}
            self::assertSame(0,DB::table('journal_entries')->count());
        }
    }

    public function test_first_depreciation_starts_from_opening_nbv_and_rejects_historical_month():void
    {
        $s=app(OpeningBalanceService::class);$id=$s->saveDraft(1,$this->payload());$s->post(1,$id,null,true);$asset=(int)DB::table('fixed_assets')->value('id');$depreciation=app(FixedAssetDepreciationService::class);
        try{$depreciation->depreciate($asset,'2026-07-01',['company_id'=>1]);self::fail('Historical depreciation accepted');}catch(\Exception$e){self::assertStringContainsString('الرصيد الافتتاحي',$e->getMessage());}
        $event=$depreciation->depreciate($asset,'2026-08-01',['company_id'=>1]);self::assertSame(10.0,$event['opening_book_value']);self::assertSame(1.0,$event['depreciation_amount']);self::assertSame(3.0,$event['accumulated_depreciation']);self::assertSame(9.0,$event['closing_book_value']);
        $lines=DB::table('journal_entry_lines')->where('journal_entry_id',$event['journal_entry_id']);self::assertSame((float)(clone$lines)->sum('debit'),(float)(clone$lines)->sum('credit'));
        try{$depreciation->depreciate($asset,'2026-08-01',['company_id'=>1]);self::fail('Duplicate depreciation accepted');}catch(\Exception$e){self::assertStringContainsString('مسبقًا',$e->getMessage());}self::assertSame(1,DB::table('fixed_asset_depreciation')->count());
    }
}
