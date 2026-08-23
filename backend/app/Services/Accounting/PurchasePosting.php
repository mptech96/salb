<?php

namespace App\Services\Accounting;

/**
 * Compatibility adapter for older project bindings.
 * All purchase accounting is delegated to the Stage 7 item-aware posting engine.
 */
class PurchasePosting
{
    public function __construct(private EnterpriseAccountingPostingService $posting) {}

    public function post(array $data): PostingResult
    {
        try {
            $companyId=(int)$data['company_id'];$invoiceId=(int)$data['invoice_id'];$userId=(int)($data['created_by']??0);
            $journalId=$this->posting->postPurchase($companyId,$invoiceId,$userId);
            return PostingResult::success('تم ترحيل فاتورة الشراء وفق حسابات الأصناف',$journalId);
        } catch (\Throwable $e) {
            return PostingResult::error($e->getMessage());
        }
    }
}
