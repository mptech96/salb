<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;

/** Standalone disposable test schema/reader only. Not bound to operational application. */
final class UomStageAProof
{
    public static function guard():void
    {
        $c=DB::connection()->getConfig();if(app()->environment()!=='testing'||($c['host']??'')!=='127.0.0.1'||(string)($c['port']??'')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$c['database']??''))throw new \RuntimeException('Owned UOM MariaDB runner required');
        $actual=DB::selectOne('SELECT @@datadir d')->d;if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',(string)getenv('SULB_SHIPMENT_TEST_DIR')),'/'))throw new \RuntimeException('Wrong disposable datadir');
    }
    public static function install():void
    {
        self::guard();$sql=file_get_contents(base_path('../docs/erp-baseline/proposed-migrations/uom_stage_a_isolated.sql'));
        if(DB::getSchemaBuilder()->hasTable('uom_units'))throw new \RuntimeException('UOM schema already exists; no partial rerun');
        foreach(explode('-- statement',$sql) as $s){$s=trim($s);if(preg_match('/^(CREATE|ALTER)\s/i',$s))DB::unprepared($s);}
    }
    public static function quantity(int $company,int $branch,int $item):string
    {
        self::guard();$result=DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->where('item_id',$item)->selectRaw("COALESCE(SUM(CASE WHEN uom_version='LEGACY_KG' THEN qty_remaining_kg ELSE remaining_base_qty END),0) q")->first();return bcadd((string)$result->q,'0',6);
    }
    public static function legacyReaderGuard(int $company,int $branch):void
    {self::guard();if(DB::table('inventory_lots')->where('company_id',$company)->where('branch_id',$branch)->where('uom_version','BASE_V1')->exists())throw new \RuntimeException('Legacy reader unsupported: BASE_V1 requires unit-aware reader');}
    public static function rollbackPreflight():void
    {
        self::guard();foreach(['inventory_lots','inventory_lot_movements','purchase_invoice_lines','sales_invoice_lines'] as $t)if(DB::table($t)->where('uom_version','BASE_V1')->exists())throw new \RuntimeException('Schema rollback blocked: BASE_V1 evidence exists');
    }
}
