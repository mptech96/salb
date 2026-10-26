<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Canonical shipment beneficiary contract; never infers a party from the shipment. */
final class ShipmentCostPayee
{
    public static function normalize(array $data): array
    {
        foreach (['type', 'id'] as $field) {
            $canonical = 'payee_'.$field;
            $legacy = 'beneficiary_'.$field;
            $normalize = fn ($value) => $value === null || $value === '' ? null
                : ($field === 'type' ? strtoupper(trim((string) $value)) : (string) $value);
            if (array_key_exists($canonical, $data) && array_key_exists($legacy, $data)
                && $normalize($data[$canonical]) !== $normalize($data[$legacy])) {
                throw ValidationException::withMessages([$canonical => 'بيانات المستفيد القديمة والجديدة متعارضة.']);
            }
            if (!array_key_exists($canonical, $data) && array_key_exists($legacy, $data)) $data[$canonical] = $data[$legacy];
            if (array_key_exists($canonical, $data) && $field === 'type') $data[$canonical] = $normalize($data[$canonical]);
        }
        return $data;
    }

    public static function assertAccessible(int $companyId, int $branchId, ?string $type, ?int $id): void
    {
        if (!$type && !$id) return;
        if (!in_array($type, ['SUPPLIER', 'CUSTOMER', 'DRIVER', 'WORKER', 'CARRIER', 'OTHER'], true)) {
            throw new \RuntimeException('نوع المستفيد غير صالح.');
        }
        // No carrier entity registry exists in this contract: do not tag an arbitrary GL party id.
        if (in_array($type, ['CARRIER', 'OTHER'], true)) {
            if ($id) throw new \RuntimeException('لا يوجد مرجع معتمد لهذا النوع من المستفيد؛ لا تمرر معرفًا تخمينيًا.');
            return;
        }
        if (!$id || $id <= 0) throw new \RuntimeException('اختر مستفيدًا صالحًا.');
        if (in_array($type, ['SUPPLIER', 'CUSTOMER'], true)) {
            app(PartyBranchScopeService::class)->assertAccessible($companyId, $type, $id, $branchId);
            return;
        }
        $party = DB::table($type === 'DRIVER' ? 'drivers' : 'workers')->where('company_id', $companyId)
            ->where('id', $id)->where('is_active', 1)->first();
        if (!$party || ($party->branch_id !== null && (int) $party->branch_id !== $branchId)) {
            throw new \RuntimeException('المستفيد غير نشط أو خارج نطاق الشركة والفرع.');
        }
    }
}
