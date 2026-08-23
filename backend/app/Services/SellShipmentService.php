<?php

namespace App\Services;

use App\Models\ShipmentItem;
use Illuminate\Support\Facades\DB;

class SellShipmentService
{
    public function sell(array $data): array
    {
        return DB::transaction(function () use ($data) {

            $companyId = request()->header('X-Company-ID');
            $branchId  = request()->header('X-Branch-ID');
            $userId    = request()->header('X-User-ID');

            $invoiceNumber = 'SINV-' . date('Y') . '-' . str_pad(
                (DB::table('sales_invoices')->where('company_id', $companyId)->max('id') ?? 0) + 1,
                5,
                '0',
                STR_PAD_LEFT
            );

            $totalQty = 0;
            $totalBeforeDiscount = 0;
            $totalDiscount = 0;
            $totalVat = 0;
            $totalAfterVat = 0;

            $prepared = [];

            foreach ($data['items'] as $row) {
                $shipmentItem = ShipmentItem::query()
                    ->where('company_id', $companyId)
                    ->where('id', $row['shipment_item_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$shipmentItem) {
                    throw new \Exception('صنف الحمولة غير موجود');
                }

                $emptyKg = (float) ($row['empty_weight'] ?? 0);
                $loadedKg = (float) ($row['loaded_weight'] ?? 0);
                $deductionKg = (float) ($row['deduction_weight'] ?? 0);

                $netKg = max($loadedKg - $emptyKg - $deductionKg, 0);
                $qtyTon = round($netKg / 1000, 3);

                if ($qtyTon <= 0) {
                    throw new \Exception('كمية البيع يجب أن تكون أكبر من صفر');
                }

                if ($qtyTon > (float) $shipmentItem->remaining_qty) {
                    throw new \Exception('الكمية أكبر من المتبقي في الحمولة. المتبقي: ' . $shipmentItem->remaining_qty);
                }

                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $discount = (float) ($row['discount_amount'] ?? 0);
                $vatPercent = (float) ($row['vat_percent'] ?? 0);

                $beforeDiscount = round($qtyTon * $unitPrice, 3);
                $beforeVat = max($beforeDiscount - $discount, 0);
                $vatAmount = round($beforeVat * ($vatPercent / 100), 3);
                $afterVat = round($beforeVat + $vatAmount, 3);

                $totalQty += $qtyTon;
                $totalBeforeDiscount += $beforeDiscount;
                $totalDiscount += $discount;
                $totalVat += $vatAmount;
                $totalAfterVat += $afterVat;

                $prepared[] = compact(
                    'shipmentItem',
                    'qtyTon',
                    'unitPrice',
                    'discount',
                    'vatPercent',
                    'vatAmount',
                    'beforeVat',
                    'afterVat'
                );
            }

            $salesInvoiceId = DB::table('sales_invoices')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'customer_id' => $data['customer_id'],
                'car_id' => $data['car_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'],
                'total_qty' => $totalQty,
                'total_before_discount' => $totalBeforeDiscount,
                'discount_amount' => $totalDiscount,
                'vat_amount' => $totalVat,
                'total_before_vat' => max($totalBeforeDiscount - $totalDiscount, 0),
                'total_after_vat' => $totalAfterVat,
                'commission_amount' => $data['commission_amount'] ?? 0,
                'total_amount' => $totalAfterVat,
                'payment_status' => 'UNPAID',
                'notes' => $data['notes'] ?? 'فاتورة بيع من حمولة',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($prepared as $p) {
                $shipmentItem = $p['shipmentItem'];

                $salesLineId = DB::table('sales_invoice_lines')->insertGetId([
                    'company_id' => $companyId,
                    'sales_invoice_id' => $salesInvoiceId,
                    'item_id' => $shipmentItem->item_id,
                    'car_id' => $data['car_id'] ?? null,
                    'shipment_item_id' => $shipmentItem->id,
                    'qty' => $p['qtyTon'],
                    'unit_price' => $p['unitPrice'],
                    'discount_amount' => $p['discount'],
                    'vat_percent' => $p['vatPercent'],
                    'vat_amount' => $p['vatAmount'],
                    'total_before_vat' => $p['beforeVat'],
                    'total_after_vat' => $p['afterVat'],
                    'line_total' => $p['afterVat'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $shipmentItem->item_id,
                    'car_id' => $data['car_id'] ?? null,
                    'shipment_item_id' => $shipmentItem->id,
                    'movement_type' => 'OUT',
                    'source_type' => 'SALE',
                    'source_id' => $salesInvoiceId,
                    'movement_date' => $data['invoice_date'],
                    'qty' => $p['qtyTon'],
                    'unit_cost' => $shipmentItem->average_cost,
                    'total_cost' => round($p['qtyTon'] * (float) $shipmentItem->average_cost, 3),
                    'notes' => 'بيع من حمولة',
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('shipment_item_sources')->insert([
                    'company_id' => $companyId,
                    'shipment_item_id' => $shipmentItem->id,
                    'sales_invoice_line_id' => $salesLineId,
                    'qty' => $p['qtyTon'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $newRemaining = round((float) $shipmentItem->remaining_qty - $p['qtyTon'], 3);
                $newSold = round((float) $shipmentItem->sold_qty + $p['qtyTon'], 3);

                $shipmentItem->update([
                    'remaining_qty' => $newRemaining,
                    'sold_qty' => $newSold,
                    'status' => $newRemaining <= 0 ? 'SOLD' : 'OPEN',
                ]);
            }

            return [
                'sales_invoice_id' => $salesInvoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAfterVat,
            ];
        });
    }
}