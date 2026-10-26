<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Scoped LOT reservation; callers must own the complete financial transaction. */
final class InventoryPostingLocks
{
    public static function reserveLotScope(int $companyId, string $date): object
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \RuntimeException('Lot reservation requires the enclosing inventory transaction.');
        }
        $year = (int) (new \DateTimeImmutable($date))->format('Y');
        $scope = ['company_id' => $companyId, 'branch_id' => 0,
            'document_type' => 'INVENTORY_LOT', 'document_year' => $year];
        // Preflight must prepare this existing-table scope before writes resume.
        // Hot bootstrap/absent-row probing adds next-key locks across unrelated companies.
        $row=DB::table('sulb_document_sequences')->where($scope)->lockForUpdate()->first();
        if(!$row)throw new \RuntimeException('يجب تهيئة تسلسل دفعات المخزون للشركة والسنة قبل الترحيل.');
        return $row;
    }

    public static function lockItems(int $companyId, iterable $itemIds): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \RuntimeException('Item locks require the enclosing inventory transaction.');
        }
        $ids = array_unique(array_map('intval', is_array($itemIds) ? $itemIds : iterator_to_array($itemIds)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if (!DB::table('items')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->first()) {
                throw new \RuntimeException('الصنف غير موجود ضمن الشركة المحددة.');
            }
        }
    }
}
