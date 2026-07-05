<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class AccountingEngine
{
    public function expense(array $data): PostingResult
    {
        return (new ExpensePosting())->post($data);
    }

    public function purchase(array $data): PostingResult
    {
        return (new PurchasePosting())->post($data);
    }

    public function sale(array $data): PostingResult
    {
        return (new SalesPosting())->post($data);
    }

    public function voucher(array $data): PostingResult
    {
        return (new VoucherPosting())->post($data);
    }

    public function worker(array $data): PostingResult
    {
        return (new WorkerPosting())->post($data);
    }

    public function inventory(array $data): PostingResult
    {
        return (new InventoryPosting())->post($data);
    }

    public function vat(array $data): PostingResult
    {
        return (new VatPosting())->post($data);
    }

    protected function nextEntryNumber(int $companyId): string
    {
        $last = DB::table('journal_entries')
            ->where('company_id',$companyId)
            ->max('id');

        return sprintf(
            "JE-%s-%06d",
            date("Y"),
            ($last ?? 0)+1
        );
    }
}