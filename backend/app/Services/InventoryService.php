<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function stockIn(array $data): int
    {
        $qty = round((float)($data['qty'] ?? 0), 3);
        $unitCost = round((float)($data['unit_cost'] ?? 0), 3);
        $totalCost = round((float)($data['total_cost'] ?? ($qty * $unitCost)), 3);

        if ($qty <= 0) {
            throw new \Exception('كمية الإدخال للمخزون غير صحيحة');
        }

        return DB::table('stock_movements')->insertGetId([
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'item_id' => $data['item_id'],
            'car_id' => $data['car_id'] ?? null,
            'shipment_item_id' => $data['shipment_item_id'] ?? null,

            'movement_type' => 'IN',
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'movement_date' => $data['movement_date'] ?? date('Y-m-d'),

            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,

            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function stockOut(array $data): int
    {
        $qty = round((float)($data['qty'] ?? 0), 3);

        if ($qty <= 0) {
            throw new \Exception('كمية الإخراج من المخزون غير صحيحة');
        }

        $available = $this->availableQty(
            (int)$data['company_id'],
            (int)$data['item_id'],
            $data['car_id'] ?? null,
            $data['shipment_item_id'] ?? null
        );

        if ($available < $qty) {
            throw new \Exception('الكمية غير متوفرة في المخزون. المتاح: ' . $available);
        }

        $unitCost = round((float)($data['unit_cost'] ?? 0), 3);
        $totalCost = round((float)($data['total_cost'] ?? ($qty * $unitCost)), 3);

        return DB::table('stock_movements')->insertGetId([
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'item_id' => $data['item_id'],
            'car_id' => $data['car_id'] ?? null,
            'shipment_item_id' => $data['shipment_item_id'] ?? null,

            'movement_type' => 'OUT',
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'movement_date' => $data['movement_date'] ?? date('Y-m-d'),

            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,

            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function reverseSource(int $companyId, string $sourceType, int $sourceId): void
    {
        DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }

    public function availableQty(int $companyId, int $itemId, $carId = null, $shipmentItemId = null): float
    {
        $q = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId);

        if ($carId) {
            $q->where('car_id', $carId);
        }

        if ($shipmentItemId) {
            $q->where('shipment_item_id', $shipmentItemId);
        }

        $in = (clone $q)->where('movement_type', 'IN')->sum('qty');
        $out = (clone $q)->where('movement_type', 'OUT')->sum('qty');

        return round((float)$in - (float)$out, 3);
    }
}