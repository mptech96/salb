<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\ShipmentService;
use App\Services\Uom\ShipmentCostBasis;
final class ShipmentBaseDraftTest extends \Tests\TestCase
{
    protected function setUp():void {parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();(require base_path('database/uom-migrations/2026_10_25_000007_shipment_snapshots.php'))->up();}
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function input():array {return ['shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-25','supplier_id'=>1,'currency_code'=>'SAR','cost_allocation_method'=>'RELATIVE_VALUE','items'=>[['item_id'=>1,'quantity'=>'0.25','unit_code'=>'CARTON','unit_price'=>'120','vat_percent'=>0]]];}
    public function test_base_draft_captures_inventory_without_inventing_weight_and_coexists_with_weight_lines():void
    {
        $input=$this->input();$input['items'][]=['item_id'=>2,'gross_qty_kg'=>'5','price_unit'=>'KG','unit_price'=>'2','vat_percent'=>0];
        $id=app(ShipmentService::class)->save($input,1,1,1);$lines=DB::table('shipment_items')->where('shipment_id',$id)->orderBy('id')->get();
        self::assertCount(2,$lines);$b=$lines[0];self::assertSame('0.250000',$b->quantity);self::assertSame('3.000000',$b->base_quantity);self::assertSame('CARTON',$b->unit_code);self::assertNull($b->actual_weight_kg);self::assertSame('0.000',$b->qty_kg);self::assertSame('5.000',$lines[1]->qty_kg);self::assertSame('40.000',DB::table('shipments')->where('id',$id)->value('total_amount'));
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());
        app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');self::assertSame('12.000000',DB::table('shipment_items')->where('id',$b->id)->value('uom_factor_snapshot'));
        (require base_path('database/uom-migrations/2026_10_25_000007_shipment_snapshots.php'))->up();self::assertSame('3.000000',DB::table('shipment_items')->where('id',$b->id)->value('base_quantity'));
    }
    public function test_actual_weight_is_optional_independent_and_invalid_basis_rolls_back_the_entire_draft():void
    {
        $input=$this->input();$input['items'][0]['actual_weight_kg']='48.125';$id=app(ShipmentService::class)->save($input,1,1,1);$b=DB::table('shipment_items')->where('shipment_id',$id)->first();self::assertSame('48.125',$b->actual_weight_kg);self::assertSame('3.000000',$b->base_quantity);
        $before=serialize([DB::table('shipments')->get(),DB::table('shipment_items')->get(),DB::table('sulb_document_sequences')->get()]);$input['cost_allocation_method']='WEIGHT';
        try{app(ShipmentService::class)->save($input,1,1,1);self::fail('Assumed weight basis accepted');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_SHIPMENT_COST_BASIS',$e->getMessage());}
        self::assertSame($before,serialize([DB::table('shipments')->get(),DB::table('shipment_items')->get(),DB::table('sulb_document_sequences')->get()]));
    }
    public function test_invoice_draft_uses_frozen_shipment_factor_and_cannot_select_another_company_source():void
    {
        $sid=app(ShipmentService::class)->save($this->input(),1,1,1);
        // This fixture exercises snapshot conversion only. READY posting/physical
        // admission is intentionally not claimed until the coordinator is adapted.
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'READY']);
        app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $payload=['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','shipment_ids'=>[$sid]];
        $invoices=app(\App\Services\EnterpriseInvoiceService::class);$id=$invoices->saveDraft('PURCHASE',$payload,1,1,1);
        $line=DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$id)->first();self::assertSame('3.000000',$line->base_quantity);self::assertSame('12.000000',$line->uom_factor_snapshot);self::assertSame('CARTON',$line->unit_code);self::assertSame('0.000',$line->qty_kg);
        $before=DB::table('purchase_invoices')->count();try{$invoices->saveDraft('PURCHASE',array_replace($payload,['supplier_id'=>2]),2,2,2);self::fail('Foreign shipment selected');}catch(\RuntimeException $e){self::assertNotEmpty($e->getMessage());}self::assertSame($before,DB::table('purchase_invoices')->count());
    }
    public function test_value_and_authorized_percent_allocation_conserve_milliunits_deterministically():void
    {
        $s=new ShipmentCostBasis;$lines=[10=>['item_code'=>'B','base_value'=>'1','cost_share_percent'=>'50'],11=>['item_code'=>'A','base_value'=>'1','cost_share_percent'=>'50']];
        foreach(['RELATIVE_VALUE','MANUAL_PERCENT'] as $method){$p=$s->plan('0.001',$method,$lines,true);self::assertSame('0.001',$p['allocations'][11]['amount']);self::assertSame('0.000',$p['allocations'][10]['amount']);self::assertSame($p,$s->plan('0.001',$method,$lines,true));}
        foreach([['WEIGHT',true],['MANUAL_PERCENT',false]] as [$method,$authorized]){try{$s->plan('10',$method,$lines,$authorized);self::fail('Unauthorized policy accepted');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_SHIPMENT_',$e->getMessage());}}
        $lines[10]['cost_share_percent']='49.999999';try{$s->plan('10','MANUAL_PERCENT',$lines,true);self::fail('Incomplete percentages accepted');}catch(\RuntimeException $e){self::assertSame('UOM_SHIPMENT_PERCENT_SUM',$e->getMessage());}
    }
    public function test_base_only_ready_does_not_require_or_invent_a_weighbridge_measurement():void
    {
        $s=app(ShipmentService::class);$id=$s->save($this->input(),1,1,1);$s->ready(1,$id,1,1);self::assertSame('READY',DB::table('shipments')->where('id',$id)->value('commercial_status'));self::assertNull(DB::table('shipment_items')->where('shipment_id',$id)->value('actual_weight_kg'));self::assertSame(0,DB::table('weighbridge_cards')->count());self::assertSame(0,DB::table('inventory_lots')->count());
    }
    public function test_mixed_shipment_requires_only_documented_weight_evidence_and_never_changes_base_quantity():void
    {
        $input=$this->input();$input['items'][]=['item_id'=>2,'gross_qty_kg'=>'5','price_unit'=>'KG','unit_price'=>'2','vat_percent'=>0];$s=app(ShipmentService::class);$id=$s->save($input,1,1,1);
        try{$s->ready(1,$id,1,1);self::fail('Legacy weight was guessed');}catch(\RuntimeException $e){self::assertStringContainsString('قياس ميزان',$e->getMessage());}
        DB::table('weighbridge_cards')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$id,'card_number'=>'QA-MIXED-WEIGHT','status'=>'CLOSED','item_id'=>2,'net_weight_kg'=>'5','loaded_weight_kg'=>'5','opened_at'=>'2026-10-25 10:00:00']);$s->ready(1,$id,1,1);self::assertSame('3.000000',DB::table('shipment_items')->where('shipment_id',$id)->where('item_id',1)->value('base_quantity'));self::assertNull(DB::table('shipment_items')->where('shipment_id',$id)->where('item_id',1)->value('actual_weight_kg'));
        $s->reopen(1,$id,1);DB::table('weighbridge_cards')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$id,'card_number'=>'QA-COUNTED-PHYSICAL','status'=>'CLOSED','item_id'=>1,'net_weight_kg'=>'48.125','loaded_weight_kg'=>'48.125','opened_at'=>'2026-10-25 11:00:00']);app(\App\Services\WeighbridgeService::class)->syncShipmentItemsFromCards($id);$s->ready(1,$id,1,1);$b=DB::table('shipment_items')->where('shipment_id',$id)->where('item_id',1)->first();self::assertSame('48.125',$b->actual_weight_kg);self::assertSame('3.000000',$b->base_quantity);self::assertSame('0.000',$b->qty_kg);
    }
    public function test_manual_percent_requires_cost_authority_exact_storage_precision_and_complete_sum():void
    {
        $input=$this->input();$input['cost_allocation_method']='MANUAL_PERCENT';$input['items'][0]['cost_share_percent']='100';$s=app(ShipmentService::class);
        try{$s->save($input,1,1,1);self::fail('Unauthorized percentages captured');}catch(\RuntimeException $e){self::assertSame('UOM_SHIPMENT_PERCENT_PERMISSION',$e->getMessage());}self::assertSame(0,DB::table('shipments')->count());
        DB::table('permissions')->insert(['permission_code'=>'shipments.cost','permission_name'=>'QA cost']);$id=$s->save($input,1,1,1);self::assertSame(1,(int)DB::table('shipments')->where('id',$id)->value('uom_cost_basis_approved_by'));
        $input['items'][0]['cost_share_percent']='99.999999';try{$s->save($input,1,1,1);self::fail('Percent rounded silently');}catch(\RuntimeException $e){self::assertSame('ALLOCATION_PRECISION',$e->getMessage());}self::assertSame(1,DB::table('shipments')->count());
    }
    public function test_logistics_transfer_keeps_explicit_unit_snapshot_without_double_posting_inventory():void
    {
        $input=$this->input();$input['shipment_type']='TRANSFER';$input['supplier_id']=null;$input['items'][0]['unit_price']='0';$s=app(ShipmentService::class);$id=$s->save($input,1,1,1);$s->ready(1,$id,1,1);self::assertSame('3.000000',DB::table('shipment_items')->where('shipment_id',$id)->value('base_quantity'));self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());
        try{app(\App\Services\EnterpriseInvoiceService::class)->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-25','currency_code'=>'SAR','shipment_ids'=>[$id]],1,1,1);self::fail('Logistics transfer was reinterpreted as purchase');}catch(\RuntimeException $e){self::assertStringContainsString('نوع إحدى الشحنات',$e->getMessage());}self::assertSame(0,DB::table('purchase_invoices')->count());
    }
    public function test_scale_evidence_can_precede_counted_quantity_without_creating_inventory_lines():void
    {
        $input=$this->input();$input['items']=[];$s=app(ShipmentService::class);$id=$s->save($input,1,1,1);
        DB::table('weighbridge_cards')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$id,'card_number'=>'QA-PHYSICAL-FIRST','status'=>'CLOSED','item_id'=>1,'net_weight_kg'=>'48.125','loaded_weight_kg'=>'50','empty_weight_kg'=>'1.875','opened_at'=>'2026-10-25 10:00:00']);app(\App\Services\WeighbridgeService::class)->syncShipmentItemsFromCards($id);self::assertSame(0,DB::table('shipment_items')->count());
        $s->save($this->input(),1,1,1,$id);app(\App\Services\WeighbridgeService::class)->syncShipmentItemsFromCards($id);$s->ready(1,$id,1,1);$line=DB::table('shipment_items')->first();self::assertSame('48.125',$line->actual_weight_kg);self::assertSame('3.000000',$line->base_quantity);self::assertSame('0.250000',$line->quantity);self::assertSame(0,DB::table('inventory_lots')->count());
    }
}
