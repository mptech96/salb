<?php
namespace Tests\Integration\Uom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** HTTP rollback acceptance; parent retains original real void/cost1213 proof. */
class BatchTwoVoidSupportBoundaryTest extends BatchTwoExternalLockBoundaryTest
{
 private function voidToken(string $access='WRITE'):string{
  if(!DB::table('users')->where('id',99)->exists()){
   DB::table('users')->insert(['id'=>99,'name'=>'Isolated support','username'=>'void-support','password'=>'synthetic','company_id'=>null,'branch_id'=>null,'is_active'=>1]);
   DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'Isolated platform']);DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'company_id'=>null,'is_active'=>1]);
   DB::table('plans')->insert(['id'=>99,'plan_name'=>'Isolated','plan_code'=>'VOID-PROOF']);foreach([1,2]as$c)DB::table('subscriptions')->insert(['company_id'=>$c,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'purchases','is_enabled'=>1]);
  }
  $session=(string)\Illuminate\Support\Str::uuid();$token=User::findOrFail(99)->createToken('isolated-void',['session','support-mode','support-session:'.$session,'support-company:1','support-branch:1'],now()->addHour());
  DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>1,'branch_id'=>1,'personal_access_token_id'=>$token->accessToken->id,'access_level'=>$access,'capabilities_json'=>json_encode($access==='WRITE'?['POST:api/purchase-invoices/{id}/void']:[]),'reason'=>'Isolated void ownership proof','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);return $token->plainTextToken;
 }
 public function test_support_void_real_timeout_now_preserves_original_finance():void{
  $token=$this->voidToken();$plain=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$plain['invoice'],1,1);
  $this->app['auth']->forgetGuards();$this->withToken($this->voidToken('READ_ONLY'))->postJson('/api/purchase-invoices/'.$plain['invoice'].'/void',['reason'=>'Isolated read-only deny'])->assertForbidden();
  $this->app['auth']->forgetGuards();$this->withToken($token)->postJson('/api/purchase-invoices/'.$plain['invoice'].'/void',['reason'=>'Isolated successful void'])->assertOk();self::assertSame('VOID',DB::table('purchase_invoices')->where('id',$plain['invoice'])->value('document_status'));
  for($round=1;$round<=3;$round++){
   $s=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);$j=DB::table('purchase_invoices')->where('id',$s['invoice'])->value('journal_entry_id');
   if($round===1)fwrite(STDOUT,'COST_DRAFT_QUERY_PLAN '.json_encode(['plan'=>DB::select('EXPLAIN SELECT * FROM shipment_costs WHERE company_id=1 AND shipment_id=? AND cost_status="DRAFT" ORDER BY id FOR UPDATE',[$s['shipment']]),'indexes'=>DB::select('SHOW INDEX FROM shipment_costs')])."\n");
   DB::beginTransaction();DB::table('shipments')->where('id',$s['shipment'])->lockForUpdate()->first();
   $p=new Process([PHP_BINARY,base_path('scripts/batch_two_support_void_probe.php'),(string)$s['invoice'],$token],base_path());$p->setTimeout(15);$start=microtime(true);
   try{$p->run();}finally{DB::rollBack();if($p->isRunning())$p->stop();}
   self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1205',$p->getOutput());self::assertStringContainsString('"status":422',$p->getOutput());
   $invoice=DB::table('purchase_invoices')->where('id',$s['invoice'])->first();$cost=DB::table('shipment_costs')->where('id',$s['cost'])->first();$lot=DB::table('inventory_lots')->where('purchase_invoice_id',$s['invoice'])->first();
   // Same original real timeout schedule; the repaired owner must preserve finance.
   self::assertSame('POSTED',$invoice->document_status);self::assertNull($invoice->voided_at);self::assertNull(DB::table('journal_entries')->where('id',$j)->value('reversed_at'));self::assertSame('POSTED',$cost->cost_status);self::assertEquals(10,$lot->qty_remaining_kg);
   self::assertSame(1,DB::table('vouchers')->where('reference_type','SHIPMENT_COST')->where('reference_id',$s['cost'])->count());
   self::assertGreaterThanOrEqual(1,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','FAILED')->count());
   foreach(DB::table('journal_entries')->pluck('id')as$id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));
   fwrite(STDOUT,'SUPPORT_VOID_ROLLBACK '.json_encode(['round'=>$round,'elapsed_ms'=>(microtime(true)-$start)*1000,'worker'=>$p->getOutput(),'invoice_status'=>$invoice->document_status,'voided_at'=>$invoice->voided_at,'original_journal_reversed'=>false,'cost_status'=>$cost->cost_status,'lot_remaining'=>$lot->qty_remaining_kg])."\n");
  }
 }
}
