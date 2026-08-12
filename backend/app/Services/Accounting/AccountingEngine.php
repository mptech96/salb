<?php
namespace App\Services\Accounting;
class AccountingEngine
{
    public function expense(array $d):PostingResult{return app(ExpensePosting::class)->post($d);} public function purchase(array $d):PostingResult{return app(PurchasePosting::class)->post($d);}
    public function sale(array $d):PostingResult{return app(SalesPosting::class)->post($d);} public function voucher(array $d):PostingResult{return app(VoucherPosting::class)->post($d);}
    public function worker(array $d):PostingResult{return app(WorkerPosting::class)->post($d);} public function inventory(array $d):PostingResult{return app(InventoryPosting::class)->post($d);}
    public function vat(array $d):PostingResult{return app(VatPosting::class)->post($d);}
}
