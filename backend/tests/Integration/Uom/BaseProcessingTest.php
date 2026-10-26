<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\Uom\ProcessingRecipeService;
use App\Services\Uom\BaseProcessingService;
final class BaseProcessingTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        foreach(['2026_10_25_000008_processing_recipes.php','2026_10_25_000009_processing_commands.php'] as $file)(require base_path('database/uom-migrations/'.$file))->up();
        foreach(['inventory.process','inventory.process.post'] as $permission)DB::table('permissions')->insert(['permission_code'=>$permission,'permission_name'=>$permission]);
        DB::table('uom_units')->insert(['id'=>4,'code'=>'KG','name_ar'=>'KG','dimension'=>'MASS','standard_factor'=>'1','allow_fractional'=>1,'kind'=>'STANDARD']);app(\App\Services\Uom\ItemUnitService::class)->registerNew(1,2,4);app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,2,4,'1');
        $invoice=app(\App\Services\EnterpriseInvoiceService::class);$id=$invoice->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);$invoice->post('PURCHASE',1,$id,1,1);
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function recipe():int {$s=app(ProcessingRecipeService::class);$id=$s->create(1,1,['recipe_code'=>'QA-RECIPE','operation_type'=>'DISASSEMBLY','yield_notes'=>'Synthetic explicit measured production recipe','from'=>[['item_id'=>1,'quantity'=>'0.25','unit_code'=>'CARTON']],'to'=>[['item_id'=>2,'quantity'=>'1.5','unit_code'=>'KG']]]);$s->approve(1,1,$id);return $id;}
    private function snapshot():string {$data=[];foreach(['inventory_operations','inventory_operation_lines','uom_processing_commands','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$data[$table]=DB::table($table)->orderBy('id')->get();return serialize($data);}
    private function mixedRecipe():array
    {
        (require base_path('../docs/erp-baseline/proposed-migrations/inventory_lot_value_balances.php'))->up();config(['inventory_value.enabled'=>true]);
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'LEGACY-QA','item_name'=>'QA existing weight stock','inventory_account_id'=>11]);
        $invoice=app(\App\Services\EnterpriseInvoiceService::class);$id=$invoice->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>4,'qty_kg'=>'5','unit_price'=>'10','price_unit'=>'KG','vat_percent'=>0]]],1,1,1);$invoice->post('PURCHASE',1,$id,1,1);
        $s=app(ProcessingRecipeService::class);$recipe=$s->create(1,1,['recipe_code'=>'QA-MIXED-LEGACY','operation_type'=>'DISASSEMBLY','yield_notes'=>'Synthetic approved measured recipe: independent KG and PCS inputs and outputs','from'=>[['item_id'=>4,'quantity'=>'0.001','unit_code'=>'TON'],['item_id'=>1,'quantity'=>'3','unit_code'=>'PCS']],'to'=>[['item_id'=>1,'quantity'=>'2','unit_code'=>'PCS'],['item_id'=>4,'quantity'=>'0.5','unit_code'=>'KG']]]);$s->approve(1,1,$recipe);
        return ['recipe_id'=>$recipe,'request_key'=>'QA-MIXED-RECIPE','batch_multiplier'=>'1','operation_date'=>'2026-10-25','output_percentages'=>[1=>'50',4=>'50']];
    }
    private function mixedValue():void
    {
        $value='0.000';foreach(DB::table('inventory_lots')->get() as $lot)$value=bcadd($value,($lot->uom_version==='BASE_V1')?$lot->uom_remaining_value:DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->value('remaining_value'),3);
        self::assertSame('170.000',$value);self::assertSame('170.000',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
    }
    private function extraCost(bool $paid=false,string $amount='10.001'):array
    {
        (require base_path('database/uom-migrations/2026_10_26_000012_processing_cost_sources.php'))->up();
        DB::table('accounts')->insertOrIgnore(['id'=>14,'company_id'=>1,'account_code'=>'QA-BANK','account_name'=>'QA bank','account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1]);
        DB::table('financial_accounts')->insertOrIgnore(['id'=>1,'company_id'=>1,'branch_id'=>1,'account_code'=>'QA-BANK','account_name'=>'QA bank','account_type'=>'BANK','currency_code'=>'SAR','gl_account_id'=>14]);DB::table('accounting_settings')->insertOrIgnore(['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>12]);
        return ['cost_type'=>'QA energy','source_reference'=>'QA-SYNTHETIC-ENERGY-001','amount'=>$amount,'currency_code'=>'SAR','payment_status'=>$paid?'PAID':'UNPAID',...($paid?['financial_account_id'=>1]:[])];
    }
    private function totalLotValue():string
    {
        $sum='0.000';foreach(DB::table('inventory_lots')->get() as $lot)$sum=bcadd($sum,$lot->uom_version==='BASE_V1'?$lot->uom_remaining_value:DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->value('remaining_value'),3);return $sum;
    }
    public function test_extra_cost_mixed_recipe_posts_one_documented_cost_and_reverses_exact_lot_gl():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost()];$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);self::assertSame($id,$s->create(1,1,1,$input));$result=$s->post(1,1,$id,1);self::assertSame('40.000',$result['input_inventory_cost']);self::assertSame('10.001',$result['operation_overhead_cost']);self::assertSame('50.001',$result['total_output_cost']);self::assertSame('180.001',$this->totalLotValue());self::assertSame('180.001',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);self::assertSame('25.000',$result['outputs'][0]['total_cost']);self::assertSame('25.001',$result['outputs'][1]['total_cost']);
        $before=$this->snapshot();self::assertSame($result,$s->post(1,1,$id,1));self::assertSame($before,$this->snapshot());self::assertSame(1,DB::table('inventory_operation_costs')->whereNotNull('posted_at')->count());$s->void(1,1,$id,1,'QA reverse documented overhead and mixed stock');$this->mixedValue();self::assertSame(0,bccomp('0',DB::table('journal_entry_lines')->where('account_id',12)->whereIn('journal_entry_id',[$result['journal_entry_id'],DB::table('uom_processing_commands')->where('operation_id',$id)->value('reversal_journal_entry_id')])->selectRaw('SUM(credit-debit) v')->first()->v,3));
    }
    public function test_extra_cost_missing_source_and_late_gl_failure_do_not_commit_stock_or_cost():void
    {
        $input=$this->mixedRecipe();$cost=$this->extraCost(true);$input['costs']=[$cost];unset($input['costs'][0]['source_reference']);$s=app(BaseProcessingService::class);$before=$this->snapshot();try{$s->create(1,1,1,$input);self::fail('Undocumented cost accepted');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_COST_SOURCE_REQUIRED',$e->getMessage());}self::assertSame($before,$this->snapshot());
        $input['costs']=[$cost];$id=$s->create(1,1,1,$input);$before=$this->snapshot();$balances=serialize(DB::table('inventory_lot_value_balances')->get());DB::unprepared("CREATE TRIGGER qa_extra_cost_gl_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA extra cost GL failure'");try{$s->post(1,1,$id,1);self::fail('Partial overhead committed');}catch(\RuntimeException $e){self::assertStringContainsString('QA extra cost GL failure',$e->getMessage());}self::assertSame($before,$this->snapshot());self::assertSame($balances,serialize(DB::table('inventory_lot_value_balances')->get()));self::assertSame(0,DB::table('inventory_operation_costs')->whereNotNull('posted_at')->count());DB::unprepared('DROP TRIGGER qa_extra_cost_gl_failure');$s->post(1,1,$id,1);self::assertSame('180.001',$this->totalLotValue());self::assertSame('0.000',DB::table('journal_entry_lines')->where('financial_account_id',1)->where('credit','>',0)->value('foreign_credit'));$s->void(1,1,$id,1,'QA reverse paid overhead');$this->mixedValue();
    }
    public function test_extra_cost_real_races_apply_once_and_source_reference_cannot_be_loaded_twice():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost(true)];$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);require_once __DIR__.'/../../Support/BaseAdapterRace.php';foreach(['PROCESS_POST','PROCESS_VOID'] as $mode){$race=\Tests\Support\BaseAdapterRace::run($mode,'inventory_operations',$id);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);}self::assertSame(1,DB::table('uom_processing_cost_sources')->count());$this->mixedValue();
        $input['request_key']='QA-SECOND-SAME-COST';$second=$s->create(1,1,1,$input);$before=$this->snapshot();try{$s->post(1,1,$second,1);self::fail('Source cost capitalized twice');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_COST_SOURCE_ALREADY_USED',$e->getMessage());}self::assertSame($before,$this->snapshot());self::assertSame(1,DB::table('uom_processing_cost_sources')->count());
    }
    public function test_extra_cost_foreign_treasury_and_changed_cost_snapshot_are_rejected():void
    {
        $input=$this->mixedRecipe();$cost=$this->extraCost(true);DB::table('financial_accounts')->insert(['id'=>2,'company_id'=>2,'branch_id'=>2,'account_code'=>'FOREIGN','account_name'=>'QA foreign bank','account_type'=>'BANK','currency_code'=>'SAR','gl_account_id'=>21]);$cost['financial_account_id']=2;$input['costs']=[$cost];$s=app(BaseProcessingService::class);$before=$this->snapshot();try{$s->create(1,1,1,$input);self::fail('Other company treasury accepted');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_COST_FINANCIAL_SCOPE',$e->getMessage());}self::assertSame($before,$this->snapshot());
        $cost['financial_account_id']=1;$input['costs']=[$cost];$id=$s->create(1,1,1,$input);DB::table('inventory_operation_costs')->where('operation_id',$id)->update(['amount'=>'11.001']);$before=$this->snapshot();try{$s->post(1,1,$id,1);self::fail('Tampered overhead accepted');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_COST_SNAPSHOT_CHANGED',$e->getMessage());}self::assertSame($before,$this->snapshot());self::assertSame(0,DB::table('uom_processing_cost_sources')->count());
    }
    public function test_extra_cost_http_support_requires_capability_and_audit_success_is_atomic():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost(true)];$input['from_branch_id']=1;$input['operation_type']='DISASSEMBLY';$s=app(BaseProcessingService::class);
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'processing','is_enabled'=>1]);$caps=['POST:api/inventory-operations','POST:api/inventory-operations/{id}/approve','POST:api/inventory-operations/{id}/void'];$write=\Tests\Support\UomSupportActor::token($caps);$read=\Tests\Support\UomSupportActor::token($caps,'READ_ONLY');$send=function($path,$token,$body=[]) {\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/'.$path,$body);};$send('inventory-operations',$read,$input)->assertForbidden();$id=$send('inventory-operations',$write,$input)->assertCreated()->json('id');$before=$this->snapshot();
        DB::unprepared("CREATE TRIGGER qa_overhead_support_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA overhead audit failure'; END IF; END");$send('inventory-operations/'.$id.'/approve',$write)->assertStatus(500);self::assertSame($before,$this->snapshot());self::assertSame(0,DB::table('uom_processing_cost_sources')->count());DB::unprepared('DROP TRIGGER qa_overhead_support_audit');$send('inventory-operations/'.$id.'/approve',$write)->assertOk();self::assertSame('180.001',$this->totalLotValue());$send('inventory-operations/'.$id.'/void',$write,['reason'=>'QA Support overhead reversal'])->assertOk();$this->mixedValue();
    }
    public function test_extra_cost_history_reports_and_actual_pdf_keep_exact_documented_value():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost()];$service=app(BaseProcessingService::class);$id=$service->create(1,1,1,$input);$service->post(1,1,$id,1);
        $detail=app(\App\Services\InventoryOperationService::class)->details(1,$id,1);self::assertSame('QA-SYNTHETIC-ENERGY-001',$detail['operation_costs'][0]->source_reference);$report=app(\App\Services\ReportCenterService::class)->run(1,1,'inventory');self::assertSame(0,bccomp('180.001',(string)$report['summary']['stock_value'],3));
        require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertSame('180.001',$plan['financial']['1:11']['replayed_value']);
        foreach(['2026_08_31_000026_add_print_branding_profile_to_company_settings.php','2026_09_21_000027_create_central_print_documents.php'] as $file)(require base_path('database/migrations/'.$file))->up();$request=\Illuminate\Http\Request::create('/api/advanced-reports/inventory/export','GET',['format'=>'pdf']);$request->attributes->set('tenant_company_id',1);$request->attributes->set('tenant_branch_id',1);$response=app(\App\Http\Controllers\Api\AdvancedReportController::class)->export($request,'inventory',app(\App\Services\ReportCenterService::class),app(\App\Services\Accounting\AccountingContext::class));self::assertSame(200,$response->getStatusCode(),$response->getContent());self::assertSame('application/pdf',$response->headers->get('Content-Type'));$bytes=$response->getContent();self::assertStringStartsWith('%PDF-',$bytes);$path=storage_path('framework/testing/extra-cost-inventory.pdf');self::assertSame(strlen($bytes),file_put_contents($path,$bytes));self::assertSame(hash('sha256',$bytes),hash_file('sha256',$path));
        $service->void(1,1,$id,1,'QA original extra cost and stock reversal');$after=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($after['ready'],json_encode($after['exceptions']));$this->mixedValue();
    }
    public function test_extra_cost_fx_half_milli_and_precision_boundaries_are_explicit():void
    {
        $input=$this->mixedRecipe();$cost=$this->extraCost(false,'0.001');DB::table('currencies')->insert(['currency_code'=>'USD','currency_name'=>'QA USD','is_active'=>1]);DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'USD','is_base'=>0,'is_active'=>1]);$cost['currency_code']='USD';$cost['exchange_rate']='1.5';$input['costs']=[$cost];$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$result=$s->post(1,1,$id,1);self::assertSame('0.002',$result['operation_overhead_cost']);self::assertSame('170.002',$this->totalLotValue());self::assertSame('40.002',$result['total_output_cost']);
        foreach([['amount'=>'0.0001'],['amount'=>'1000000000000000'],['exchange_rate'=>1.5]] as $bad){$body=$input;$body['request_key']='QA-FX-INVALID-'.count($bad);$body['costs']=[array_replace($cost,$bad)];$before=$this->snapshot();try{$s->create(1,1,1,$body);self::fail('Inexact source accepted');}catch(\RuntimeException $e){self::assertMatchesRegularExpression('/UOM_PROCESS_COST_(AMOUNT|RATE)/',$e->getMessage());}self::assertSame($before,$this->snapshot());}
    }
    public function test_extra_cost_two_distinct_orders_race_for_one_source_and_only_one_capitalizes():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost(true)];$s=app(BaseProcessingService::class);$first=$s->create(1,1,1,$input);$input['request_key']='QA-OTHER-ORDER-SAME-SOURCE';$second=$s->create(1,1,1,$input);require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('PROCESS_COST_POST','financial_accounts',1,[$first,$second]);self::assertCount(2,$race['waiting_threads']);self::assertCount(1,array_filter($race['results'],fn($id)=>$id>0));self::assertContains(0,$race['results']);self::assertSame(1,DB::table('uom_processing_cost_sources')->count());self::assertSame(1,DB::table('uom_processing_commands')->where('status','POSTED')->count());self::assertSame('180.001',$this->totalLotValue());self::assertSame('180.001',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
    }
    public function test_extra_cost_schema_restart_preserves_claims_and_rejects_missing_unique_index():void
    {
        $input=$this->mixedRecipe();$input['costs']=[$this->extraCost()];$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$s->post(1,1,$id,1);$before=serialize(DB::table('uom_processing_cost_sources')->get());$migration=require base_path('database/uom-migrations/2026_10_26_000012_processing_cost_sources.php');$migration->up();self::assertSame($before,serialize(DB::table('uom_processing_cost_sources')->get()));
        foreach(['update','delete'] as $action){try{if($action==='update')DB::table('uom_processing_cost_sources')->update(['base_amount'=>'99']);else DB::table('uom_processing_cost_sources')->delete();self::fail('Cost source history changed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('UOM_PROCESS_COST_SOURCE_IMMUTABLE',$e->getMessage());}}
        DB::unprepared('ALTER TABLE uom_processing_cost_sources DROP INDEX uq_process_cost_source');try{$migration->up();self::fail('Unprotected financial source accepted');}catch(\RuntimeException $e){self::assertSame('UOM_PROCESS_COST_SOURCE_INDEX_REQUIRED',$e->getMessage());}self::assertSame($before,serialize(DB::table('uom_processing_cost_sources')->get()));
    }
    public function test_alternative_manual_scrap_cannot_silently_drop_a_base_output_without_recipe():void
    {
        $this->mixedRecipe();$before=$this->snapshot();
        try{app(\App\Services\InventoryOperationService::class)->create(['company_id'=>1,'from_branch_id'=>1,'created_by'=>1,'operation_type'=>'SCRAP','operation_date'=>'2026-10-26','from_lines'=>[['item_id'=>4,'qty_kg'=>'1']],'to_lines'=>[['item_id'=>1,'quantity'=>'2','unit_code'=>'PCS']]]);self::fail('BASE output silently discarded');}catch(\RuntimeException $e){self::assertSame('UOM_LEGACY_WEIGHT_ADAPTER_REQUIRED',$e->getMessage());}self::assertSame($before,$this->snapshot());
    }
    public function test_legacy_fifo_final_residual_is_not_lost_across_three_recipe_batches():void
    {
        $this->mixedRecipe();DB::table('items')->insert(['id'=>5,'company_id'=>1,'item_code'=>'QA-RESIDUAL','item_name'=>'QA exact residual KG','inventory_account_id'=>11]);$invoices=app(\App\Services\EnterpriseInvoiceService::class);$purchase=$invoices->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-26','currency_code'=>'SAR','items'=>[['item_id'=>5,'qty_kg'=>'3','unit_price'=>'0.000333','price_unit'=>'KG','vat_percent'=>0]]],1,1,1);$invoices->post('PURCHASE',1,$purchase,1,1);
        $recipes=app(ProcessingRecipeService::class);$recipe=$recipes->create(1,1,['recipe_code'=>'QA-FINAL-RESIDUAL','operation_type'=>'CONVERSION','yield_notes'=>'Explicit synthetic 1 KG input and 1 PCS output per batch; no inferred mass','from'=>[['item_id'=>5,'quantity'=>'1','unit_code'=>'KG']],'to'=>[['item_id'=>1,'quantity'=>'1','unit_code'=>'PCS']]]);$recipes->approve(1,1,$recipe);$costs=[];$service=app(BaseProcessingService::class);
        $ids=[];for($n=1;$n<=3;$n++){$id=$service->create(1,1,1,['recipe_id'=>$recipe,'request_key'=>'QA-RESIDUAL-BATCH-'.$n,'batch_multiplier'=>'1','operation_date'=>'2026-10-26']);$ids[]=$id;$costs[]=$service->post(1,1,$id,1)['input_inventory_cost'];}
        self::assertSame(['0.000','0.000','0.001'],$costs);$lot=DB::table('inventory_lots')->where('item_id',5)->first();self::assertSame('0.000',$lot->qty_remaining_kg);self::assertSame('0.000',DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->value('remaining_value'));self::assertSame('170.001',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
        require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));
        foreach(array_reverse($ids) as $id)$service->void(1,1,$id,1,'QA exact zero-cost and final residual reversal');$again=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($again['ready'],json_encode($again['exceptions']));self::assertSame('0.001',DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->value('remaining_value'));
    }
    public function test_mixed_legacy_kg_and_base_recipe_posts_fifo_and_reverses_exact_original_values():void
    {
        $input=$this->mixedRecipe();$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$result=$s->post(1,1,$id,1);self::assertSame('40.000',$result['input_inventory_cost']);$this->mixedValue();
        self::assertSame('4.000',DB::table('inventory_lots')->where('item_id',4)->orderBy('id')->value('qty_remaining_kg'));self::assertSame('9.000000',DB::table('inventory_lots')->where('item_id',1)->orderBy('id')->value('remaining_base_qty'));
        self::assertSame(2,DB::table('inventory_operation_lot_links')->where('uom_version','LEGACY_KG')->count());$before=$this->snapshot();self::assertSame($result,$s->post(1,1,$id,1));self::assertSame($before,$this->snapshot());
        $s->void(1,1,$id,1,'QA exact mixed dimensional reversal');$this->mixedValue();self::assertSame('5.000',DB::table('inventory_lots')->where('item_id',4)->orderBy('id')->value('qty_remaining_kg'));self::assertSame('12.000000',DB::table('inventory_lots')->where('item_id',1)->orderBy('id')->value('remaining_base_qty'));
    }
    public function test_mixed_recipe_missing_qualified_value_and_late_gl_failure_roll_back_all_stock():void
    {
        $input=$this->mixedRecipe();$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$before=$this->snapshot();config(['inventory_value.enabled'=>false]);
        try{$s->post(1,1,$id,1);self::fail('Unqualified old values used');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_RECIPE_LEGACY_VALUE_QUALIFICATION_REQUIRED',$e->getMessage());}self::assertSame($before,$this->snapshot());config(['inventory_value.enabled'=>true]);
        $balances=serialize(DB::table('inventory_lot_value_balances')->get());DB::unprepared("CREATE TRIGGER qa_mixed_gl_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA mixed GL failure'");
        try{$s->post(1,1,$id,1);self::fail('Partial mixed stock committed');}catch(\RuntimeException $e){self::assertStringContainsString('QA mixed GL failure',$e->getMessage());}self::assertSame($before,$this->snapshot());self::assertSame($balances,serialize(DB::table('inventory_lot_value_balances')->get()));DB::unprepared('DROP TRIGGER qa_mixed_gl_failure');$s->post(1,1,$id,1);$this->mixedValue();
    }
    public function test_mixed_recipe_real_post_and_void_races_apply_once():void
    {
        $input=$this->mixedRecipe();$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);require_once __DIR__.'/../../Support/BaseAdapterRace.php';
        putenv('SULB_QA_ADAPTER_VALUE_LEDGER=1');try{foreach(['PROCESS_POST','PROCESS_VOID'] as $mode){$race=\Tests\Support\BaseAdapterRace::run($mode,'inventory_operations',$id);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);$this->mixedValue();}}finally{putenv('SULB_QA_ADAPTER_VALUE_LEDGER');}
    }
    public function test_mixed_processing_rejects_foreign_company_and_branch_without_mutation():void
    {
        $input=$this->mixedRecipe();$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$before=$this->snapshot();$balances=serialize(DB::table('inventory_lot_value_balances')->get());
        foreach([[2,2,2],[1,2,1]] as [$company,$branch,$user]){
            try{$s->post($company,$branch,$id,$user);self::fail('Foreign mixed operation posted');}catch(\RuntimeException $e){self::assertMatchesRegularExpression('/UOM_(OWNER_SOURCE_REQUIRED|PROCESS_COMMAND_SCOPE)/',$e->getMessage());}
            self::assertSame($before,$this->snapshot());self::assertSame($balances,serialize(DB::table('inventory_lot_value_balances')->get()));
        }
        $s->post(1,1,$id,1);$before=$this->snapshot();try{$s->void(1,2,$id,1,'QA forbidden foreign branch reversal');self::fail('Foreign branch reversal committed');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_COMMAND_SCOPE',$e->getMessage());}self::assertSame($before,$this->snapshot());$this->mixedValue();
    }
    public function test_mixed_history_dry_run_preserves_proven_values_and_reports_missing_recipe_source():void
    {
        $input=$this->mixedRecipe();$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,$input);$s->post(1,1,$id,1);require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';require_once __DIR__.'/../../Support/LotUpgradeDryRun.php';\Tests\Support\HistoricalLotBackfill::install();
        $before=$this->snapshot();$balances=serialize(DB::table('inventory_lot_value_balances')->get());$result=\Tests\Support\LotUpgradeDryRun::run(1,2026);self::assertTrue($result['ready'],json_encode($result['backfill']['exceptions']));self::assertFalse($result['writes_performed']);self::assertFalse($result['production_qualified']);self::assertSame($before,$this->snapshot());self::assertSame($balances,serialize(DB::table('inventory_lot_value_balances')->get()));$this->mixedValue();
        $lot=DB::table('inventory_lots')->where('source_type','INVENTORY_OPERATION')->where('uom_version','LEGACY_KG')->value('id');DB::table('inventory_lots')->where('id',$lot)->update(['source_id'=>999999]);$bad=\Tests\Support\LotUpgradeDryRun::run(1,2026);self::assertFalse($bad['ready']);self::assertContains('LEGACY_PROCESS_SOURCE_REVIEW_REQUIRED',array_column($bad['backfill']['exceptions'],'reason'));self::assertFalse($bad['writes_performed']);
        DB::table('inventory_lots')->where('id',$lot)->update(['source_id'=>$id]);$s->void(1,1,$id,1,'QA preserve mixed historical reversal');$after=\Tests\Support\LotUpgradeDryRun::run(1,2026);self::assertTrue($after['ready'],json_encode($after['backfill']['exceptions']));$this->mixedValue();
    }
    public function test_support_create_uses_recipe_owner_and_rolls_back_command_and_sequence_on_audit_failure():void
    {
        config(['app.debug'=>false]);$recipe=$this->recipe();
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'processing','is_enabled'=>1]);
        $caps=['POST:api/inventory-operations'];$write=\Tests\Support\UomSupportActor::token($caps);$read=\Tests\Support\UomSupportActor::token($caps,'READ_ONLY');$foreign=\Tests\Support\UomSupportActor::token($caps,'WRITE',2);
        $body=['operation_type'=>'DISASSEMBLY','from_branch_id'=>1,'operation_date'=>'2026-10-25','recipe_id'=>$recipe,'request_key'=>'QA-SUPPORT-CREATE','batch_multiplier'=>'1'];
        $send=function($token)use($body){\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/inventory-operations',$body);};
        $before=$this->snapshot();$send($read)->assertForbidden();$send($foreign)->assertStatus(422);self::assertSame($before,$this->snapshot());
        DB::unprepared("CREATE TRIGGER qa_create_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA create audit failure'; END IF; END");
        $send($write)->assertStatus(500);self::assertSame($before,$this->snapshot());DB::unprepared('DROP TRIGGER qa_create_audit_failure');
        $id=$send($write)->assertCreated()->json('id');self::assertSame($id,$send($write)->assertCreated()->json('id'));self::assertSame(1,DB::table('uom_processing_commands')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_real_post_and_void_races_wait_on_the_same_source_and_apply_once():void
    {
        $s=app(BaseProcessingService::class);$id=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-RACE','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);require_once __DIR__.'/../../Support/BaseAdapterRace.php';
        foreach(['PROCESS_POST','PROCESS_VOID'] as $mode){$race=\Tests\Support\BaseAdapterRace::run($mode,'inventory_operations',$id);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);}
        self::assertSame(3,DB::table('journal_entries')->count());self::assertSame('12.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));self::assertSame('120.000',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
    }
    public function test_historical_plan_preserves_proven_recipe_outputs_and_reports_missing_source_without_guessing():void
    {
        $s=app(BaseProcessingService::class);$id=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-PLAN','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);$result=$s->post(1,1,$id,1);
        require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertCount(2,$plan['preserved_base_lots']);self::assertSame('120.000',$plan['financial']['1:11']['replayed_value']);
        $lot=$result['outputs'][0]['inventory_lot_id'];DB::table('inventory_lots')->where('id',$lot)->update(['source_id'=>999999]);$bad=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertFalse($bad['ready']);self::assertContains('BASE_PROCESS_SOURCE_REVIEW_REQUIRED',array_column($bad['exceptions'],'reason'));
        DB::table('inventory_lots')->where('id',$lot)->update(['source_id'=>$id]);$s->void(1,1,$id,1,'QA source-preserving reversal');$again=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($again['ready'],json_encode($again['exceptions']));
    }
    public function test_explicit_scrap_recipe_uses_fifo_loss_account_and_reverses_without_inventing_outputs():void
    {
        DB::table('accounts')->insert(['id'=>34,'company_id'=>1,'account_code'=>'LOSS','account_name'=>'QA loss','account_type'=>'EXPENSE','normal_side'=>'DEBIT','allow_posting'=>1]);DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>'INVENTORY_ADJUSTMENT_ACCOUNT','account_id'=>34]);
        $recipes=app(ProcessingRecipeService::class);$recipe=$recipes->create(1,1,['recipe_code'=>'QA-SCRAP','operation_type'=>'SCRAP','yield_notes'=>'Explicit synthetic loss with no recoverable output','from'=>[['item_id'=>1,'quantity'=>'3','unit_code'=>'PCS']],'to'=>[]]);$recipes->approve(1,1,$recipe);
        $s=app(BaseProcessingService::class);$id=$s->create(1,1,1,['recipe_id'=>$recipe,'request_key'=>'QA-SCRAP-POST','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);$result=$s->post(1,1,$id,1);self::assertSame([],$result['outputs']);self::assertSame(1,DB::table('inventory_lots')->count());self::assertSame('90.000',DB::table('inventory_lots')->value('uom_remaining_value'));self::assertSame('30.000',DB::table('journal_entry_lines')->where('account_id',34)->selectRaw('SUM(debit-credit) v')->first()->v);
        $s->void(1,1,$id,1,'QA reverse explicit scrap loss');self::assertSame('12.000000',DB::table('inventory_lots')->value('remaining_base_qty'));self::assertSame('120.000',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
    }
    public function test_http_recipe_approval_creation_post_and_void_use_existing_permission_and_branch_contracts():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'processing','is_enabled'=>1]);
        $this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        $r=$this->postJson('/api/inventory/recipes',['branch_id'=>1,'recipe_code'=>'QA-HTTP','operation_type'=>'DISASSEMBLY','yield_notes'=>'Synthetic explicitly documented output','from'=>[['item_id'=>1,'quantity'=>'0.25','unit_code'=>'CARTON']],'to'=>[['item_id'=>2,'quantity'=>'1.5','unit_code'=>'KG']]])->assertCreated();$recipe=$r->json('id');
        $this->postJson('/api/inventory/recipes/'.$recipe.'/approve',['branch_id'=>1])->assertOk();
        $id=$this->postJson('/api/inventory-operations',['operation_type'=>'DISASSEMBLY','from_branch_id'=>1,'operation_date'=>'2026-10-25','recipe_id'=>$recipe,'request_key'=>'QA-HTTP-PROCESS','batch_multiplier'=>'1'])->assertCreated()->json('id');
        $this->postJson('/api/inventory-operations/'.$id.'/approve')->assertOk();$this->postJson('/api/inventory-operations/'.$id.'/void',['reason'=>'QA HTTP exact reversal'])->assertOk();self::assertSame('12.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));
        DB::table('roles')->insert(['id'=>3,'role_code'=>'QA_VIEWER','role_name'=>'QA viewer']);DB::table('users')->insert(['id'=>3,'company_id'=>1,'branch_id'=>1,'name'=>'QA viewer','username'=>'qa-process-viewer','password'=>'synthetic']);DB::table('user_roles')->insert(['user_id'=>3,'role_id'=>3,'company_id'=>1]);$view=DB::table('permissions')->insertGetId(['permission_code'=>'inventory.view','permission_name'=>'View','permission_scope'=>'COMPANY']);DB::table('role_permissions')->insert(['role_id'=>3,'permission_id'=>$view,'company_id'=>1]);$this->actingAs(\App\Models\User::findOrFail(3),'sanctum');
        $before=$this->snapshot();$this->postJson('/api/inventory/recipes/'.$recipe.'/approve',['branch_id'=>1])->assertForbidden();$this->postJson('/api/inventory-operations/'.$id.'/void',['reason'=>'QA forbidden viewer reversal'])->assertForbidden();self::assertSame($before,$this->snapshot());
    }
    public function test_support_process_owner_rolls_back_success_audit_failure_and_denies_foreign_company():void
    {
        config(['app.debug'=>false]);$s=app(BaseProcessingService::class);$id=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-SUPPORT','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'processing','is_enabled'=>1]);$caps=['POST:api/inventory-operations/{id}/approve','POST:api/inventory-operations/{id}/void'];$write=\Tests\Support\UomSupportActor::token($caps);$read=\Tests\Support\UomSupportActor::token($caps,'READ_ONLY');$foreign=\Tests\Support\UomSupportActor::token($caps,'WRITE',2);
        $send=function(string $path,string $token,array $body=[]){\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/'.$path,$body);};$path='inventory-operations/'.$id.'/approve';$before=$this->snapshot();$send($path,$read)->assertForbidden();$send($path,$foreign)->assertNotFound();self::assertSame($before,$this->snapshot());
        DB::unprepared("CREATE TRIGGER qa_process_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA process audit failure'; END IF; END");$failed=$send($path,$write)->assertStatus(500);self::assertStringNotContainsString('SQLSTATE',$failed->getContent());self::assertSame($before,$this->snapshot());DB::unprepared('DROP TRIGGER qa_process_audit_failure');
        $send($path,$write)->assertOk();$send('inventory-operations/'.$id.'/void',$write,['reason'=>'QA Support complete reversal'])->assertOk();self::assertSame('12.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));self::assertSame(0,DB::transactionLevel());
    }
    public function test_recipe_processing_moves_exact_fifo_cost_across_explicit_dimensions_once():void
    {
        $recipe=$this->recipe();$s=app(BaseProcessingService::class);$input=['recipe_id'=>$recipe,'request_key'=>'QA-PROCESS-001','batch_multiplier'=>'2','operation_date'=>'2026-10-25'];$id=$s->create(1,1,1,$input);self::assertSame($id,$s->create(1,1,1,$input));
        app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');$result=$s->post(1,1,$id,1);self::assertSame('60.000',$result['input_inventory_cost']);
        self::assertSame('6.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));self::assertSame('3.000000',DB::table('inventory_lots')->where('item_id',2)->value('remaining_base_qty'));
        self::assertSame('60.000',DB::table('inventory_lots')->where('item_id',2)->value('uom_remaining_value'));self::assertSame('120.000',DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('SUM(debit-credit) v')->first()->v);
        $before=$this->snapshot();self::assertSame($result,$s->post(1,1,$id,1));self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());
    }
    public function test_missing_recipe_and_late_gl_failure_leave_no_partial_processing_stock_or_sequence():void
    {
        $s=app(BaseProcessingService::class);$before=$this->snapshot();try{$s->create(1,1,1,['recipe_id'=>999,'request_key'=>'QA-PROCESS-MISSING','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);self::fail('Recipe guessed');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_APPROVED_RECIPE_REQUIRED',$e->getMessage());}self::assertSame($before,$this->snapshot());
        $id=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-FAIL','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);$before=$this->snapshot();
        DB::unprepared("CREATE TRIGGER qa_process_journal_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA process journal failure'");
        try{$s->post(1,1,$id,1);self::fail('Partial processing committed');}catch(\Throwable $e){self::assertStringContainsString('QA process journal failure',$e->getMessage());}self::assertSame($before,$this->snapshot());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        DB::unprepared('DROP TRIGGER qa_process_journal_failure');$s->post(1,1,$id,1);self::assertSame('POSTED',DB::table('inventory_operations')->where('id',$id)->value('status'));
    }
    public function test_void_restores_exact_input_lot_and_rejects_used_output_without_partial_reversal():void
    {
        $s=app(BaseProcessingService::class);$id=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-VOID','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);$r=$s->post(1,1,$id,1);
        DB::transaction(fn()=>\App\Services\Uom\BaseInventory::consume(1,1,2,'0.5','QA_PROCESS_USE',999,1));$before=$this->snapshot();
        try{$s->void(1,1,$id,1,'QA cannot reverse issued output');self::fail('Issued output reversed');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_PROCESS_OUTPUT_ALREADY_ISSUED',$e->getMessage());}self::assertSame($before,$this->snapshot());
        // A second independent process has a complete output and can be reversed.
        $other=$s->create(1,1,1,['recipe_id'=>$this->recipe(),'request_key'=>'QA-PROCESS-VOID-SECOND','batch_multiplier'=>'1','operation_date'=>'2026-10-25']);$posted=$s->post(1,1,$other,1);$s->void(1,1,$other,1,'QA exact source restoration');
        self::assertSame('9.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));self::assertSame('90.000',DB::table('inventory_lots')->where('item_id',1)->value('uom_remaining_value'));self::assertSame('0.000000',DB::table('inventory_lots')->where('id',$posted['outputs'][0]['inventory_lot_id'])->value('remaining_base_qty'));
        $before=$this->snapshot();$s->void(1,1,$other,1,'QA idempotent restoration');self::assertSame($before,$this->snapshot());self::assertSame(2,DB::table('inventory_operation_lot_links')->where('operation_id',$other)->count());
    }
}
