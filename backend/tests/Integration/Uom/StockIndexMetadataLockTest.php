<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class StockIndexMetadataLockTest extends \Tests\TestCase
{
 public function test_create_index_waits_for_existing_table_metadata_owner_and_fails_bounded():void{
  foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture']as$f)require_once __DIR__.'/../../Support/'.$f.'.php';
  \Tests\Support\UomStageAProof::guard();
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES')as$r)DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');\Tests\Support\ShipmentPurchaseLockFixture::install();
  DB::beginTransaction();DB::table('stock_movements')->count();
  $p=new Process([PHP_BINARY,base_path('scripts/isolated_stock_index_worker.php')],base_path());$p->setTimeout(15);$start=microtime(true);$p->start();
  try{$p->wait();self::assertSame(1,$p->getExitCode(),$p->getOutput());self::assertStringContainsString('1205',$p->getOutput());self::assertCount(0,DB::select('SHOW INDEX FROM stock_movements WHERE Key_name="idx_stock_company_source"'));}
  finally{DB::rollBack();if($p->isRunning())$p->stop();}
  $elapsed=(microtime(true)-$start)*1000;$migration=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$migration->up();self::assertCount(3,DB::select('SHOW INDEX FROM stock_movements WHERE Key_name="idx_stock_company_source"'));
  fwrite(STDOUT,'INDEX_METADATA_LOCK '.json_encode(['elapsed_ms'=>$elapsed,'worker'=>$p->getOutput(),'retry_after_release'=>'created'])."\n");
 }
}
