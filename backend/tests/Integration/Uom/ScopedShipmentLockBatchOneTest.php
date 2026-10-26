<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;
class ScopedShipmentLockBatchOneTest extends ShipmentPurchaseLockGateTest
{
 public function test_same_invoice_and_cost_source_concurrent_no_double_capitalization():void{
  foreach(['purchase','cost'] as $other){for($i=0;$i<3;$i++){
   $s=F::purchase();$count=DB::table('journal_entries')->count();$key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('purchase',$s['invoice'],false,'before-year');$b=null;
   try{$this->await($a,'BEFORE_YEAR');$b=$this->worker($other,$other==='purchase'?$s['invoice']:$s['shipment']);$this->await($b,'from `companies`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
   self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertSame($count+2,DB::table('journal_entries')->count());$this->financial($s);
  }}
 }
 public function test_two_drafts_for_same_shipment_cannot_post_second():void{
  $s=F::purchase();$second=\Tests\Support\UomOperationalLockFixture::purchase(1,1);DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$second)->update(['shipment_id'=>$s['shipment'],'shipment_item_id'=>$s['line']]);DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$second,'shipment_id'=>$s['shipment'],'allocated_qty_kg'=>10,'allocated_amount'=>100]);
  $this->postInvoice($s['invoice']);try{$this->postInvoice($second);self::fail('Second draft for fully invoiced shipment');}catch(\RuntimeException $e){self::assertStringContainsString('SHIPMENT_NOT_READY',$e->getMessage());}
  self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('inventory_lots')->count());self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$second)->value('document_status'));$this->financial($s);
 }
 public function test_link_change_after_probe_is_rejected_before_financial_writes():void{
  foreach(['remove','amount','add'] as $change){
   $s=F::purchase();if($change==='add')DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->delete();$key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$p=$this->worker('purchase',$s['invoice'],false,'after-classification');
   try{$this->await($p,'SCOPE_CLASSIFIED');DB::transaction(function()use($s,$change){DB::table('purchase_invoices')->where('id',$s['invoice'])->lockForUpdate()->first();if($change==='remove')DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->delete();elseif($change==='amount')DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->update(['allocated_amount'=>101]);else DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$s['invoice'],'shipment_id'=>$s['shipment'],'allocated_qty_kg'=>10,'allocated_amount'=>100]);});}finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$p->wait();}
   self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('SHIPMENT_POST_SCOPE_CHANGED',$p->getOutput());self::assertStringNotContainsString('COMMITTED',$p->getOutput());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());
  }
 }
 public function test_forced_1213_rolls_back_actual_linked_transaction_and_full_retry():void{
  $s=F::purchase();DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();for($i=0;$i<50;$i++)DB::table('items')->insert(['company_id'=>1,'item_code'=>'LOCK-WEIGHT-'.$i,'item_name'=>'Disposable']);$p=$this->worker('purchase',$s['invoice']);
  try{$this->await($p,'from `items`');app(\App\Services\SulbDocumentSequenceService::class)->lockVoucherCompany(1);}finally{if(DB::transactionLevel()>0)DB::rollBack();}
  $p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1213',$p->getOutput());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());$this->postInvoice($s['invoice']);$this->postInvoice($s['invoice']);$this->financial($s);
 }
 public function test_plain_purchase_does_not_take_company_and_nested_linked_is_rejected():void{
  $id=\Tests\Support\UomOperationalLockFixture::purchase(1,1);$p=$this->worker('purchase',$id);$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertStringNotContainsString('from `companies`',$p->getOutput());self::assertEquals(100,DB::table('inventory_lots')->value('total_cost'));
  $s=F::purchase();try{DB::transaction(fn()=>$this->postInvoice($s['invoice']));self::fail('Unknown outer lock owner');}catch(\RuntimeException $e){self::assertStringContainsString('SHIPMENT_POST_OUTERMOST_REQUIRED',$e->getMessage());}self::assertSame(1,DB::table('journal_entries')->count());
 }
 public function test_original_lot_pay_numbers_and_weight_fifo_values():void{
  $s=F::purchase();$this->postInvoice($s['invoice']);$this->financial($s);self::assertSame('LOT-2026-000001',DB::table('inventory_lots')->value('lot_number'));self::assertSame('PAY-2026-000001',DB::table('vouchers')->value('voucher_number'));
  $result=DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,1,5,'SALE',555));self::assertEquals(60,$result['total_cost']);self::assertEquals(5,DB::table('inventory_lots')->value('qty_remaining_kg'));self::assertEquals(12,DB::table('inventory_lots')->value('unit_cost_per_kg'));self::assertSame(2,DB::table('journal_entries')->count());
 }
 public function test_supplier_voucher_real_reservation_and_posting_against_linked_purchase():void{
  for($i=0;$i<3;$i++){
   $s=F::purchase();$before=DB::table('journal_entries')->count();$key=DB::connection()->getDatabaseName().'-ship-gate';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$this->worker('voucher',1,false,'before-year');$b=null;
   try{$this->await($a,'BEFORE_YEAR');$b=$this->worker('purchase',$s['invoice']);$this->await($b,'from `companies`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);$a->wait();if($b)$b->wait();}
   self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertSame($before+3,DB::table('journal_entries')->count());self::assertSame(($i+1)*2,DB::table('vouchers')->count());$this->financial($s);self::assertSame(($i+1)*2,DB::table('vouchers')->select('voucher_number')->distinct()->get()->count());
  }
 }
}
