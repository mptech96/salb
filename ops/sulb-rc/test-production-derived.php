<?php
require_once __DIR__.'/production-derived.php';
function testProductionDerived(string $file):int {
    $a=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$db=\Illuminate\Support\Facades\DB::reconnect();$pdo=$db->getPdo();$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);\Tests\Support\UomStageAProof::guard();$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('NEW_DERIVED_CHECK_'.($n+1));$n++;};
    require_once __DIR__.'/trusted-release-application.php';$trusted=trustedReleaseApplication($pdo,'isolated-rehearsal');$db=\Illuminate\Support\Facades\DB::connection();$ok($db->getPdo()===$pdo);
    \Sulb\Release\ProductionUpgradeContext::begin('isolated-rehearsal',[],'');
    try{$first=applyProductionDerived($a,true);$second=applyProductionDerived($a,true);$ok($first===$second&&$first['derived_rows']===7);$ok($db->table('inventory_lot_value_balances')->where('company_id',4)->where('inventory_lot_id',90)->value('remaining_value')==='50.000');
    $bad=$a;$bad['company_plans'][4]['rows'][0]['inventory_lot_id']=3;$reject=false;try{applyProductionDerived($bad,true);}catch(RuntimeException $e){$reject=$e->getMessage()==='DERIVED_LOT_TENANT_SCOPE';}$ok($reject);
    $bad=$a;unset($bad['company_plans'][4]);$reject=false;try{applyProductionDerived($bad,true);}catch(RuntimeException $e){$reject=$e->getMessage()==='DERIVED_ALL_COMPANIES_REQUIRED';}$ok($reject);
    $original=$db->table('inventory_lots')->where('id',34)->value('total_cost');$db->table('inventory_lots')->where('id',34)->update(['total_cost'=>bcadd($original,'1',3)]);$reject=false;try{applyProductionDerived($a,true);}catch(RuntimeException $e){$reject=$e->getMessage()==='HISTORY_LEGACY_COLUMNS_CHANGED';}finally{$db->table('inventory_lots')->where('id',34)->update(['total_cost'=>$original]);}$ok($reject);
    // Only synthetic fixture balances are deleted to prove a late-company failure rolls back every insert.
    $db->table('inventory_lot_value_balances')->delete();$db->unprepared("CREATE TRIGGER qa_new_derived_abort BEFORE INSERT ON inventory_lot_value_balances FOR EACH ROW BEGIN IF NEW.company_id=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='EXPECTED_NEW_DERIVED_ROLLBACK'; END IF; END");$reject=false;try{applyProductionDerived($a,true);}catch(\Illuminate\Database\QueryException $e){$reject=(int)($e->errorInfo[1]??0)===1644;}finally{$db->unprepared('DROP TRIGGER qa_new_derived_abort');}$ok($reject&&$db->table('inventory_lot_value_balances')->count()===0);
    $inserted=applyProductionDerived($a,true);$again=applyProductionDerived($a,true);$ok($inserted['derived_rows']===7&&$again['derived_rows']===7&&$db->table('inventory_lot_value_balances')->count()===7);$ok($db->table('items')->where('company_id',3)->whereIn('id',[1,39])->whereNull('inventory_account_id')->count()===2);verifyDerivedSource($pdo,$a,true);$ok(true);
    }finally{\Sulb\Release\ProductionUpgradeContext::end();}
    echo 'PASS '.$n.' new derived executor integration checks; synthetic_only=true; production_connected=false'.PHP_EOL;return$n;
}
