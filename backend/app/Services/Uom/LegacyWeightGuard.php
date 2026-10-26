<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Legacy scalar weight APIs must never interpret a BASE item as kilograms/tons. */
final class LegacyWeightGuard
{
    public static function item(int $company,int $item):void
    {
        if(DB::getSchemaBuilder()->hasColumn('items','inventory_quantity_version')
            && DB::table('items')->where('company_id',$company)->where('id',$item)->where('inventory_quantity_version','BASE_V1')->exists())
            throw new \RuntimeException('UOM_LEGACY_WEIGHT_ADAPTER_REQUIRED');
    }
}
