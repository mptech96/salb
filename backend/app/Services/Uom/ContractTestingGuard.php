<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
/** Temporary contract writes are explicitly restricted to the owned isolated acceptance DB. */
final class ContractTestingGuard
{
 public static function assert():void{
  $c=DB::connection()->getConfig();
  if(!app()->environment('testing')||config('uom.contract_testing_enabled',false)!==true||($c['host']??'')!=='127.0.0.1'||(string)($c['port']??'')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$c['database']??''))throw new \RuntimeException('UOM_CONTRACT_NOT_ACTIVATED');
  $dir=(string)DB::selectOne('SELECT @@datadir AS d')->d;$expected=(string)getenv('SULB_SHIPMENT_TEST_DIR');if(!$expected||rtrim(str_replace('\\','/',$dir),'/')!==rtrim(str_replace('\\','/',$expected),'/'))throw new \RuntimeException('UOM_CONTRACT_WRONG_DATADIR');
 }
}
