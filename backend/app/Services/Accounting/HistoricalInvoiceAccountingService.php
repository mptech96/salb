<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/** Reads authoritative posted journal evidence; never consults mutable party settings. */
class HistoricalInvoiceAccountingService
{
    public function partyAccount(int $companyId, int $branchId, string $kind, int $invoiceId): int
    {
        if (!in_array($kind, ['SALE', 'PURCHASE'], true)) {
            throw new \RuntimeException('نوع الفاتورة غير مدعوم.');
        }
        $sale = $kind === 'SALE';
        $invoice = DB::table($sale ? 'sales_invoices' : 'purchase_invoices')
            ->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('id', $invoiceId)->first();
        if (!$invoice || ($invoice->document_status ?? null) !== 'POSTED'
            || !empty($invoice->voided_at) || empty($invoice->journal_entry_id)) {
            throw new \RuntimeException('الفاتورة الأصلية غير مرحلة أو خارج نطاق الشركة والفرع.');
        }
        $entry = DB::table('journal_entries')->where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('id', $invoice->journal_entry_id)
            ->where('status', 'POSTED')->where('source_type', $kind)
            ->where('source_id', $invoiceId)->first();
        if (!$entry) {
            throw new \RuntimeException('القيد الأصلي لا يطابق الفاتورة.');
        }
        $lines = DB::table('journal_entry_lines')->where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('journal_entry_id', $entry->id)->get();
        if ($lines->isEmpty() || abs((float)$lines->sum('debit') - (float)$lines->sum('credit')) > 0.0001) {
            throw new \RuntimeException('القيد الأصلي فارغ أو غير متوازن.');
        }
        $partyType = $sale ? 'CUSTOMER' : 'SUPPLIER';
        $partyId = (int)$invoice->{$sale ? 'customer_id' : 'supplier_id'};
        $partyLines = $lines->filter(fn($line) => $line->party_type === $partyType
            && (int)$line->party_id === $partyId);
        $ids = $partyLines->pluck('account_id')->unique()->values();
        $net = (float)$partyLines->sum('debit') - (float)$partyLines->sum('credit');
        $total = (float)($invoice->base_total_amount ?? $invoice->total_amount);
        if ($ids->count() !== 1 || abs($net - ($sale ? $total : -$total)) > 0.001) {
            throw new \RuntimeException('لا يمكن إسناد حساب الذمة الأصلي بصورة قطعية؛ يلزم اعتماد محاسبي.');
        }
        // Historical existence is required, not current activity: posting eligibility is
        // enforced separately by JournalService. Never silently substitute a new account.
        if (!DB::table('accounts')->where('company_id', $companyId)->where('id', $ids[0])->exists()) {
            throw new \RuntimeException('حساب الذمة الأصلي لا يتبع الشركة.');
        }
        return (int)$ids[0];
    }
}
