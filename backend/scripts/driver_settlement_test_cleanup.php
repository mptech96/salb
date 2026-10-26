<?php
/** Test fixture cleanup only. Never callable against a normal database. */
function driverSettlementTestCleanup(): void
{
    $cfg=\Illuminate\Support\Facades\DB::connection()->getConfig();
    if(($cfg['host']??'')!=='127.0.0.1'||(string)($cfg['port']??'')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$cfg['database']??''))throw new \RuntimeException('Owned disposable fixture required');
    $actual=\Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() db, @@datadir directory');$directory=getenv('SULB_SHIPMENT_TEST_DIR');
    if(!$directory||$actual->db!==$cfg['database']||rtrim(str_replace('\\','/',$directory),'/')!==rtrim(str_replace('\\','/',$actual->directory),'/'))throw new \RuntimeException('Disposable datadir mismatch');
    foreach(\Illuminate\Support\Facades\DB::select('SHOW TRIGGERS') as $trigger){if(str_starts_with($trigger->Trigger,'scs'))\Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER `'.$trigger->Trigger.'`');}
    \Illuminate\Support\Facades\Schema::dropIfExists('shipment_cost_settlement_allocations');
    \Illuminate\Support\Facades\Schema::dropIfExists('shipment_cost_settlement_requests');
}
