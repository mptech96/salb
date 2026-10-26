<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use App\Services\ShipmentService;
use App\Services\CommercialReturnService;

final class ShipmentBaseCycleTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        (require base_path('database/uom-migrations/2026_10_25_000007_shipment_snapshots.php'))->up();
        foreach(['stock_movements_company_source_index.php','shipment_cost_parent_fk.php','shipment_cost_allocation_receipts.php','inventory_lot_value_balances.php'] as $file)(require base_path('../docs/erp-baseline/proposed-migrations/'.$file))->up();
        (require base_path('database/uom-migrations/2026_10_26_000010_base_capitalization.php'))->up();
        (require base_path('database/uom-migrations/2026_10_26_000011_invoice_header_cost.php'))->up();
        config(['shipment_cost_read.enabled'=>true,'shipment_allocation.enabled'=>true,'inventory_value.enabled'=>true]);
        DB::table('uom_units')->insert(['id'=>4,'code'=>'KG','name_ar'=>'KG','dimension'=>'MASS','standard_factor'=>'1','allow_fractional'=>1,'kind'=>'STANDARD']);
        foreach([[14,'BANK','ASSET'],[15,'EXP','EXPENSE']] as [$id,$code,$type])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>$code,'account_name'=>'QA '.$code,'account_type'=>$type,'normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('accounting_settings')->insert([['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>12],['company_id'=>1,'setting_key'=>'GENERAL_EXPENSE_ACCOUNT','account_id'=>15]]);
        DB::table('financial_accounts')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'account_code'=>'BANK','account_name'=>'QA bank','account_type'=>'BANK','gl_account_id'=>14,'currency_code'=>'SAR']);DB::table('expense_types')->insert(['id'=>1,'company_id'=>1,'type_name'=>'QA freight','type_code'=>'FREIGHT','account_id'=>15,'usage_type'=>'SHIPMENT','affects_cost'=>1]);DB::table('voucher_types')->insert(['id'=>2,'type_code'=>'PAYMENT','type_name'=>'Payment']);
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function purchase(bool $mixed=true,bool $paid=false,string $cost='20.001'):array
    {
        $items=[['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'120','vat_percent'=>0]];if($mixed)$items[]=['item_id'=>2,'gross_qty_kg'=>'10','price_unit'=>'KG','unit_price'=>'10','vat_percent'=>0];
        $shipments=app(ShipmentService::class);$sid=$shipments->save(['shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-25','supplier_id'=>1,'currency_code'=>'SAR','cost_allocation_method'=>'RELATIVE_VALUE','items'=>$items],1,1,1);
        if($mixed)DB::table('weighbridge_cards')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'card_number'=>'QA-MIXED','status'=>'CLOSED','item_id'=>2,'net_weight_kg'=>'10','loaded_weight_kg'=>'10','opened_at'=>'2026-10-25 10:00:00']);$shipments->ready(1,$sid,1,1);
        $costId=app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'expense_type_id'=>1,'expense_date'=>'2026-10-25','amount'=>$cost,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>$paid?'PAID':'UNPAID','financial_account_id'=>$paid?1:null,'capitalizable'=>true,'created_by'=>1]);
        $id=app(EnterpriseInvoiceService::class)->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','shipment_ids'=>[$sid]],1,1,1);return ['shipment'=>$sid,'cost'=>$costId,'invoice'=>$id];
    }
    private function value(string $expected):void
    {
        $sum='0.000';foreach(DB::table('inventory_lots')->get() as $lot){if(($lot->uom_version??'')==='BASE_V1')$sum=bcadd($sum,$lot->uom_remaining_value,3);else $sum=bcadd($sum,DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->value('remaining_value'),3);}
        self::assertSame(0,bccomp($expected,$sum,3));self::assertSame(0,bccomp($expected,DB::table('journal_entry_lines')->where('account_id',11)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v,3));
    }
    public function test_linked_base_outbound_shipment_uses_purchase_fifo_and_reverses_original_lot_values():void
    {
        $purchase=$this->purchase();$invoices=app(EnterpriseInvoiceService::class);$invoices->post('PURCHASE',1,$purchase['invoice'],1,1);
        $shipments=app(ShipmentService::class);$sid=$shipments->save(['shipment_type'=>'SALE','shipment_date'=>'2026-10-26','customer_id'=>1,'currency_code'=>'SAR','cost_allocation_method'=>'RELATIVE_VALUE','items'=>[['item_id'=>1,'quantity'=>'0.25','unit_code'=>'CARTON','unit_price'=>'180','vat_percent'=>0]]],1,1,1);$shipments->ready(1,$sid,1,1);
        $sale=$invoices->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-26','currency_code'=>'SAR','shipment_ids'=>[$sid]],1,1,1);$invoices->post('SALE',1,$sale,1,1);$this->value('207.273');$source=DB::table('sales_line_lot_sources')->first();self::assertSame($purchase['shipment'],(int)$source->shipment_id);self::assertSame('3.000000',$source->base_quantity);
        $invoices->void('SALE',1,$sale,1,'QA linked counted shipment original FIFO reversal',1);$this->value('240.001');self::assertSame('12.000000',DB::table('inventory_lots')->where('item_id',1)->value('remaining_base_qty'));
    }
    public function test_mixed_paid_purchase_real_l3_concurrency_capitalizes_and_pays_once():void
    {
        $s=$this->purchase(true,true);require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('SHIPMENT_PURCHASE_POST','companies',1,$s['invoice']);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);$this->value('240.001');self::assertSame(1,DB::table('vouchers')->count());self::assertSame(1,DB::table('shipment_cost_allocation_receipts')->count());self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());self::assertSame(2,DB::table('inventory_lots')->count());
    }
    public function test_support_mixed_paid_l3_purchase_denies_other_company_and_rolls_back_success_audit_failure():void
    {
        config(['app.debug'=>false]);$s=$this->purchase(true,true);require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$cap=['POST:api/purchase-invoices/{id}/post'];$write=\Tests\Support\UomSupportActor::token($cap);$foreign=\Tests\Support\UomSupportActor::token($cap,'WRITE',2);$read=\Tests\Support\UomSupportActor::token($cap,'READ_ONLY');
        $tables=['inventory_lots','inventory_lot_movements','inventory_lot_value_balances','stock_movements','journal_entries','journal_entry_lines','vouchers','shipment_cost_allocation_receipts','uom_lot_capitalization_events','sulb_document_sequences'];$snapshot=function()use($tables){$data=[];foreach($tables as $table)$data[$table]=DB::table($table)->orderBy('id')->get();return serialize($data);};$before=$snapshot();
        $send=function($token)use($s){\Tests\Support\UomSupportActor::forgetAuth();return $this->withHeaders(['Authorization'=>'Bearer '.$token])->postJson('/api/purchase-invoices/'.$s['invoice'].'/post');};$send($read)->assertForbidden();$send($foreign)->assertNotFound();self::assertSame($before,$snapshot());
        DB::unprepared("CREATE TRIGGER qa_mixed_l3_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_type='SUPPORT_WRITE' AND NEW.result='SUCCESS' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA mixed L3 audit failure'; END IF; END");$failure=$send($write)->assertStatus(500);self::assertStringNotContainsString('SQLSTATE',$failure->getContent());self::assertSame($before,$snapshot());DB::unprepared('DROP TRIGGER qa_mixed_l3_audit_failure');
        $send($write)->assertOk();$this->value('240.001');self::assertSame(1,DB::table('vouchers')->count());self::assertSame(1,DB::table('shipment_cost_allocation_receipts')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_late_capitalization_failure_rolls_back_receipts_lots_gl_payment_and_sequence_then_retries_once():void
    {
        $s=$this->purchase(true,true);$tables=['shipments','shipment_items','shipment_costs','purchase_invoices','inventory_lots','inventory_lot_movements','inventory_lot_value_balances','stock_movements','journal_entries','journal_entry_lines','vouchers','shipment_cost_allocation_receipts','uom_lot_capitalization_events','sulb_document_sequences'];
        $snapshot=function()use($tables){$data=[];foreach($tables as $table)$data[$table]=DB::table($table)->orderBy('id')->get();return serialize($data);};$before=$snapshot();
        DB::unprepared("CREATE TRIGGER qa_capitalization_failure BEFORE INSERT ON uom_lot_capitalization_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA late capitalization failure'");
        try{app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::fail('Partial capitalization committed');}catch(\Throwable $e){self::assertStringContainsString('QA late capitalization failure',$e->getMessage());}
        self::assertSame($before,$snapshot());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());DB::unprepared('DROP TRIGGER qa_capitalization_failure');
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);$this->value('240.001');self::assertSame(1,DB::table('vouchers')->count());self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());
    }
    public function test_actual_mixed_capitalized_inventory_export_saves_pdf_bytes():void
    {
        $s=$this->purchase();app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);$this->value('240.001');
        (require base_path('database/migrations/2026_08_31_000026_add_print_branding_profile_to_company_settings.php'))->up();(require base_path('database/migrations/2026_09_21_000027_create_central_print_documents.php'))->up();
        $request=\Illuminate\Http\Request::create('/api/advanced-reports/inventory/export','GET',['format'=>'pdf']);$request->attributes->set('tenant_company_id',1);$request->attributes->set('tenant_branch_id',1);
        $response=app(\App\Http\Controllers\Api\AdvancedReportController::class)->export($request,'inventory',app(\App\Services\ReportCenterService::class),app(\App\Services\Accounting\AccountingContext::class));
        self::assertSame(200,$response->getStatusCode(),$response->getContent());self::assertSame('application/pdf',$response->headers->get('Content-Type'));self::assertStringContainsString('attachment;',$response->headers->get('Content-Disposition'));$bytes=$response->getContent();self::assertStringStartsWith('%PDF-',$bytes);self::assertGreaterThan(1000,strlen($bytes));
        $path=storage_path('framework/testing/policy-mixed-capitalized-inventory.pdf');self::assertSame(strlen($bytes),file_put_contents($path,$bytes,LOCK_EX));self::assertSame(hash('sha256',$bytes),hash_file('sha256',$path));self::assertSame(0,DB::transactionLevel());
    }
    public function test_mixed_purchase_l3_receipt_capitalization_fifo_return_and_reversals_reconcile():void
    {
        $s=$this->purchase();$invoices=app(EnterpriseInvoiceService::class);$invoices->post('PURCHASE',1,$s['invoice'],1,1);$this->value('240.001');
        self::assertSame('130.910',DB::table('inventory_lots')->where('item_id',1)->value('total_cost'));self::assertSame('109.091',DB::table('inventory_lots')->where('item_id',2)->value('total_cost'));self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());self::assertSame(1,DB::table('shipment_cost_allocation_receipts')->count());
        $sale=$invoices->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','items'=>[['item_id'=>1,'quantity'=>'3','unit_code'=>'PCS','unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);$invoices->post('SALE',1,$sale,1,1);$this->value('207.273');
        $detail=app(ShipmentService::class)->details(1,$s['shipment'],1);$base=$detail['items']->firstWhere('item_id',1);self::assertSame('9.000000',$base->on_hand_base_quantity);self::assertSame('9.000000',$base->remaining_base_qty);self::assertSame('12.000000',$base->base_quantity);self::assertSame('PCS',$base->base_unit_code_snapshot);
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');$returns=app(CommercialReturnService::class);$return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-25','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'3']]]);$returns->post(1,$return,1,1);$this->value('240.001');$returns->void(1,$return,1,'QA original return reversal',1);$this->value('207.273');$invoices->void('SALE',1,$sale,1,'QA original FIFO sale reversal',1);$this->value('240.001');
        $reader=app(\App\Services\Uom\UnifiedInventoryReader::class)->read(1,[1]);self::assertCount(2,$reader);foreach($reader as $group)self::assertSame('MATCHED',$group['valuation_status']);
        $invoices->void('PURCHASE',1,$s['invoice'],1,'QA complete purchase and costs reversal',1);$this->value('0');self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());app(\App\Services\Uom\UnifiedInventoryReader::class)->read(1,[1]);
    }
    public function test_paid_base_only_purchase_has_one_cost_receipt_one_payment_and_idempotent_post():void
    {
        $s=$this->purchase(false,true);$service=app(EnterpriseInvoiceService::class);$service->post('PURCHASE',1,$s['invoice'],1,1);$this->value('140.001');self::assertSame(1,DB::table('vouchers')->count());$before=serialize([DB::table('inventory_lots')->get(),DB::table('journal_entries')->get(),DB::table('uom_lot_capitalization_events')->get()]);$service->post('PURCHASE',1,$s['invoice'],1,1);self::assertSame($before,serialize([DB::table('inventory_lots')->get(),DB::table('journal_entries')->get(),DB::table('uom_lot_capitalization_events')->get()]));
    }
    public function test_purchase_return_of_capitalized_stock_keeps_inventory_gl_equal_to_lot():void
    {
        $s=$this->purchase(false);$invoices=app(EnterpriseInvoiceService::class);$invoices->post('PURCHASE',1,$s['invoice'],1,1);
        $line=DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->value('id');$returns=app(CommercialReturnService::class);$return=$returns->saveDraft(1,1,1,['return_type'=>'PURCHASE_RETURN','source_invoice_id'=>$s['invoice'],'return_date'=>'2026-10-25','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'0.25']]]);
        $before=serialize([DB::table('inventory_lots')->get(),DB::table('stock_movements')->get(),DB::table('journal_entries')->get()]);
        try{$returns->post(1,$return,1,1);self::fail('Inventory was used as its own return variance account');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_RETURN_VARIANCE_ACCOUNT_REQUIRED',$e->getMessage());}self::assertSame($before,serialize([DB::table('inventory_lots')->get(),DB::table('stock_movements')->get(),DB::table('journal_entries')->get()]));
        DB::table('items')->where('company_id',1)->where('id',1)->update(['purchase_return_account_id'=>15]);$returns->post(1,$return,1,1);$this->value('105.001');self::assertSame('5.000',DB::table('journal_entry_lines')->where('account_id',15)->selectRaw('SUM(debit-credit) v')->first()->v);
        DB::table('items')->where('company_id',1)->where('id',1)->update(['purchase_return_account_id'=>11]);$returns->void(1,$return,1,'QA reverse frozen return journal after account change',1);$this->value('140.001');
    }
    public function test_header_freight_uses_one_frozen_milliunit_plan_for_all_stock_lots_and_gl():void
    {
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'A-OTHER','item_name'=>'QA third stock item','inventory_account_id'=>11]);
        $rows=[['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'100','price_unit'=>'UNIT','vat_percent'=>0],['item_id'=>2,'qty_kg'=>'10','unit_price'=>'10','price_unit'=>'KG','vat_percent'=>0],['item_id'=>4,'qty_kg'=>'10','unit_price'=>'10','price_unit'=>'KG','vat_percent'=>0]];$maps=[];
        foreach([false,true] as $reverse){$id=app(EnterpriseInvoiceService::class)->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','transport_cost'=>'0.001','items'=>$reverse?array_reverse($rows):$rows],1,1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);$map=[];foreach(DB::table('inventory_lots')->where('purchase_invoice_id',$id)->get() as $lot)$map[$lot->item_id]=$lot->base_cost;ksort($map);$maps[]=$map;}
        self::assertSame($maps[0],$maps[1]);self::assertSame([1=>'100.000',2=>'100.000',4=>'100.001'],$maps[0]);$this->value('600.002');self::assertSame(2,DB::table('uom_invoice_header_cost_receipts')->whereNotNull('journal_entry_id')->count());
    }
    public function test_unresolved_header_cost_tie_rolls_back_before_stock_journal_or_allocation_receipt():void
    {
        $row=['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'100','price_unit'=>'UNIT','vat_percent'=>0];$service=app(EnterpriseInvoiceService::class);$id=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','extra_cost'=>'0.001','items'=>[$row,$row]],1,1,1);
        $before=serialize(DB::table('sulb_document_sequences')->get());try{$service->post('PURCHASE',1,$id,1,1);self::fail('Ambiguous economic tie was guessed');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_ECONOMIC_TIE',$e->getMessage());}
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('uom_invoice_header_cost_receipts')->count());self::assertSame($before,serialize(DB::table('sulb_document_sequences')->get()));
    }
    public function test_header_freight_currency_half_milli_rounding_is_captured_once_and_rolls_back_late_failure():void
    {
        DB::table('currencies')->insertOrIgnore(['currency_code'=>'USD','currency_name'=>'QA USD','is_active'=>1]);
        DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'USD','is_base'=>0,'is_active'=>1]);
        $service=app(EnterpriseInvoiceService::class);$id=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-26','currency_code'=>'USD','exchange_rate'=>'1.5','transport_cost'=>'0.001','items'=>[['item_id'=>1,'quantity'=>'1','unit_code'=>'CARTON','unit_price'=>'100','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
        DB::unprepared("CREATE TRIGGER qa_header_gl_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA header freight GL failure'");
        try{$service->post('PURCHASE',1,$id,1,1);self::fail('Partial header freight committed');}catch(\RuntimeException $e){self::assertStringContainsString('QA header freight GL failure',$e->getMessage());}
        self::assertSame(0,DB::table('uom_invoice_header_cost_receipts')->count());self::assertSame(0,DB::table('inventory_lots')->count());DB::unprepared('DROP TRIGGER qa_header_gl_failure');
        $service->post('PURCHASE',1,$id,1,1);$this->value('150.002');$receipt=DB::table('uom_invoice_header_cost_receipts')->first();self::assertSame('0.002',$receipt->amount);self::assertSame(hash('sha256',$receipt->payload),$receipt->payload_hash);$before=serialize($receipt);$service->post('PURCHASE',1,$id,1,1);self::assertSame($before,serialize(DB::table('uom_invoice_header_cost_receipts')->first()));
    }
    public function test_base_capitalization_dry_plan_preserves_evidence_before_and_after_purchase_void():void
    {
        $s=$this->purchase(false);$service=app(EnterpriseInvoiceService::class);$service->post('PURCHASE',1,$s['invoice'],1,1);require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertSame('140.001',$plan['financial']['1:11']['replayed_value']);
        $service->void('PURCHASE',1,$s['invoice'],1,'QA preserve capitalization evidence on void',1);$after=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($after['ready'],json_encode($after['exceptions']));self::assertSame('0.000',$after['financial']['1:11']['replayed_value']);self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());
    }
    public function test_mixed_paid_l3_purchase_void_race_reverses_payment_and_inventory_once_without_losing_receipts():void
    {
        $s=$this->purchase(true,true);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $invoiceJournal=DB::table('purchase_invoices')->where('id',$s['invoice'])->value('journal_entry_id');$costJournal=DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id');
        require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('SHIPMENT_PURCHASE_VOID','purchase_invoices',$s['invoice']);
        self::assertCount(2,$race['waiting_threads']);self::assertCount(1,array_filter($race['results'],fn($id)=>$id>0));self::assertContains(0,$race['results']);$this->value('0.000');
        foreach([$invoiceJournal,$costJournal]as$original)self::assertSame(1,DB::table('journal_entries')->where('reversal_of_id',$original)->count());
        self::assertSame('0.000',DB::table('journal_entry_lines')->where('account_id',14)->selectRaw('SUM(debit-credit) v')->first()->v);
        self::assertSame(1,DB::table('shipment_costs')->where('recreated_from_cost_id',$s['cost'])->where('cost_status','DRAFT')->count());
        self::assertSame(1,DB::table('shipment_cost_allocation_receipts')->count());self::assertSame(1,DB::table('uom_lot_capitalization_events')->count());
        require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);
        // Legacy value is qualified by its own frozen receipt and original/reversal GL.
        self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertSame('0.000',$plan['financial']['1:11']['replayed_value']);
    }
    public function test_untouched_legacy_capitalized_receipts_backfill_without_guessing_and_reject_changed_sources():void
    {
        $s=$this->purchase(true,true);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();
        $before=serialize([DB::table('inventory_lots')->get(),DB::table('journal_entry_lines')->get()]);$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertSame(['replayed_value'=>'240.001','gl_value'=>'240.001'],$plan['financial']['1:11']);self::assertSame('109.091',$plan['rows'][0]['remaining_value']);self::assertSame($before,serialize([DB::table('inventory_lots')->get(),DB::table('journal_entry_lines')->get()]));
        $run=\Tests\Support\HistoricalLotBackfill::apply($plan,true);self::assertSame($run,\Tests\Support\HistoricalLotBackfill::apply($plan,true));
        $lot=DB::table('inventory_lots')->where('item_id',2)->first();DB::table('inventory_lots')->where('id',$lot->id)->update(['allocated_cost'=>bcadd($lot->allocated_cost,'0.001',3)]);$bad=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertFalse($bad['ready']);self::assertContains('SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED',array_column($bad['exceptions'],'reason'));
        try{\Tests\Support\HistoricalLotBackfill::apply($bad,true);self::fail('Guessed capitalized value applied');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_HISTORY',$e->getMessage());}
    }
}
