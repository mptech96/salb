<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Deliberately not an activation flag: normal application databases always fail. */
final class DriverSettlementIsolation
{
    public static function assert(): void
    {
        $c=DB::connection();$cfg=$c->getConfig();$dir=getenv('SULB_SHIPMENT_TEST_DIR');
        if(!app()->environment('testing') || $c->getDriverName()!=='mysql' || ($cfg['host']??'')!=='127.0.0.1'
            || (string)($cfg['port']??'')!=='3339' || !preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$cfg['database']??'')
            || !$dir || !preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$dir)) throw new \RuntimeException('Driver settlements are isolated-test only.');
        $actual=$c->selectOne('SELECT DATABASE() db, @@datadir directory');
        if($actual->db!==$cfg['database'] || rtrim(str_replace('\\','/',$actual->directory),'/')!==rtrim(str_replace('\\','/',$dir),'/')) throw new \RuntimeException('Disposable server ownership mismatch.');
        $guards=['scsr_insert','scsa_insert','scsr_update','scsr_delete','scsa_update','scsa_delete','scs_gl_insert','scs_gl_update','scs_gl_delete','scs_cost_update','scs_entry_update','scs_voucher_update'];
        if(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA',$cfg['database'])->whereIn('TRIGGER_NAME',$guards)->count()!==count($guards)) throw new \RuntimeException('Controlled-origin guards are not installed.');
    }
}
