<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    public function meta(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $parties = fn (string $table, string $name) => DB::table($table)
            ->where('company_id', $companyId)->where('is_active', 1)
            ->orderBy($name)->get(['id', $name]);

        $branchId = $context->branchFilter($request);

        return response()->json(['status' => true, 'data' => [
            'branches' => DB::table('branches')->where('company_id', $companyId)->where('is_active', 1)
                ->when($branchId !== null, fn ($query) => $query->where('id', $branchId))->orderBy('branch_name')->get(['id', 'branch_name']),
            'suppliers' => $parties('suppliers', 'supplier_name'),
            'customers' => $parties('customers', 'customer_name'),
            'affiliation_types' => [
                ['code' => 'COMPANY', 'name' => 'الشركة'], ['code' => 'SUPPLIER', 'name' => 'مورد'],
                ['code' => 'CUSTOMER', 'name' => 'عميل'], ['code' => 'CARRIER', 'name' => 'ناقل / شركة نقل'],
                ['code' => 'INDEPENDENT', 'name' => 'سائق مستقل'],
            ],
        ]]);
    }

    public function index(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);
        $rows = $this->visibleQuery($companyId, $branchId)
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->select('d.*', 'b.branch_name', DB::raw('(SELECT COUNT(*) FROM weighbridge_cards w WHERE w.company_id=d.company_id AND w.driver_id=d.id) weighbridge_count'))
            ->orderBy('d.driver_name')->get();

        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function show(Request $request, int $id, AccountingContext $context)
    {
        $row = $this->visibleQuery($context->companyId($request), $context->branchFilter($request))->where('d.id', $id)->first();
        return $row ? response()->json(['status' => true, 'data' => $row]) : response()->json(['status' => false, 'message' => 'السائق غير موجود ضمن نطاقك.'], 404);
    }

    public function store(Request $request, AccountingContext $context)
    {
        return $this->save($request, $context, null);
    }

    public function update(Request $request, int $id, AccountingContext $context)
    {
        return $this->save($request, $context, $id);
    }

    public function destroy(Request $request, int $id, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);
        $query = DB::table('drivers')->where('company_id', $companyId)->where('id', $id);
        if ($branchId !== null) $query->where('branch_id', $branchId);
        if (! $query->exists()) return response()->json(['status' => false, 'message' => 'السائق غير موجود ضمن نطاق فرعك.'], 404);

        $used = DB::table('weighbridge_cards')->where('company_id', $companyId)->where('driver_id', $id)->exists()
            || DB::table('shipments')->where('company_id', $companyId)->where('driver_id', $id)->exists();
        if ($used) {
            DB::table('drivers')->where('company_id', $companyId)->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);
            return response()->json(['status' => true, 'message' => 'للسائق سجل تاريخي؛ تم تعطيله بدل حذفه.']);
        }
        DB::table('drivers')->where('company_id', $companyId)->where('id', $id)->delete();
        return response()->json(['status' => true, 'message' => 'تم حذف السائق.']);
    }

    private function save(Request $request, AccountingContext $context, ?int $id)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer', 'driver_name' => 'required|string|max:255', 'phone' => 'nullable|string|max:50',
            'id_number' => 'nullable|string|max:100', 'license_number' => 'nullable|string|max:100',
            'affiliation_type' => 'required|in:COMPANY,SUPPLIER,CUSTOMER,CARRIER,INDEPENDENT',
            'affiliation_id' => 'nullable|integer', 'notes' => 'nullable|string', 'is_active' => 'nullable|boolean',
        ]);
        $companyId = $context->companyId($request);
        $scopedBranch = $context->branchFilter($request);
        $requestedBranch = isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null;
        if ($scopedBranch !== null && $requestedBranch !== null && $requestedBranch !== $scopedBranch) {
            return response()->json(['status' => false, 'message' => 'لا يمكن اختيار فرع خارج نطاقك.'], 422);
        }
        $branchId = $scopedBranch ?? $requestedBranch;
        if ($branchId !== null && ! DB::table('branches')->where('company_id', $companyId)->where('id', $branchId)->where('is_active', 1)->exists()) {
            return response()->json(['status' => false, 'message' => 'الفرع غير صالح أو لا يتبع الشركة الحالية.'], 422);
        }

        if ($id !== null) {
            $target = DB::table('drivers')->where('company_id', $companyId)->where('id', $id);
            if ($scopedBranch !== null) $target->where('branch_id', $scopedBranch);
            if (! $target->exists()) return response()->json(['status' => false, 'message' => 'السائق غير موجود ضمن نطاق فرعك.'], 404);
        }

        $type = strtoupper($data['affiliation_type']);
        $affiliationId = isset($data['affiliation_id']) && (int) $data['affiliation_id'] > 0 ? (int) $data['affiliation_id'] : null;
        if ($type === 'SUPPLIER' && (! $affiliationId || ! DB::table('suppliers')->where('company_id', $companyId)->where('id', $affiliationId)->exists())) {
            return response()->json(['status' => false, 'message' => 'اختر المورد الذي يتبعه السائق.'], 422);
        }
        if ($type === 'CUSTOMER' && (! $affiliationId || ! DB::table('customers')->where('company_id', $companyId)->where('id', $affiliationId)->exists())) {
            return response()->json(['status' => false, 'message' => 'اختر العميل الذي يتبعه السائق.'], 422);
        }
        if (in_array($type, ['COMPANY', 'INDEPENDENT', 'CARRIER'], true)) $affiliationId = null;

        $values = [
            'branch_id' => $branchId, 'driver_name' => trim($data['driver_name']), 'phone' => $data['phone'] ?? null,
            'id_number' => $data['id_number'] ?? null, 'license_number' => $data['license_number'] ?? null,
            'affiliation_type' => $type, 'affiliation_id' => $affiliationId, 'notes' => $data['notes'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 1), 'updated_at' => now(),
        ];
        if ($id !== null) {
            DB::table('drivers')->where('company_id', $companyId)->where('id', $id)->update($values);
            return response()->json(['status' => true, 'message' => 'تم تحديث السائق.']);
        }
        $newId = DB::table('drivers')->insertGetId(['company_id' => $companyId, ...$values, 'created_at' => now()]);
        return response()->json(['status' => true, 'message' => 'تم حفظ السائق.', 'id' => $newId], 201);
    }

    private function visibleQuery(int $companyId, ?int $branchId)
    {
        return DB::table('drivers as d')->where('d.company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('d.branch_id', $branchId)->orWhereNull('d.branch_id')));
    }
}
