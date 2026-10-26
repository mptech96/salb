<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
/** Counterexample to deployment safety, not a successful financial E2E test. */
class UomLockOrderTest extends \Tests\TestCase
{
 public function test_actual_item_year_lock_inversion_is_a_blocker():void{
  require_once __DIR__.'/../../Support/UomStageAProof.php';\Tests\Support\UomStageAProof::guard();
  // Other UOM tests deliberately alter key signedness. Never reload a partial
  // dump over that leftover schema: FK type validation still applies.
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $r){$t=array_values((array)$r)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');
  DB::unprepared(file_get_contents(base_path('database/schema/mysql-schema.sql')));
  DB::table('companies')->insert(['id'=>1,'company_name'=>'Isolated']);DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Test']);DB::table('items')->insert(['id'=>1,'company_id'=>1,'item_name'=>'Test','item_code'=>'T']);DB::table('users')->insert(['company_id'=>1,'branch_id'=>1,'name'=>'Test','username'=>'uom-test','password'=>'synthetic']);DB::table('financial_years')->insert(['id'=>1,'company_id'=>1,'year_name'=>'Test','start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
  DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/uom_contract_race_worker.php'),'fy-then-item','unused','unused','unused'],base_path());$p->setTimeout(15);$p->start();$deadlock=false;
  try{$until=microtime(true)+8;while(!str_contains($p->getOutput(),'FY_LOCKED')&&microtime(true)<$until)usleep(10000);self::assertStringContainsString('FY_LOCKED',$p->getOutput());
   try{DB::table('financial_years')->where('id',1)->lockForUpdate()->first();}catch(\Illuminate\Database\QueryException $e){$deadlock=($e->errorInfo[1]??null)===1213;}
  }finally{if(DB::transactionLevel()>0)DB::rollBack();}
  $p->wait();$deadlock=$deadlock||str_contains($p->getOutput(),'1213');self::assertTrue($deadlock,'Lock protocol cannot be claimed globally safe');self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('stock_movements')->count());
 }
}
