<?php

namespace App\Services;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class ApproveShipmentService
{
    public function approve(Shipment $shipment): array
    {
        return DB::transaction(function () use ($shipment) {

            if ($shipment->status !== 'DRAFT') {
                throw new \Exception('لا يمكن اعتماد حمولة ليست مسودة');
            }

            $items = $shipment->items;

            if ($items->count() === 0) {
                throw new \Exception('لا يمكن اعتماد حمولة بدون أصناف');
            }

            $totalNet = 0;
            $totalBeforeDiscount = 0;
            $totalDiscount = 0;
            $totalVat = 0;
            $totalAfterVat = 0;

            foreach ($items as $item) {
                $netTon = (float) $item->net_weight;
                $price = (float) $item->unit_price;
                $discount = (float) $item->discount_amount;
                $vatPercent = (float) $item->vat_percent;

                $beforeDiscount = round($netTon * $price, 3);
                $beforeVat = max($beforeDiscount - $discount, 0);
                $vatAmount = round($beforeVat * ($vatPercent / 100), 3);
                $afterVat = round($beforeVat + $vatAmount, 3);

                $totalNet += $netTon;
                $totalBeforeDiscount += $beforeDiscount;
                $totalDiscount += $discount;
                $totalVat += $vatAmount;
                $totalAfterVat += $afterVat;

                $item->update([
                    'total_before_vat' => $beforeVat,
                    'vat_amount' => $vatAmount,
                    'total_after_vat' => $afterVat,
                    'line_total' => $afterVat,
                    'remaining_qty' => $netTon,
                    'sold_qty' => 0,
                    'average_cost' => $price,
                    'status' => 'OPEN',
                ]);
            }

            $beforeVatTotal = max($totalBeforeDiscount - $totalDiscount, 0);
            $invoiceNumber = 'PINV-' . $shipment->shipment_number;

            $purchaseInvoiceId = DB::table('purchase_invoices')->insertGetId([
                'company_id' => $shipment->company_id,
                'branch_id' => $shipment->branch_id,
                'supplier_id' => $shipment->supplier_id,
                'car_id' => $shipment->car_id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $shipment->shipment_date,
                'total_qty' => $totalNet,
                'total_before_discount' => $totalBeforeDiscount,
                'discount_amount' => $totalDiscount,
                'vat_amount' => $totalVat,
                'total_before_vat' => $beforeVatTotal,
                'total_after_vat' => $totalAfterVat,
                'total_amount' => $totalAfterVat,
                'transport_cost' => 0,
                'extra_cost' => 0,
                'payment_status' => 'UNPAID',
                'notes' => 'فاتورة شراء منشأة من الحمولة ' . $shipment->shipment_number,
                'created_by' => request()->header('X-User-ID'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($shipment->items()->get() as $item) {
                $purchaseLineId = DB::table('purchase_invoice_lines')->insertGetId([
                    'company_id' => $shipment->company_id,
                    'purchase_invoice_id' => $purchaseInvoiceId,
                    'item_id' => $item->item_id,
                    'car_id' => $shipment->car_id,
                    'qty' => $item->net_weight,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'vat_percent' => $item->vat_percent,
                    'vat_amount' => $item->vat_amount,
                    'total_before_vat' => $item->total_before_vat,
                    'total_after_vat' => $item->total_after_vat,
                    'line_total' => $item->line_total,
                    'notes' => $item->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'company_id' => $shipment->company_id,
                    'branch_id' => $shipment->branch_id,
                    'item_id' => $item->item_id,
                    'car_id' => $shipment->car_id,
                    'shipment_item_id' => $item->id,
                    'movement_type' => 'IN',
                    'source_type' => 'SHIPMENT',
                    'source_id' => $shipment->id,
                    'movement_date' => $shipment->shipment_date,
                    'qty' => $item->net_weight,
                    'unit_cost' => $item->unit_price,
                    'total_cost' => $item->total_after_vat,
                    'notes' => 'إدخال مخزون من الحمولة ' . $shipment->shipment_number,
                    'created_by' => request()->header('X-User-ID'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $item->update([
                    'purchase_line_id' => $purchaseLineId,
                    'inventory_created' => 1,
                ]);
            }

            $journalId = $this->postPurchaseJournal(
                $shipment,
                $purchaseInvoiceId,
                $beforeVatTotal,
                $totalVat,
                $totalAfterVat
            );

            DB::table('purchase_invoices')
                ->where('id', $purchaseInvoiceId)
                ->update([
                    'journal_entry_id' => $journalId,
                    'updated_at' => now(),
                ]);

            $shipment->update([
                'status' => 'APPROVED',
                'purchase_invoice_id' => $purchaseInvoiceId,
                'total_net_weight' => $totalNet,
                'total_before_discount' => $totalBeforeDiscount,
                'discount_amount' => $totalDiscount,
                'vat_amount' => $totalVat,
                'total_amount' => $totalAfterVat,
                'approved_at' => now(),
                'approved_by' => request()->header('X-User-ID'),
            ]);

            return [
                'purchase_invoice_id' => $purchaseInvoiceId,
                'invoice_number' => $invoiceNumber,
                'journal_entry_id' => $journalId,
                'total_amount' => $totalAfterVat,
            ];
        });
    }

    private function postPurchaseJournal(
        Shipment $shipment,
        int $purchaseInvoiceId,
        float $beforeVatTotal,
        float $totalVat,
        float $totalAfterVat
    ): int {
        $inventoryAccount = $this->settingAccount($shipment->company_id, 'INVENTORY_ACCOUNT');
        $supplierAccount = $this->settingAccount($shipment->company_id, 'SUPPLIER_ACCOUNT');
        $vatInputAccount = $this->settingAccount($shipment->company_id, 'VAT_INPUT_ACCOUNT');

        $lines = [
            [
                'account_id' => $inventoryAccount,
                'debit' => $beforeVatTotal,
                'credit' => 0,
                'description' => 'إثبات مخزون من الحمولة ' . $shipment->shipment_number,
            ],
        ];

        if ($totalVat > 0) {
            $lines[] = [
                'account_id' => $vatInputAccount,
                'debit' => $totalVat,
                'credit' => 0,
                'description' => 'ضريبة مدخلات على فاتورة شراء',
            ];
        }

        $lines[] = [
            'account_id' => $supplierAccount,
            'debit' => 0,
            'credit' => $totalAfterVat,
            'description' => 'استحقاق المورد',
        ];

        return app(\App\Services\AccountingService::class)->post([
            'company_id' => $shipment->company_id,
            'branch_id' => $shipment->branch_id,
            'entry_date' => $shipment->shipment_date,
            'source_type' => 'PURCHASE_INVOICE',
            'source_id' => $purchaseInvoiceId,
            'description' => 'قيد فاتورة شراء من الحمولة ' . $shipment->shipment_number,
            'lines' => $lines,
        ]);
    }

    private function settingAccount(int $companyId, string $key): int
    {
        $accountId = DB::table('accounting_settings')
            ->where('company_id', $companyId)
            ->where('setting_key', $key)
            ->value('account_id');

        if (!$accountId) {
            throw new \Exception('إعداد الحساب غير موجود: ' . $key);
        }

        return (int) $accountId;
    }
}