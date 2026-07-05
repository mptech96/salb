<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class ShipmentCostDistributionService
{
    public function distribute(Shipment $shipment): array
    {
        return DB::transaction(function () use ($shipment) {

            if ($shipment->status !== 'APPROVED') {
                throw new \Exception('لا يمكن توزيع تكلفة حمولة غير معتمدة');
            }

            $items = $shipment->items()->get();

            if ($items->count() === 0) {
                throw new \Exception('لا توجد أصناف في الحمولة');
            }

            $totalNetWeight = (float) $items->sum('net_weight');

            if ($totalNetWeight <= 0) {
                throw new \Exception('إجمالي وزن الحمولة غير صحيح');
            }

            $totalExtraCosts = (float) DB::table('shipment_costs')
                ->where('company_id', $shipment->company_id)
                ->where('shipment_id', $shipment->id)
                ->sum('amount');

            $costPerTon = round($totalExtraCosts / $totalNetWeight, 3);

            foreach ($items as $item) {
                $netWeight = (float) $item->net_weight;

                $extraCost = round($netWeight * $costPerTon, 3);

                $purchaseCost = round($netWeight * (float) $item->unit_price, 3);

                $finalCost = round($purchaseCost + $extraCost, 3);

                $finalUnitCost = $netWeight > 0
                    ? round($finalCost / $netWeight, 3)
                    : 0;

                $item->update([
                    'extra_cost' => $extraCost,
                    'final_cost' => $finalCost,
                    'distributed_cost' => $extraCost,
                    'average_cost' => $finalUnitCost,
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')
                    ->where('company_id', $shipment->company_id)
                    ->where('shipment_item_id', $item->id)
                    ->where('movement_type', 'IN')
                    ->where('source_type', 'SHIPMENT')
                    ->update([
                        'unit_cost' => $finalUnitCost,
                        'total_cost' => $finalCost,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('shipment_costs')
                ->where('company_id', $shipment->company_id)
                ->where('shipment_id', $shipment->id)
                ->update([
                    'distributed' => 1,
                    'updated_at' => now(),
                ]);

            $shipment->update([
                'distributed_cost' => $totalExtraCosts,
                'updated_at' => now(),
            ]);

            return [
                'shipment_id' => $shipment->id,
                'total_weight' => $totalNetWeight,
                'total_extra_costs' => $totalExtraCosts,
                'cost_per_ton' => $costPerTon,
            ];
        });
    }
}