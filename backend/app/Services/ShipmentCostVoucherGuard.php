<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ShipmentCostVoucherGuard
{
    /** Called inside the deletion transaction, before locking the voucher. */
    public static function assertDeletable(object $voucher): void
    {
        if ($voucher->reference_type !== 'SHIPMENT_COST') return;
        app(SulbDocumentSequenceService::class)->lockVoucherCompany((int) $voucher->company_id);
        $source = DB::table('shipment_costs')->where('company_id', $voucher->company_id)->where('id', $voucher->reference_id)->first();
        if (!$source) throw new \RuntimeException('مصدر سند تكلفة الشحنة يحتاج مراجعة؛ لا يمكن حذف الدليل.');
        DB::table('shipments')->where('company_id', $voucher->company_id)->where('id', $source->shipment_id)->lockForUpdate()->first();
        $cost = DB::table('shipment_costs')->where('company_id', $voucher->company_id)->where('id', $source->id)->lockForUpdate()->first();
        if (!$cost || $cost->cost_status === 'POSTED' || $cost->journal_entry_id || $cost->voucher_id
            || DB::table('journal_entries')->where('company_id', $voucher->company_id)->where('source_type', 'SHIPMENT_COST')->where('source_id', $source->id)->exists()) {
            throw new \RuntimeException('لا يمكن حذف سند مرتبط بدليل ترحيل تكلفة الشحنة.');
        }
    }
}
