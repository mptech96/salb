<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;

class ShipmentRollbackIntegrityTest extends StockMovementSourceIndexProofTest
{
 private function pristine(array $s):void
 {
  self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$s['invoice'])->value('document_status'));
  self::assertNull(DB::table('purchase_invoices')->where('id',$s['invoice'])->value('journal_entry_id'));
  foreach(['journal_entries','journal_entry_lines','inventory_lots','inventory_lot_movements','stock_movements','vouchers']as$table)self::assertSame(0,DB::table($table)->count(),$table);
  self::assertSame('READY',DB::table('shipments')->where('id',$s['shipment'])->value('commercial_status'));
  self::assertSame('DRAFT',DB::table('shipment_costs')->where('id',$s['cost'])->value('cost_status'));
  self::assertNull(DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id'));
  self::assertNull(DB::table('shipment_costs')->where('id',$s['cost'])->value('voucher_id'));
  self::assertEquals(1,DB::table('sulb_document_sequences')->where('document_type','INVENTORY_LOT')->where('company_id',1)->value('next_number'));
 }
 public function test_all_financial_fault_boundaries_and_failed_audit_after_confirmed_rollback():void
 {
  $token=$this->token();
  foreach(['fault-after-lot','fault-before-stock-link','fault-after-journal','fault-after-voucher','inject-1205','inject-1213','swallow-1205']as$phase){
   $s=F::purchase();$before=DB::table('audit_logs')->where('result','FAILED')->count();
   $p=$this->supportWorker($token,$s['invoice'],$phase);$p->wait();
   self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('HTTP 422',$p->getOutput());
   self::assertStringContainsString('AUDIT_WRITE_STATE {"level":0,"pdo":false,"server":0}',$p->getOutput());
   self::assertStringContainsString('HTTP_END_STATE {"status":422,"level":0,"pdo":false,"server":0}',$p->getOutput());
   self::assertStringContainsString('HTTP_PENDING_TRANSACTIONS 0',$p->getOutput());
   $this->pristine($s);self::assertSame($before+1,DB::table('audit_logs')->where('result','FAILED')->count());
   fwrite(STDOUT,'ROLLBACK_BOUNDARY '.json_encode(['phase'=>$phase,'trace'=>$p->getOutput()])."\n");
  }
 }
 public function test_failure_of_success_audit_cannot_leave_financial_effect():void
 {
  $s=F::purchase();$p=$this->supportWorker($this->token(),$s['invoice'],'fault-audit');$p->wait();
  self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('HTTP 500',$p->getOutput());
  $this->pristine($s);self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->count());
  $this->requestPost($this->token(),$s['invoice'])->assertOk();$this->financial($s);
 }
 public function test_validation_422_is_not_blanket_rollback_and_other_support_writes_still_work():void
 {
  $s=F::purchase();$p=$this->supportWorker($this->token(),$s['invoice'],'nonposting-422');$p->wait();
  self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('TRANSACTION_OWNER_NOT_ACCEPTED',$p->getOutput());
  self::assertStringContainsString('AUDIT_WRITE_STATE {"level":1,"pdo":true,"server":1}',$p->getOutput());$this->pristine($s);
  $token=$this->token('WRITE',['POST:api/company-settings']);$this->app['auth']->forgetGuards();
  $this->withToken($token)->postJson('/api/company-settings',['print_company_name'=>'Isolated unchanged write'])->assertOk();
  self::assertSame('Isolated unchanged write',DB::table('company_settings')->where('company_id',1)->value('print_company_name'));
  self::assertSame(1,DB::table('audit_logs')->where('result','SUCCESS')->count());
 }
}
