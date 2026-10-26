<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Internal SQL only. Quantities may be aggregated per item/unit, never across dimensions. */
final class InventoryQuantitySql
{
    public static function available():bool
    { return DB::getSchemaBuilder()->hasColumn('inventory_lots','remaining_base_qty'); }

    public static function lot(string $alias,string $kind='remaining'):string
    {
        if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$alias)||!in_array($kind,['remaining','received','sold'],true))throw new \InvalidArgumentException('Invalid internal quantity expression');
        $legacy=['remaining'=>'qty_remaining_kg','received'=>'qty_received_kg','sold'=>'qty_sold_kg'][$kind];
        if(!self::available())return "$alias.$legacy";
        $base=['remaining'=>'remaining_base_qty','received'=>'received_base_qty','sold'=>'sold_base_qty'][$kind];
        return "CASE WHEN $alias.uom_version='BASE_V1' THEN $alias.$base ELSE $alias.$legacy END";
    }

    public static function movement(string $alias):string
    {
        if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$alias))throw new \InvalidArgumentException('Invalid internal movement alias');
        return self::available()?"CASE WHEN $alias.uom_version='BASE_V1' THEN $alias.base_quantity ELSE $alias.qty_kg END":"$alias.qty_kg";
    }
}
