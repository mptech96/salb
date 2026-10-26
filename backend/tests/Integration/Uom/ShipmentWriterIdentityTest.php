<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/ShipmentLineIdentityTest.php';

/** Actual operational routes/controllers, not the isolated adapter routes. */
class ShipmentWriterIdentityTest extends ShipmentLineIdentityTest
{
    private function line(array $extra=[]): array
    {
        return $extra+['item_id'=>1,'gross_qty_kg'=>5,'deduction_qty_kg'=>0,'accepted_qty_kg'=>5,'unit_price'=>10,'price_unit'=>'KG','vat_percent'=>0];
    }
    private function payload(array $lines): array
    {
        return ['branch_id'=>1,'shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-14','supplier_id'=>1,'currency_code'=>'SAR','exchange_rate'=>1,'items'=>$lines];
    }
    private function newDocument(array $lines): array
    {
        $r=$this->postJson('/api/shipments',$this->payload($lines));
        self::assertSame(201,$r->getStatusCode(),$r->getContent());return $r->json();
    }
    private function financial(): string
    {
        $all=[];foreach(['inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','vouchers'] as $t)$all[$t]=DB::table($t)->orderBy('id')->get();return serialize($all);
    }
    public function test_actual_http_create_edit_reorder_reopen_and_copy(): void
    {
        $before=$this->financial();$d=$this->newDocument([$this->line(),$this->line()]);$sid=$d['id'];[$a,$b]=$d['data']['items'];
        self::assertNotSame($a['allocation_line_ref'],$b['allocation_line_ref']);
        $r=$this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$b['id']]),$this->line(['id'=>$a['id'],'gross_qty_kg'=>6,'accepted_qty_kg'=>6,'unit_price'=>12])]));
        self::assertSame(200,$r->getStatusCode(),$r->getContent());
        $rows=$this->getJson('/api/shipments/'.$sid)->assertOk()->json('data.items');
        self::assertSame([$b['id'],$a['id']],array_column($rows,'id'));self::assertSame([$b['allocation_line_ref'],$a['allocation_line_ref']],array_column($rows,'allocation_line_ref'));self::assertEquals(72,$rows[1]['base_cost']);
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'READY']);
        $this->postJson('/api/shipments/'.$sid.'/reopen')->assertOk();
        self::assertSame($a['allocation_line_ref'],DB::table('shipment_items')->where('id',$a['id'])->value('allocation_line_ref'));
        // Copy economic values into a NEW business line, while retaining existing row IDs.
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id']]),$this->line(['id'=>$b['id']]),$this->line()]))->assertOk();
        self::assertSame(3,DB::table('shipment_items')->where('shipment_id',$sid)->distinct()->count('allocation_line_ref'));
        $copy=$this->newDocument([$this->line(),$this->line()]);self::assertNotSame($a['allocation_line_ref'],$copy['data']['items'][0]['allocation_line_ref']);
        self::assertSame($before,$this->financial());self::assertSame(0,DB::transactionLevel());
    }
    public function test_actual_api_rejects_ref_injection_foreign_and_duplicate_row_ids_atomically(): void
    {
        $d=$this->newDocument([$this->line()]);$sid=$d['id'];$a=$d['data']['items'][0];$before=serialize(DB::table('shipment_items')->where('shipment_id',$sid)->get());
        foreach([null,'','BL:FORGED',$a['allocation_line_ref']] as $ref)$this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id'],'allocation_line_ref'=>$ref])]))->assertStatus(422);
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id']]),$this->line(['id'=>$a['id']])]))->assertStatus(422);
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>999999])]))->assertStatus(422);
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line()]))->assertStatus(422);
        self::assertSame($before,serialize(DB::table('shipment_items')->where('shipment_id',$sid)->get()));
        Sanctum::actingAs(\App\Models\User::findOrFail(2),['*']);$this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id']])]))->assertForbidden();self::assertSame(0,DB::transactionLevel());
    }
    public function test_schema_absent_operational_writer_and_historical_null_edit(): void
    {
        $legacy=DB::table('shipment_items')->whereNull('allocation_line_ref')->first();$sid=$legacy->shipment_id;
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'DRAFT']);DB::table('shipment_costs')->where('shipment_id',$sid)->delete();
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$legacy->id])]))->assertOk();
        self::assertNull(DB::table('shipment_items')->where('id',$legacy->id)->value('allocation_line_ref'));
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_line_identity.php');$migration->down();
        $d=$this->newDocument([$this->line()]);$this->putJson('/api/shipments/'.$d['id'],$this->payload([$this->line(['unit_price'=>11])]))->assertOk();
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('shipment_items','allocation_line_ref'));
    }
    public function test_partial_schema_fails_before_header_or_sequence_writes(): void
    {
        DB::unprepared('DROP TRIGGER sli_insert');$before=serialize([DB::table('shipments')->get(),DB::table('sulb_document_sequences')->get()]);
        $this->postJson('/api/shipments',$this->payload([$this->line()]))->assertStatus(422);
        self::assertSame($before,serialize([DB::table('shipments')->get(),DB::table('sulb_document_sequences')->get()]));self::assertSame(0,DB::transactionLevel());
    }
    public function test_weighbridge_real_insert_and_update_retain_identity(): void
    {
        $d=$this->newDocument([]);$sid=$d['id'];
        DB::table('weighbridge_cards')->insert(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'item_id'=>1,'card_number'=>'IDENTITY-CARD','status'=>'CLOSED','net_weight_kg'=>5,'opened_at'=>now()]);
        app(\App\Services\WeighbridgeService::class)->syncShipmentItemsFromCards($sid);
        $a=DB::table('shipment_items')->where('shipment_id',$sid)->first();self::assertMatchesRegularExpression('/^BL:[A-F0-9]{32}$/',$a->allocation_line_ref);
        app(\App\Services\WeighbridgeService::class)->syncShipmentItemsFromCards($sid);
        self::assertSame($a->allocation_line_ref,DB::table('shipment_items')->where('id',$a->id)->value('allocation_line_ref'));self::assertSame(1,DB::table('shipment_items')->where('shipment_id',$sid)->count());
    }
    public function test_actual_posted_purchase_immutability_and_read_only(): void
    {
        $d=$this->newDocument([$this->line()]);$sid=$d['id'];$a=$d['data']['items'][0];
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'READY']);
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->update(['shipment_id'=>$sid,'shipment_item_id'=>$a['id'],'qty_kg'=>5,'quantity'=>5,'total_before_vat'=>50,'total_after_vat'=>50,'line_total'=>50,'base_total_before_vat'=>50,'base_total_after_vat'=>50]);
        DB::table('purchase_invoices')->where('id',$p)->update(['total_before_vat'=>50,'total_amount'=>50,'base_total_before_vat'=>50,'base_total_amount'=>50]);
        DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$p,'shipment_id'=>$sid,'allocated_qty_kg'=>5,'allocated_amount'=>50]);
        app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $row=DB::table('shipment_items')->where('id',$a['id'])->first();
        self::assertSame($a['allocation_line_ref'],$row->allocation_line_ref);
        self::assertEquals(50,DB::table('inventory_lots')->where('id',$row->inventory_lot_id)->value('total_cost'));
        $finance=$this->financial();
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id']])]))->assertStatus(422);
        self::assertSame($finance,$this->financial());
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'DRAFT']);
        DB::table('roles')->insert(['id'=>100,'role_code'=>'READ_ONLY','role_name'=>'Read only']);DB::table('user_roles')->where('user_id',1)->update(['role_id'=>100]);
        $this->putJson('/api/shipments/'.$sid,$this->payload([$this->line(['id'=>$a['id']])]))->assertForbidden();
        self::assertSame($a['allocation_line_ref'],DB::table('shipment_items')->where('id',$a['id'])->value('allocation_line_ref'));
    }
    public function test_late_invalid_line_rolls_back_prior_draft_update(): void
    {
        $d=$this->newDocument([$this->line()]);$sid=$d['id'];$a=$d['data']['items'][0];
        $before=serialize([DB::table('shipments')->where('id',$sid)->first(),DB::table('shipment_items')->where('shipment_id',$sid)->get()]);
        // Direct service exercises rollback after the first valid row has actually been updated.
        try{app(\App\Services\ShipmentService::class)->save($this->payload([$this->line(['id'=>$a['id'],'unit_price'=>99]),$this->line(['item_id'=>999999])]),1,1,1,$sid);self::fail('Expected rejection');}catch(\RuntimeException $e){self::assertStringContainsString('غير صالح',$e->getMessage());}
        self::assertSame($before,serialize([DB::table('shipments')->where('id',$sid)->first(),DB::table('shipment_items')->where('shipment_id',$sid)->get()]));self::assertSame(0,DB::transactionLevel());
    }
    public function test_identified_writer_purchase_freight_pay_and_void_keep_original_ref(): void
    {
        $d=$this->newDocument([$this->line(['gross_qty_kg'=>10,'accepted_qty_kg'=>10])]);$sid=$d['id'];$a=$d['data']['items'][0];
        $cost=app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>20,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>true,'created_by'=>1]);
        DB::table('shipments')->where('id',$sid)->update(['commercial_status'=>'READY']);
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->update(['shipment_id'=>$sid,'shipment_item_id'=>$a['id']]);
        DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$p,'shipment_id'=>$sid,'allocated_qty_kg'=>10,'allocated_amount'=>100]);
        $svc=app(\App\Services\EnterpriseInvoiceService::class);$svc->post('PURCHASE',1,$p,1,1);
        $r=DB::table('shipment_items')->where('id',$a['id'])->first();$lot=DB::table('inventory_lots')->where('id',$r->inventory_lot_id)->first();
        self::assertEquals(10,$lot->qty_received_kg);self::assertEquals(120,$lot->total_cost);self::assertSame($a['allocation_line_ref'],$r->allocation_line_ref);
        $journal=DB::table('shipment_costs')->where('id',$cost)->value('journal_entry_id');
        self::assertEquals(20,DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->sum('debit'));
        self::assertEquals(20,DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->sum('credit'));
        $voucher=DB::table('vouchers')->where('reference_type','SHIPMENT_COST')->where('reference_id',$cost)->first();self::assertNotNull($voucher);self::assertEquals($journal,$voucher->journal_entry_id);
        $before=$this->financial();try{$svc->post('PURCHASE',1,$p,1,1);}catch(\RuntimeException $e){}self::assertSame($before,$this->financial());
        $svc->void('PURCHASE',1,$p,1,'Identity regression',1);
        self::assertSame($a['allocation_line_ref'],DB::table('shipment_items')->where('id',$a['id'])->value('allocation_line_ref'));self::assertSame(0,DB::transactionLevel());
    }
}
