<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;
/** Regression of the formerly passing partial-commit counterexample. */
class BatchTwoSaleSupportBoundaryTest extends \Tests\TestCase
{
 protected function setUp():void{
  parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture']as$f)require_once __DIR__.'/../../Support/'.$f.'.php';\Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES')as$r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();$m=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$m->up();
 }
 private function snapshot():string{
  $rows=[];foreach(['sales_invoices','sales_invoice_lines','sales_line_lot_sources','purchase_invoices','shipment_costs','shipments','shipment_items','journal_entries','journal_entry_lines','inventory_lots','inventory_lot_movements','stock_movements','vouchers','sulb_document_sequences','financial_years']as$table)$rows[$table]=DB::table($table)->orderBy('id')->get();return json_encode($rows);
 }
 private function denied(string $token,int $id,int $status):void{
  $before=$this->snapshot();$p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_sale_probe.php'),(string)$id,$token],base_path());$p->setTimeout(20);$p->run();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('"status":'.$status,$p->getOutput());self::assertSame($before,$this->snapshot());
 }
 public function test_actual_support_sale_timeout_restores_finance_and_retries():void{
  foreach([16=>['ASSET','DEBIT'],17=>['REVENUE','CREDIT'],18=>['EXPENSE','DEBIT']]as$id=>[$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'SALE-'.$id,'account_name'=>'Synthetic sale account','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1,'allow_cost_center'=>0]);
  foreach(['CUSTOMER_ACCOUNT'=>16,'SALES_ACCOUNT'=>17,'COGS_ACCOUNT'=>18]as$key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Synthetic customer','ledger_account_id'=>16,'scope_all_branches'=>1]);
  DB::table('users')->insert(['id'=>99,'name'=>'Isolated support','username'=>'sale-boundary','password'=>'synthetic','company_id'=>null,'branch_id'=>null,'is_active'=>1]);DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'Platform']);DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'company_id'=>null,'is_active'=>1]);DB::table('plans')->insert(['id'=>99,'plan_name'=>'Isolated','plan_code'=>'SALE-PROOF']);DB::table('subscriptions')->insert(['company_id'=>1,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'sales','is_enabled'=>1]);
  $session=(string)\Illuminate\Support\Str::uuid();$t=\App\Models\User::findOrFail(99)->createToken('isolated-sale',['session','support-mode','support-session:'.$session,'support-company:1','support-branch:1'],now()->addHour());DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>1,'branch_id'=>1,'personal_access_token_id'=>$t->accessToken->id,'access_level'=>'WRITE','capabilities_json'=>json_encode(['POST:api/sales-invoices/{id}/post']),'reason'=>'Isolated sale boundary proof','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);
  for($round=1;$round<=9;$round++){
   $source=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$source['invoice'],1,1);
   $h=DB::table('shipments')->insertGetId(['company_id'=>1,'branch_id'=>1,'shipment_type'=>'SALE','customer_id'=>1,'shipment_number'=>'SALE-PROOF-'.$round,'shipment_date'=>'2026-10-14','commercial_status'=>'READY','currency_code'=>'SAR']);
   $si=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$h,'item_id'=>1,'qty_kg'=>5,'accepted_qty_kg'=>5,'remaining_qty_kg'=>5,'unit_price_per_kg'=>20]);
   $id=DB::table('sales_invoices')->insertGetId(['company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'SALE-PROOF-'.$round,'invoice_date'=>'2026-10-14','currency_code'=>'SAR','exchange_rate'=>1,'total_before_vat'=>100,'total_amount'=>100,'base_total_before_vat'=>100,'base_total_amount'=>100,'created_by'=>1]);DB::table('sales_invoice_lines')->insert(['company_id'=>1,'sales_invoice_id'=>$id,'item_id'=>1,'shipment_id'=>$h,'shipment_item_id'=>$si,'qty_kg'=>5,'quantity'=>5,'unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>20,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'SALE','invoice_id'=>$id,'shipment_id'=>$h,'allocated_qty_kg'=>5,'allocated_amount'=>100]);
   if($round===1){
    DB::table('support_sessions')->where('support_session_id',$session)->update(['access_level'=>'READ_ONLY']);$this->denied($t->plainTextToken,$id,403);
    DB::table('support_sessions')->where('support_session_id',$session)->update(['access_level'=>'WRITE','capabilities_json'=>'[]']);$this->denied($t->plainTextToken,$id,403);
    DB::table('support_sessions')->where('support_session_id',$session)->update(['capabilities_json'=>json_encode(['POST:api/sales-invoices/{id}/post'])]);
    DB::table('subscriptions')->where('company_id',1)->update(['status'=>'EXPIRED','end_date'=>now()->subMonth()->toDateString()]);$this->denied($t->plainTextToken,$id,403);
    DB::table('subscriptions')->where('company_id',1)->update(['status'=>'ACTIVE','end_date'=>now()->addYear()->toDateString()]);
    $pdo=DB::connection()->getPdo();$pdo->beginTransaction();
    try{try{app(\App\Services\ShipmentTransactionOwner::class)->run(1,$id,99,[$h],fn()=>self::fail('Foreign PDO callback ran'),'SALE_POST');self::fail('Foreign PDO accepted');}catch(\RuntimeException $e){self::assertSame('SHIPMENT_POST_OUTERMOST_REQUIRED',$e->getMessage());}self::assertTrue($pdo->inTransaction());}finally{$pdo->rollBack();}
    DB::beginTransaction();try{try{app(\App\Services\EnterpriseInvoiceService::class)->post('SALE',1,$id,99,1);self::fail('Untrusted outer accepted');}catch(\RuntimeException $e){self::assertSame('SALE_POST_OUTERMOST_REQUIRED',$e->getMessage());}}finally{DB::rollBack();}
   }
   $phase=$round<=3?'':(['1205','1213','swallow','reject','audit','real1213'][$round-4]);
   $before=$this->snapshot();$success=DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count();
   if($phase===''||$phase==='real1213'){
    DB::beginTransaction();if($phase==='real1213')DB::table('items')->where('id',1)->lockForUpdate()->first();else DB::table('shipments')->where('id',$h)->lockForUpdate()->first();
    if($phase==='real1213'){$weight=[];for($i=0;$i<500;$i++)$weight[]=['company_id'=>1,'item_code'=>'SALE-VICTIM-'.$i,'item_name'=>'Synthetic deadlock weight'];DB::table('items')->insert($weight);}
   }
   $p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_sale_probe.php'),(string)$id,$t->plainTextToken,$phase],base_path());$p->setTimeout(20);
   try{
    $p->start();
    if($phase==='real1213'){
     $until=microtime(true)+10;while(!str_contains($p->getOutput(),'SALE_REQUEST_ITEM_LOCK')){if(!$p->isRunning()||microtime(true)>$until)throw new \RuntimeException('SALE barrier missing: '.$p->getOutput());usleep(10000);}
     DB::table('shipments')->where('id',$h)->lockForUpdate()->first();
    }
    $p->wait();
   }finally{if(DB::transactionLevel()>0)DB::rollBack();if($p->isRunning())$p->stop();}
   self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString($phase==='audit'?'"status":500':'"status":422',$p->getOutput());
   if($phase===''||$phase==='1205'||$phase==='swallow')self::assertStringContainsString('1205',$p->getOutput());
   if($phase==='1213'||$phase==='real1213')self::assertStringContainsString('1213',$p->getOutput());
   self::assertStringNotContainsString('Database:',$p->getOutput());
   self::assertStringNotContainsString('SQLSTATE[',$p->getOutput());
   self::assertSame($before,$this->snapshot(),$p->getOutput());
   self::assertSame($success,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
   self::assertStringContainsString('"level":0,"pdo":false,"server":0',$p->getOutput());
   if($phase!=='audit')self::assertStringContainsString('SALE_AUDIT_STATE {"level":0,"pdo":false,"server":0}',$p->getOutput());
   fwrite(STDOUT,'SALE_ROLLBACK '.json_encode(['round'=>$round,'phase'=>$phase?:'real1205','worker'=>$p->getOutput(),'snapshot'=>'identical'])."\n");
   $p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_sale_probe.php'),(string)$id,$t->plainTextToken],base_path());$p->setTimeout(20);$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput());
   self::assertSame('POSTED',DB::table('sales_invoices')->where('id',$id)->value('document_status'));self::assertSame('INVOICED',DB::table('shipments')->where('id',$h)->value('commercial_status'));
   self::assertSame($success+1,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
   self::assertEquals(5,DB::table('sales_line_lot_sources')->whereIn('sales_invoice_line_id',DB::table('sales_invoice_lines')->where('sales_invoice_id',$id)->pluck('id'))->sum('qty_kg'));
   foreach(DB::table('journal_entries')->pluck('id')as$j){$totals=DB::table('journal_entry_lines')->where('journal_entry_id',$j)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertEquals($totals->d,$totals->c);}
   $posted=$this->snapshot();$p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_sale_probe.php'),(string)$id,$t->plainTextToken],base_path());$p->setTimeout(20);$p->run();self::assertSame(0,$p->getExitCode(),$p->getOutput());self::assertSame($posted,$this->snapshot());
  }
 }
}
