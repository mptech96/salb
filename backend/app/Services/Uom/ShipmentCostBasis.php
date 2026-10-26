<?php
namespace App\Services\Uom;
use App\Services\ShipmentLargestRemainder;

/** Approved mixed-dimension policy: money or explicitly authorized percentages. */
final class ShipmentCostBasis
{
    public function plan(string $amount,string $method,array $lines,bool $percentAuthorized=false):array
    {
        if(!in_array($method,['RELATIVE_VALUE','MANUAL_PERCENT'],true))throw new \RuntimeException('UOM_SHIPMENT_COST_BASIS: اختر قيمة البنود أو نسب توزيع معتمدة؛ لا يستخدم وزن مفترض.');
        if(!$lines)throw new \RuntimeException('UOM_SHIPMENT_COST_LINES');
        $rows=[];$sum='0';$math=new ShipmentLargestRemainder;
        if($method==='MANUAL_PERCENT'&&!$percentAuthorized)throw new \RuntimeException('UOM_SHIPMENT_PERCENT_PERMISSION');
        foreach($lines as $id=>$line){
            $basis=$math->decimal((string)($method==='MANUAL_PERCENT'?($line['cost_share_percent']??''):($line['base_value']??'')),6);
            if($method==='MANUAL_PERCENT'&&bccomp($basis,'100',6)>0)throw new \RuntimeException('UOM_SHIPMENT_PERCENT_RANGE');
            $sum=bcadd($sum,$basis,6);$rows[$id]=['basis'=>$basis,'item_code'=>(string)$line['item_code']];
        }
        if($method==='MANUAL_PERCENT'&&bccomp($sum,'100',6)!==0)throw new \RuntimeException('UOM_SHIPMENT_PERCENT_SUM');
        return ['method'=>$method,'amount'=>$math->decimal($amount),'allocations'=>$math->allocate($amount,$rows)];
    }
}
