<?php
namespace App\Services\Accounting;
use Illuminate\Support\Facades\DB;
class InventoryCostService
{
    public function averageInboundCost(int $companyId,int $branchId,int $itemId,?int $carId=null): float
    {
        $q=DB::table('stock_movements')->where('company_id',$companyId)->where('branch_id',$branchId)->where('item_id',$itemId)->where('movement_type','IN');
        if($carId)$q->where('car_id',$carId);else $q->whereNull('car_id');
        $r=$q->selectRaw('COALESCE(SUM(total_cost),0) total_cost, COALESCE(SUM(qty),0) qty')->first();
        $qty=(float)($r->qty??0);return $qty>0?round((float)$r->total_cost/$qty,3):0.0;
    }
}
