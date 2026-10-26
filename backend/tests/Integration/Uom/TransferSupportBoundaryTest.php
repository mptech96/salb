<?php
namespace Tests\Integration\Uom;

use App\Services\EnterpriseInvoiceService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\UomOperationalLockFixture as F;

require_once __DIR__.'/ReleaseTransferLockAcceptanceTest.php';
final class TransferSupportBoundaryTest extends ReleaseTransferLockAcceptanceTest
{
    private function token(string $access='WRITE',bool $capability=true,int $company=1): string
    {
        if(!DB::table('users')->where('id',99)->exists()){
            DB::table('users')->insert(['id'=>99,'name'=>'Synthetic support','username'=>'transfer-support','password'=>'synthetic','company_id'=>null,'branch_id'=>null,'is_active'=>1]);
            DB::table('roles')->insert(['id'=>99,'role_code'=>'SUPER_ADMIN','role_name'=>'Synthetic platform']);DB::table('user_roles')->insert(['user_id'=>99,'role_id'=>99,'company_id'=>null,'is_active'=>1]);
            DB::table('plans')->insert(['id'=>99,'plan_name'=>'Synthetic','plan_code'=>'TRANSFER-PROOF']);
            foreach([1,2] as $id)DB::table('subscriptions')->insert(['company_id'=>$id,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
            foreach(['inventory','processing'] as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
        }
        $session=(string)\Illuminate\Support\Str::uuid();
        $token=\App\Models\User::findOrFail(99)->createToken('isolated-transfer',['session','support-mode','support-session:'.$session,'support-company:'.$company,'support-branch:'.$company],now()->addHour());
        DB::table('support_sessions')->insert(['support_session_id'=>$session,'platform_user_id'=>99,'company_id'=>$company,'branch_id'=>$company,'personal_access_token_id'=>$token->accessToken->id,
            'access_level'=>$access,'capabilities_json'=>json_encode($capability?['POST:api/inventory-operations/{id}/approve']:[]),'reason'=>'Isolated transfer acceptance','ticket_reference'=>'LOCAL','status'=>'ACTIVE','started_at'=>now(),'expires_at'=>now()->addHour()]);
        return $token->plainTextToken;
    }
    private function state(): string
    {
        $tables=[];foreach(['inventory_operations','inventory_operation_lines','inventory_operation_lot_links','inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$tables[$table]=DB::table($table)->orderBy('id')->get();
        return hash('sha256',serialize($tables));
    }
    private function probe(int $id,string $token,string $phase=''): Process
    {
        $p=new Process([PHP_BINARY,base_path('scripts/release_transfer_support_probe.php'),(string)$id,$token,$phase],base_path());$p->setTimeout(20);$p->start();return $p;
    }
    public function test_transfer_support_success_denials_and_posted_duplicate(): void
    {
        $purchase=F::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);$id=$this->operation();
        foreach([[$this->token('READ_ONLY'),403],[$this->token('WRITE',false),403],[$this->token('WRITE',true,2),404]] as [$token,$status]){
            $before=$this->state();$p=$this->probe($id,$token);$p->wait();self::assertStringContainsString('"status":'.$status,$p->getOutput());self::assertSame($before,$this->state());
        }
        $token=$this->token();$p=$this->probe($id,$token);$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput());
        self::assertStringContainsString('"level":0,"pdo":false',$p->getOutput());$before=$this->state();
        $duplicate=$this->probe($id,$token);$duplicate->wait();self::assertSame(1,$duplicate->getExitCode());self::assertSame($before,$this->state());
        self::assertSame(1,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
    }
    public function test_transfer_support_1205_swallow_late_failure_and_audit_failure_are_atomic(): void
    {
        $purchase=F::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);$id=$this->operation();$token=$this->token();
        foreach(['timeout','swallow','late_failure','audit'] as $phase){
            $before=$this->state();$held=in_array($phase,['timeout','swallow'],true);
            if($held){DB::beginTransaction();\App\Services\InventoryPostingLocks::reserveLotScope(1,'2026-10-14');}
            try{$p=$this->probe($id,$token,$phase);$p->wait();}finally{if($held)DB::rollBack();}
            self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertSame($before,$this->state(),$phase);
            self::assertStringContainsString('"status":'.($phase==='audit'?500:422),$p->getOutput());self::assertStringContainsString('"level":0,"pdo":false',$p->getOutput());
            self::assertStringNotContainsString('SQLSTATE[',$p->getOutput());
            self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
        }
    }
    public function test_transfer_refuses_unknown_outer_and_restores_next_transaction_isolation(): void
    {
        $purchase=F::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);$id=$this->operation();
        DB::beginTransaction();$before=$this->state();
        try{app(\App\Services\InventoryOperationService::class)->approve(1,$id,1,1);self::fail('Unknown outer adopted');}
        catch(\RuntimeException $error){self::assertSame('TRANSFER_OUTERMOST_REQUIRED',$error->getMessage());self::assertTrue(DB::connection()->getPdo()->inTransaction());self::assertSame($before,$this->state());}
        finally{DB::rollBack();}
        app(\App\Services\InventoryOperationService::class)->approve(1,$id,1,1);
        $peer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        DB::beginTransaction();try{$old=DB::table('items')->where('id',1)->value('item_name');$peer->exec("UPDATE items SET item_name='peer change' WHERE id=1");self::assertSame($old,DB::table('items')->where('id',1)->value('item_name'));}finally{DB::rollBack();}
    }

    public function test_transfer_support_actual_deadlock_restores_full_state_and_has_no_success_audit():void
    {
        $purchase=F::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);$id=$this->operation();$token=$this->token();$before=$this->state();$worker=null;
        DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();
        $weight=[];for($n=0;$n<500;$n++)$weight[]=['company_id'=>1,'item_code'=>'TRANSFER-VICTIM-'.$n,'item_name'=>'Synthetic deadlock weight'];DB::table('items')->insert($weight);
        try{
            $worker=$this->probe($id,$token,'real1213');$this->await($worker,'TRANSFER_REQUEST_ITEM');
            DB::table('inventory_operations')->where('id',$id)->lockForUpdate()->first();
        }finally{DB::rollBack();if($worker)$worker->wait();}
        self::assertSame(1,$worker->getExitCode(),$worker->getOutput());self::assertStringContainsString('1213',$worker->getOutput());
        self::assertStringContainsString('"status":422',$worker->getOutput());self::assertStringContainsString('"level":0,"pdo":false',$worker->getOutput());
        self::assertSame($before,$this->state());self::assertSame(0,DB::table('audit_logs')->where('action_type','SUPPORT_WRITE')->where('result','SUCCESS')->count());
    }
}
