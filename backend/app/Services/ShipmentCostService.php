<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentCost;
use Illuminate\Support\Facades\DB;

class ShipmentCostService
{
    public function store(array $data): array
    {
        return DB::transaction(function () use ($data) {

            $companyId = request()->header('X-Company-ID');
            $branchId  = request()->header('X-Branch-ID');
            $userId    = request()->header('X-User-ID');

            $shipment = Shipment::where('company_id', $companyId)
                ->where('id', $data['shipment_id'])
                ->firstOrFail();

            $cost = ShipmentCost::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'shipment_id' => $shipment->id,
                'expense_type_id' => $data['expense_type_id'],
                'expense_date' => $data['expense_date'],
                'amount' => $data['amount'],
                'payment_status' => $data['payment_status'] ?? 'PAID',
                'payment_method' => $data['payment_method'] ?? 'CASH',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $posting = $this->postAccounting($cost);

            $cost->update([
                'voucher_id' => $posting['voucher_id'],
                'journal_entry_id' => $posting['journal_entry_id'],
            ]);

            $distribution = app(ShipmentCostDistributionService::class)
                ->distribute($shipment->fresh('items'));

            return [
                'cost_id' => $cost->id,
                'voucher_id' => $posting['voucher_id'],
                'journal_entry_id' => $posting['journal_entry_id'],
                'distribution' => $distribution,
            ];
        });
    }

    public function summary(int $shipmentId): array
    {
        $companyId = request()->header('X-Company-ID');

        $shipment = Shipment::with('items')
            ->where('company_id', $companyId)
            ->where('id', $shipmentId)
            ->firstOrFail();

        $costs = ShipmentCost::query()
            ->leftJoin('expense_types as t', 't.id', '=', 'shipment_costs.expense_type_id')
            ->where('shipment_costs.company_id', $companyId)
            ->where('shipment_costs.shipment_id', $shipmentId)
            ->select('shipment_costs.*', 't.type_name')
            ->orderByDesc('shipment_costs.id')
            ->get();

        $totalCosts = (float) $costs->sum('amount');
        $totalWeight = (float) $shipment->items->sum('net_weight');

        return [
            'shipment' => $shipment,
            'costs' => $costs,
            'total_costs' => round($totalCosts, 3),
            'total_weight' => round($totalWeight, 3),
            'cost_per_ton' => $totalWeight > 0 ? round($totalCosts / $totalWeight, 3) : 0,
            'items' => $shipment->items,
        ];
    }

    private function postAccounting(ShipmentCost $cost): array
    {
        if ($cost->payment_status !== 'PAID') {
            return [
                'voucher_id' => null,
                'journal_entry_id' => null,
            ];
        }

        $voucherId = $this->createVoucher($cost);

        $inventoryAccount = $this->settingAccount($cost->company_id, 'INVENTORY_ACCOUNT');
        $cashAccount = $this->paymentAccount($cost->company_id, $cost->payment_method);

        $journalId = app(\App\Services\AccountingService::class)->post([
            'company_id' => $cost->company_id,
            'branch_id' => $cost->branch_id,
            'entry_date' => $cost->expense_date,
            'source_type' => 'SHIPMENT_COST',
            'source_id' => $cost->id,
            'description' => 'تكلفة حمولة رقم ' . $cost->shipment_id,
            'lines' => [
                [
                    'account_id' => $inventoryAccount,
                    'debit' => $cost->amount,
                    'credit' => 0,
                    'description' => 'إضافة تكلفة تشغيل على المخزون',
                ],
                [
                    'account_id' => $cashAccount,
                    'debit' => 0,
                    'credit' => $cost->amount,
                    'description' => 'سداد تكلفة حمولة',
                ],
            ],
        ]);

        return [
            'voucher_id' => $voucherId,
            'journal_entry_id' => $journalId,
        ];
    }

    private function createVoucher(ShipmentCost $cost): int
    {
        $lastId = DB::table('vouchers')
            ->where('company_id', $cost->company_id)
            ->max('id') ?? 0;

        $voucherNumber = 'PAY-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

        return DB::table('vouchers')->insertGetId([
            'company_id' => $cost->company_id,
            'branch_id' => $cost->branch_id,
            'voucher_type_id' => 2,
            'voucher_number' => $voucherNumber,
            'voucher_date' => $cost->expense_date,
            'reference_type' => 'SHIPMENT_COST',
            'reference_id' => $cost->id,
            'amount' => $cost->amount,
            'payment_method' => $cost->payment_method,
            'notes' => 'سند صرف تكلفة حمولة رقم ' . $cost->shipment_id,
            'created_by' => request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settingAccount(int $companyId, string $key): int
    {
        $accountId = DB::table('accounting_settings')
            ->where('company_id', $companyId)
            ->where('setting_key', $key)
            ->value('account_id');

        if (!$accountId) {
            throw new \Exception('الحساب غير مضبوط في الإعدادات: ' . $key);
        }

        return (int) $accountId;
    }

    private function paymentAccount(int $companyId, ?string $method): int
    {
        if ($method === 'BANK') {
            return $this->settingAccount($companyId, 'BANK_ACCOUNT');
        }

        return $this->settingAccount($companyId, 'CASH_ACCOUNT');
    }
}