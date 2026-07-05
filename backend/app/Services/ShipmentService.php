<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Facades\DB;

class ShipmentService
{
    public function save(array $data, ?Shipment $shipment = null): Shipment
    {
        return DB::transaction(function () use ($data, $shipment) {

            $companyId = request()->header('X-Company-ID');
            $branchId  = request()->header('X-Branch-ID');
            $userId    = request()->header('X-User-ID');

            if (!$shipment) {
                $shipment = new Shipment();
            }

            if ($shipment->exists && $shipment->status !== 'DRAFT') {
                throw new \Exception('لا يمكن تعديل حمولة بعد اعتمادها');
            }

            if (empty($data['shipment_number'])) {
                $lastId = Shipment::where('company_id', $companyId)->max('id') ?? 0;

                $data['shipment_number'] =
                    'SHP-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
            }

            $totalGross = 0;
            $totalTare = 0;
            $totalDeduction = 0;
            $totalNet = 0;
            $totalBeforeDiscount = 0;
            $totalDiscount = 0;
            $totalVat = 0;
            $totalAfterVat = 0;

            $preparedItems = [];

            foreach (($data['items'] ?? []) as $index => $row) {
                $grossKg = (float) ($row['gross_weight'] ?? 0);
                $tareKg = (float) ($row['tare_weight'] ?? 0);
                $deductionKg = (float) ($row['deduction_weight'] ?? 0);

                $netKg = max($grossKg - $tareKg - $deductionKg, 0);
                $netTon = round($netKg / 1000, 3);

                // لو الواجهة أرسلت net_weight جاهز بالطن نستخدمه إذا كان أكبر من صفر
                if (isset($row['net_weight']) && (float) $row['net_weight'] > 0) {
                    $netTon = (float) $row['net_weight'];
                }

                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $discount = (float) ($row['discount_amount'] ?? 0);
                $vatPercent = (float) ($row['vat_percent'] ?? ($data['vat_percent'] ?? 15));

                $beforeDiscount = round($netTon * $unitPrice, 3);
                $beforeVat = max($beforeDiscount - $discount, 0);
                $vatAmount = round($beforeVat * ($vatPercent / 100), 3);
                $afterVat = round($beforeVat + $vatAmount, 3);

                $totalGross += $grossKg;
                $totalTare += $tareKg;
                $totalDeduction += $deductionKg;
                $totalNet += $netTon;
                $totalBeforeDiscount += $beforeDiscount;
                $totalDiscount += $discount;
                $totalVat += $vatAmount;
                $totalAfterVat += $afterVat;

                $preparedItems[] = [
                    'company_id' => $companyId,
                    'shipment_id' => null,
                    'item_id' => $row['item_id'],

                    'gross_weight' => $grossKg,
                    'tare_weight' => $tareKg,
                    'deduction_weight' => $deductionKg,
                    'net_weight' => $netTon,

                    'remaining_qty' => $netTon,
                    'sold_qty' => 0,

                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,

                    'vat_percent' => $vatPercent,
                    'vat_amount' => $vatAmount,
                    'total_before_vat' => $beforeVat,
                    'total_after_vat' => $afterVat,

                    'line_total' => $afterVat,

                    'average_cost' => $unitPrice,
                    'distributed_cost' => 0,
                    'profit' => 0,

                    'inventory_created' => 0,
                    'purchase_line_id' => null,
                    'sorting_order' => $index + 1,
                    'status' => 'OPEN',
                    'notes' => $row['notes'] ?? null,
                ];
            }

            $shipment->fill([
                'company_id' => $companyId,
                'branch_id' => $branchId,

                'supplier_id' => $data['supplier_id'],
                'driver_id' => $data['driver_id'] ?? null,
                'car_id' => $data['car_id'] ?? null,

                'shipment_number' => $data['shipment_number'],
                'shipment_date' => $data['shipment_date'],

                'plate_number' => $data['plate_number'] ?? null,
                'weight_card_number' => $data['weight_card_number'] ?? null,

                'status' => 'DRAFT',

                'total_gross_weight' => $totalGross,
                'total_tare_weight' => $totalTare,
                'total_deduction_weight' => $totalDeduction,
                'total_net_weight' => $totalNet,

                'total_before_discount' => $totalBeforeDiscount,
                'discount_amount' => $totalDiscount,

                // سننقلها لاحقًا إلى جدول تكاليف الحمولة، الآن لا تدخل فاتورة المورد
                'transport_cost' => $data['transport_cost'] ?? 0,
                'extra_cost' => $data['extra_cost'] ?? 0,

                'vat_percent' => $data['vat_percent'] ?? 15,
                'vat_amount' => $totalVat,
                'total_amount' => $totalAfterVat,

                'notes' => $data['notes'] ?? null,
                'created_by' => $shipment->exists ? $shipment->created_by : $userId,
            ]);

            $shipment->save();

            ShipmentItem::where('shipment_id', $shipment->id)->delete();

            foreach ($preparedItems as $itemData) {
                $itemData['shipment_id'] = $shipment->id;
                ShipmentItem::create($itemData);
            }

            return $shipment->fresh('items');
        });
    }
}