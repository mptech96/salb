<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryOperationService
{
    public function approve(int $operationId): array
    {
        return DB::transaction(function () use ($operationId) {

            $companyId = request()->header('X-Company-ID');

            $operation = DB::table('inventory_operations')
                ->where('company_id', $companyId)
                ->where('id', $operationId)
                ->first();

            if (!$operation) {
                throw new \Exception('عملية المخزون غير موجودة');
            }

            if ($operation->status !== 'DRAFT') {
                throw new \Exception('لا يمكن اعتماد عملية ليست مسودة');
            }

            $fromLines = DB::table('inventory_operation_lines')
                ->where('company_id', $companyId)
                ->where('operation_id', $operationId)
                ->where('line_type', 'FROM')
                ->get();

            $toLines = DB::table('inventory_operation_lines')
                ->where('company_id', $companyId)
                ->where('operation_id', $operationId)
                ->where('line_type', 'TO')
                ->get();

            if ($fromLines->count() === 0) {
                throw new \Exception('يجب إضافة صنف مصدر للعملية');
            }

            if ($toLines->count() === 0 && !in_array($operation->operation_type, ['SCRAP','COUNT','ADJUSTMENT'])) {
                throw new \Exception('يجب إضافة صنف ناتج للعملية');
            }

            $inventory = app(InventoryService::class);

            $totalFromCost = 0;

            foreach ($fromLines as $line) {
                if ((float)$line->qty <= 0) {
                    throw new \Exception('كمية الصنف المصدر غير صحيحة');
                }

                $available = $inventory->availableQty(
                    $companyId,
                    $line->item_id,
                    $line->car_id,
                    $line->shipment_item_id
                );

                if ($available < (float)$line->qty) {
                    throw new \Exception('الكمية غير متوفرة للصنف رقم ' . $line->item_id . ' المتاح: ' . $available);
                }

                $unitCost = (float)$line->unit_cost;
                $totalCost = (float)$line->total_cost;

                if ($unitCost <= 0) {
                    $unitCost = $this->getAverageCost($companyId, $line->item_id, $line->car_id, $line->shipment_item_id);
                    $totalCost = round($unitCost * (float)$line->qty, 3);
                }

                $totalFromCost += $totalCost;

                $inventory->stockOut([
                    'company_id' => $companyId,
                    'branch_id' => $operation->branch_id,
                    'item_id' => $line->item_id,
                    'car_id' => $line->car_id,
                    'shipment_item_id' => $line->shipment_item_id,
                    'source_type' => 'INVENTORY_OPERATION',
                    'source_id' => $operationId,
                    'movement_date' => $operation->operation_date,
                    'qty' => $line->qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'notes' => 'إخراج مخزون للعملية ' . $operation->operation_number,
                ]);
            }

            $totalToQty = (float)$toLines->sum('qty');

            if ($toLines->count() > 0 && $totalToQty <= 0) {
                throw new \Exception('إجمالي كمية الأصناف الناتجة غير صحيح');
            }

            foreach ($toLines as $line) {
                $shareCost = $totalToQty > 0
                    ? round($totalFromCost * ((float)$line->qty / $totalToQty), 3)
                    : 0;

                $unitCost = (float)$line->qty > 0
                    ? round($shareCost / (float)$line->qty, 3)
                    : 0;

                $inventory->stockIn([
                    'company_id' => $companyId,
                    'branch_id' => $operation->branch_id,
                    'item_id' => $line->item_id,
                    'car_id' => $line->car_id,
                    'shipment_item_id' => $line->shipment_item_id,
                    'source_type' => 'INVENTORY_OPERATION',
                    'source_id' => $operationId,
                    'movement_date' => $operation->operation_date,
                    'qty' => $line->qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $shareCost,
                    'notes' => 'إدخال مخزون ناتج من العملية ' . $operation->operation_number,
                ]);

                DB::table('inventory_operation_lines')
                    ->where('id', $line->id)
                    ->update([
                        'unit_cost' => $unitCost,
                        'total_cost' => $shareCost,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('inventory_operations')
                ->where('id', $operationId)
                ->update([
                    'status' => 'POSTED',
                    'approved_by' => request()->header('X-User-ID'),
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'operation_id' => $operationId,
                'operation_number' => $operation->operation_number,
                'total_from_cost' => round($totalFromCost, 3),
                'message' => 'تم اعتماد وترحيل العملية المخزنية',
            ];
        });
    }

    private function getAverageCost($companyId, $itemId, $carId = null, $shipmentItemId = null): float
    {
        $q = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('movement_type', 'IN');

        if ($carId) {
            $q->where('car_id', $carId);
        }

        if ($shipmentItemId) {
            $q->where('shipment_item_id', $shipmentItemId);
        }

        $qty = (float)(clone $q)->sum('qty');
        $cost = (float)(clone $q)->sum('total_cost');

        return $qty > 0 ? round($cost / $qty, 3) : 0;
    }
}