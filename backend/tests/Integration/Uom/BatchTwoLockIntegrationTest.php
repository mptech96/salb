<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

class BatchTwoLockIntegrationTest extends BatchTwoExternalLockBoundaryTest
{
 private function worker(string $kind,int $company,int $id,string $phase=''):Process{
  $p=new Process([PHP_BINARY,base_path('scripts/batch_two_integration_worker.php'),$kind,(string)$company,(string)$id,$phase],base_path());$p->setTimeout(22);$p->start();return $p;
 }
 private function await(Process $p,string $text):void{
  $until=microtime(true)+10;while(!str_contains($p->getOutput(),$text)&&$p->isRunning()&&microtime(true)<$until)usleep(10000);self::assertStringContainsString($text,$p->getOutput(),$p->getErrorOutput());
 }
 private function sale():int{
  foreach([16=>['ASSET','DEBIT'],17=>['REVENUE','CREDIT'],18=>['EXPENSE','DEBIT']]as$id=>[$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'B2-'.$id,'account_name'=>'Synthetic sale','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1]);
  foreach(['CUSTOMER_ACCOUNT'=>16,'SALES_ACCOUNT'=>17,'COGS_ACCOUNT'=>18]as$key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
  DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Synthetic customer','ledger_account_id'=>16,'scope_all_branches'=>1]);
  $shipment=DB::table('shipments')->insertGetId(['company_id'=>1,'branch_id'=>1,'shipment_type'=>'SALE','customer_id'=>1,'shipment_number'=>'B2-SALE-SHIP','shipment_date'=>'2026-10-14','commercial_status'=>'READY','currency_code'=>'SAR']);
  $shipmentItem=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$shipment,'item_id'=>1,'qty_kg'=>5,'accepted_qty_kg'=>5,'remaining_qty_kg'=>5,'unit_price_per_kg'=>20]);
  $id=DB::table('sales_invoices')->insertGetId(['company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'B2-SALE','invoice_date'=>'2026-10-14','currency_code'=>'SAR','exchange_rate'=>1,'total_before_vat'=>100,'total_amount'=>100,'base_total_before_vat'=>100,'base_total_amount'=>100,'created_by'=>1]);
  DB::table('sales_invoice_lines')->insert(['company_id'=>1,'sales_invoice_id'=>$id,'item_id'=>1,'shipment_id'=>$shipment,'shipment_item_id'=>$shipmentItem,'qty_kg'=>5,'quantity'=>5,'unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>20,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
  DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'SALE','invoice_id'=>$id,'shipment_id'=>$shipment,'allocated_qty_kg'=>5,'allocated_amount'=>100]);return $id;
 }
 private function residualDraft(array $s,int $amount):int{
  // Explicit synthetic residual draft. The public draft API correctly refuses
  // adding new costs to an invoiced shipment; do not weaken that policy.
  $c=(array)DB::table('shipment_costs')->where('id',$s['cost'])->first();unset($c['id']);
  $c['amount']=$amount;$c['foreign_amount']=$amount;$c['cost_status']='DRAFT';$c['payment_status']='UNPAID';$c['financial_account_id']=null;$c['journal_entry_id']=null;$c['voucher_id']=null;$c['posted_at']=null;$c['posted_by']=null;$c['distributed']=0;
  return DB::table('shipment_costs')->insertGetId($c);
 }
 public function test_sale_and_cost_distribution_serialize_without_double_finance():void{
  $s=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
  $sale=$this->sale();
  $extra=$this->residualDraft($s,20);
  $key=DB::connection()->getDatabaseName().'-batch2-barrier';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=$d=null;
  try{$a=$this->worker('sale',1,$sale,'hold');$this->await($a,'BEFORE_FINANCIAL_YEAR');$b=$this->worker('cost',1,$s['shipment']);$this->await($b,'from `items`');
   preg_match('/CONNECTION (\d+)/',$b->getOutput(),$connection);$wait=[];$until=microtime(true)+1;
   do{$wait=DB::select('SELECT l.lock_table,l.lock_index,l.lock_data,r.trx_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?',[(int)$connection[1]]);if(!$wait)usleep(10000);}while(!$wait&&microtime(true)<$until);
   fwrite(STDOUT,'BATCH2_ITEM_SERIALIZATION '.json_encode(['wait'=>$wait,'later_row_plan'=>DB::select('EXPLAIN SELECT * FROM shipment_items WHERE company_id=? AND shipment_id IN (?) ORDER BY id FOR UPDATE',[1,$s['shipment']])])."\n");
   $this->await($b,'from `items`');self::assertStringNotContainsString('BEFORE_FINANCIAL_YEAR',$b->getOutput());self::assertStringNotContainsString('COMMITTED',$b->getOutput());$d=$this->worker('distribution',1,$s['shipment']);$this->await($d,'from `shipments`');self::assertStringNotContainsString('COMMITTED',$d->getOutput());}
  finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b)$b->wait();if($d)$d->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(1,$b->getExitCode(),$b->getOutput());self::assertStringNotContainsString('1213',$b->getOutput());self::assertStringContainsString('"level":0,"pdo":false',$b->getOutput());
  self::assertSame('DRAFT',DB::table('shipment_costs')->where('id',$extra)->value('cost_status'));self::assertNull(DB::table('shipment_costs')->where('id',$extra)->value('journal_entry_id'));self::assertSame(3,DB::table('journal_entries')->count());
  self::assertEquals(5,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(5,DB::table('sales_line_lot_sources')->sum('qty_kg'));self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));
  foreach(DB::table('journal_entries')->pluck('id')as$j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));
  self::assertSame(0,$d->getExitCode(),$d->getOutput());self::assertSame(3,DB::table('journal_entries')->count());
 }
 public function test_purchase_locks_extra_shipment_item_before_first_financial_year():void{
  $s=F::purchase();DB::table('shipment_items')->insert(['company_id'=>1,'shipment_id'=>$s['shipment'],'item_id'=>2,'qty_kg'=>1,'accepted_qty_kg'=>1,'remaining_qty_kg'=>1]);
  DB::beginTransaction();DB::table('items')->where('id',2)->lockForUpdate()->first();$p=null;
  try{$p=$this->worker('purchase',1,$s['invoice']);$this->await($p,'[1,2]');self::assertStringNotContainsString('BEFORE_FINANCIAL_YEAR',$p->getOutput());}
  finally{DB::rollBack();if($p)$p->wait();}
  self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('vouchers')->count());
 }
 public function test_other_company_purchase_finishes_while_company_one_cost_is_paused():void{
  $s=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
  $this->residualDraft($s,10);
  $other=\Tests\Support\UomOperationalLockFixture::purchase(2,3);$key=DB::connection()->getDatabaseName().'-batch2-barrier';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;
  try{$a=$this->worker('cost',1,$s['shipment'],'hold');$this->await($a,'BEFORE_FINANCIAL_YEAR');$b=$this->worker('purchase',2,$other);$b->wait();self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertTrue($a->isRunning());}
  finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame('POSTED',DB::table('purchase_invoices')->where('id',$other)->value('document_status'));self::assertEquals(130,DB::table('inventory_lots')->where('company_id',1)->sum('total_cost'));self::assertEquals(100,DB::table('inventory_lots')->where('company_id',2)->sum('total_cost'));
 }
 public function test_same_shipment_purchase_and_cost_commit_one_receipt_and_one_pay():void{
  $s=F::purchase();$key=DB::connection()->getDatabaseName().'-batch2-barrier';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;
  try{$a=$this->worker('purchase',1,$s['invoice'],'hold');$this->await($a,'BEFORE_FINANCIAL_YEAR');$b=$this->worker('cost',1,$s['shipment']);$this->await($b,'from `companies`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}
  finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b)$b->wait();}
  self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());self::assertSame(1,DB::table('inventory_lots')->count());self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));self::assertSame(2,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());self::assertSame(DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id'),DB::table('vouchers')->value('journal_entry_id'));
 }
}
