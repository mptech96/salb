<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Exact mutable value balance; does not choose FIFO order or create GL. */
final class InventoryLotValue
{
    public static function assertWritableEntry():void
    {
        if(config('inventory_value.enabled'))self::ready();
    }
    public static function sqlValue(string $alias): string
    {
        if(!preg_match('/^[a-z_][a-z0-9_]*$/D',$alias))throw new \InvalidArgumentException('Invalid internal LOT alias');
        $legacy="CASE WHEN $alias.qty_received_kg>0 THEN $alias.total_cost*($alias.qty_remaining_kg/$alias.qty_received_kg) ELSE 0 END";
        $base=DB::getSchemaBuilder()->hasColumn('inventory_lots','uom_remaining_value');
        if(!config('inventory_value.enabled'))return $base?"CASE WHEN $alias.uom_version='BASE_V1' THEN $alias.uom_remaining_value ELSE $legacy END":$legacy;
        if(!DB::getSchemaBuilder()->hasTable('inventory_lot_value_balances'))throw new \RuntimeException('LOT_VALUE_SCHEMA_REQUIRED');
        $value="COALESCE((SELECT lv.remaining_value FROM inventory_lot_value_balances lv WHERE lv.inventory_lot_id=$alias.id AND lv.company_id=$alias.company_id),$legacy)";
        return $base?"CASE WHEN $alias.uom_version='BASE_V1' THEN $alias.uom_remaining_value ELSE $value END":$value;
    }
    private static function money(string $value): string
    {
        return (new ShipmentLargestRemainder)->decimal($value,3);
    }
    private static function ready(): void
    {
        if(DB::transactionLevel()<1||!DB::connection()->getPdo()->inTransaction())throw new \RuntimeException('LOT_VALUE_ROOT_REQUIRED');
        if(!DB::getSchemaBuilder()->hasTable('inventory_lot_value_balances'))throw new \RuntimeException('LOT_VALUE_SCHEMA_REQUIRED');
    }
    public static function create(int $company,int $lot,string $quantity,string $value): void
    {
        if(!config('inventory_value.enabled'))return;
        self::ready();$quantity=self::money($quantity);$value=self::money($value);
        DB::table('inventory_lot_value_balances')->insert(['company_id'=>$company,'inventory_lot_id'=>$lot,'original_quantity'=>$quantity,
            'remaining_quantity'=>$quantity,'original_value'=>$value,'remaining_value'=>$value,'policy_version'=>'FINAL_RESIDUAL_V1','created_at'=>now(),'updated_at'=>now()]);
    }
    private static function balance(object $lot): object
    {
        self::ready();
        $balance=DB::table('inventory_lot_value_balances')->where('company_id',$lot->company_id)->where('inventory_lot_id',$lot->id)->lockForUpdate()->first();
        // Never infer an unreviewed historical remaining value from rounded units.
        if(!$balance)throw new \RuntimeException('LOT_VALUE_HISTORY_REVIEW_REQUIRED');
        if($balance->policy_version!=='FINAL_RESIDUAL_V1'||bccomp((string)$lot->qty_remaining_kg,$balance->remaining_quantity,3)!==0
            ||bccomp((string)$lot->total_cost,$balance->original_value,3)!==0)throw new \RuntimeException('LOT_VALUE_BALANCE_MISMATCH');
        return $balance;
    }
    public static function issue(object $lot,string $quantity): string
    {
        $balance=self::balance($lot);$quantity=self::money($quantity);
        if(bccomp($quantity,'0',3)<=0||bccomp($quantity,$balance->remaining_quantity,3)>0)throw new \RuntimeException('LOT_VALUE_SHORTAGE');
        $full=bccomp($quantity,$balance->remaining_quantity,3)===0;
        $value=$full?$balance->remaining_value:bcadd(bcmul($quantity,(string)$lot->unit_cost_per_kg,12),'0.0005',3);
        if(bccomp($value,$balance->remaining_value,3)>0)throw new \RuntimeException('LOT_VALUE_ROUNDING_REVIEW');
        self::write($balance,bcsub($balance->remaining_quantity,$quantity,3),bcsub($balance->remaining_value,$value,3));
        return $value;
    }
    public static function restore(object $lot,string $quantity,string $value): void
    {
        if(!config('inventory_value.enabled'))return;
        $balance=self::balance($lot);$quantity=self::money($quantity);$value=self::money($value);
        $nextQuantity=bcadd($balance->remaining_quantity,$quantity,3);$nextValue=bcadd($balance->remaining_value,$value,3);
        if(bccomp($nextQuantity,$balance->original_quantity,3)>0||bccomp($nextValue,$balance->original_value,3)>0)throw new \RuntimeException('LOT_VALUE_RESTORE_EXCEEDS_SOURCE');
        self::write($balance,$nextQuantity,$nextValue);
    }
    public static function removeExact(object $lot,string $quantity,string $value): void
    {
        if(!config('inventory_value.enabled'))return;
        $balance=self::balance($lot);$quantity=self::money($quantity);$value=self::money($value);
        if(bccomp($quantity,$balance->remaining_quantity,3)>0||bccomp($value,$balance->remaining_value,3)>0)throw new \RuntimeException('LOT_VALUE_REVERSAL_EXCEEDS_BALANCE');
        $remainingQuantity=bcsub($balance->remaining_quantity,$quantity,3);$remainingValue=bcsub($balance->remaining_value,$value,3);
        if(bccomp($remainingQuantity,'0',3)===0&&bccomp($remainingValue,'0',3)!==0)throw new \RuntimeException('LOT_VALUE_ZERO_QUANTITY_WITH_VALUE');
        self::write($balance,$remainingQuantity,$remainingValue);
    }
    public static function capitalize(object $lot,string $value): void
    {
        if(!config('inventory_value.enabled'))return;
        $balance=self::balance($lot);$value=self::money($value);
        if(bccomp($balance->remaining_quantity,$balance->original_quantity,3)!==0)throw new \RuntimeException('LOT_VALUE_ALREADY_ISSUED');
        DB::table('inventory_lot_value_balances')->where('id',$balance->id)->update(['original_value'=>bcadd($balance->original_value,$value,3),
            'remaining_value'=>bcadd($balance->remaining_value,$value,3),'updated_at'=>now()]);
    }
    private static function write(object $balance,string $quantity,string $value): void
    {
        DB::table('inventory_lot_value_balances')->where('id',$balance->id)->update(['remaining_quantity'=>$quantity,'remaining_value'=>$value,'updated_at'=>now()]);
    }
}
