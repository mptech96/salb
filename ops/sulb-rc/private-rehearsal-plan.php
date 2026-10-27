<?php
/** The exact reviewed schema-only schedule. Never a production executor. */
function privateRehearsalPlan(string $root):array {
    $catalog=json_decode(file_get_contents($root.'/ops/sulb-rc/migration-catalog.json'),true,512,JSON_THROW_ON_ERROR);
    $uom=$catalog['uom_order'];$proposed=array_column($catalog['proposed'],null,'path');$plan=[];
    if(count($uom)!==14)throw new RuntimeException('MIGRATION_ORDER_INCOMPLETE');
    foreach($uom as$i=>$m){
        if($m['sequence']!==$i+1)throw new RuntimeException('MIGRATION_ORDER_INCOMPLETE');
        if($i===9)foreach(['stock_movements_company_source_index.php','shipment_cost_parent_fk.php','shipment_cost_allocation_receipts.php','inventory_lot_value_balances.php']as$name){
            $path='docs/erp-baseline/proposed-migrations/'.$name;
            if(!isset($proposed[$path]))throw new RuntimeException('DEPENDENCY_CATALOG_MISSING');
            $plan[]=$proposed[$path]+['step'=>'dependency:'.$name];
        }
        $plan[]=$m+['step'=>'uom:'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT)];
    }
    foreach($plan as$m){
        $source=file_get_contents($root.'/'.$m['path']);
        if(!hash_equals($m['sha256'],hash('sha256',str_replace("\r\n","\n",$source))))throw new RuntimeException('REHEARSAL_MIGRATION_HASH_MISMATCH');
    }
    return $plan;
}
