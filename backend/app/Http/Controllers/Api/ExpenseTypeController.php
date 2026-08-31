<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseTypeController extends Controller
{
    public function index(Request $request, AccountingContext $context): JsonResponse
    {
        $companyId = $context->companyId($request);
        $rows = DB::table('expense_types as t')
            ->leftJoin('accounts as a', function ($join) use ($companyId): void {
                $join->on('a.id', '=', 't.account_id')->where('a.company_id', '=', $companyId);
            })
            ->where(fn ($query) => $query->whereNull('t.company_id')->orWhere('t.company_id', $companyId))
            ->select('t.*', 'a.account_code', 'a.account_name', DB::raw('CASE WHEN t.company_id IS NULL THEN 1 ELSE 0 END as is_system'))
            ->orderByRaw('CASE WHEN t.company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('t.type_name')
            ->get();

        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function accounts(Request $request, AccountingContext $context): JsonResponse
    {
        $rows = DB::table('accounts')
            ->where('company_id', $context->companyId($request))
            ->where('account_type', 'EXPENSE')->where('is_active', 1)
            ->where('is_group', 0)->where('allow_posting', 1)
            ->orderBy('account_code')->get(['id', 'account_code', 'account_name']);

        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function store(Request $request, AccountingContext $context): JsonResponse
    {
        $companyId = $context->companyId($request);
        $data = $this->validated($request);
        if (! $this->validExpenseAccount($companyId, (int) $data['account_id'])) {
            return response()->json(['status' => false, 'message' => 'حساب المصروف غير صالح أو لا يتبع الشركة الحالية.'], 422);
        }
        $code = $this->normalizeCode($data['type_code'] ?? null);
        if ($code !== null && $this->codeExists($companyId, $code)) {
            return response()->json(['status' => false, 'message' => 'كود نوع المصروف مستخدم مسبقًا.'], 422);
        }

        $id = DB::transaction(function () use ($companyId, $data, $code): int {
            $id = DB::table('expense_types')->insertGetId([
                'company_id' => $companyId, 'type_name' => trim($data['type_name']), 'type_code' => $code,
                'account_id' => (int) $data['account_id'], 'default_scope' => $data['default_scope'] ?? 'GENERAL',
                'affects_cost' => (int) ($data['affects_cost'] ?? 1), 'usage_type' => $data['usage_type'] ?? 'GENERAL',
                'description' => $this->nullableText($data['description'] ?? null), 'is_active' => (int) ($data['is_active'] ?? 1),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($code === null) DB::table('expense_types')->where('id', $id)->update(['type_code' => 'EXP-'.$companyId.'-'.$id]);
            return $id;
        });

        return response()->json(['status' => true, 'message' => 'تم إنشاء نوع المصروف.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id, AccountingContext $context): JsonResponse
    {
        $companyId = $context->companyId($request);
        $type = DB::table('expense_types')->where('id', $id)->first();
        if (! $type || ($type->company_id !== null && (int) $type->company_id !== $companyId)) {
            return response()->json(['status' => false, 'message' => 'نوع المصروف غير موجود.'], 404);
        }
        if ($type->company_id === null) {
            return response()->json(['status' => false, 'message' => 'نوع المصروف النظامي متاح للقراءة فقط ولا يمكن تعديله.'], 403);
        }

        $data = $this->validated($request);
        if (! $this->validExpenseAccount($companyId, (int) $data['account_id'])) {
            return response()->json(['status' => false, 'message' => 'حساب المصروف غير صالح أو لا يتبع الشركة الحالية.'], 422);
        }
        $code = $this->normalizeCode($data['type_code'] ?? null) ?? (string) $type->type_code;
        if ($this->codeExists($companyId, $code, $id)) {
            return response()->json(['status' => false, 'message' => 'كود نوع المصروف مستخدم مسبقًا.'], 422);
        }

        DB::table('expense_types')->where('company_id', $companyId)->where('id', $id)->update([
            'type_name' => trim($data['type_name']), 'type_code' => $code, 'account_id' => (int) $data['account_id'],
            'default_scope' => $data['default_scope'] ?? 'GENERAL', 'affects_cost' => (int) ($data['affects_cost'] ?? 1),
            'usage_type' => $data['usage_type'] ?? $type->usage_type ?? 'GENERAL',
            'description' => $this->nullableText($data['description'] ?? null), 'is_active' => (int) ($data['is_active'] ?? 1),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'تم تحديث نوع المصروف.']);
    }

    public function destroy(Request $request, int $id, AccountingContext $context): JsonResponse
    {
        $companyId = $context->companyId($request);
        $type = DB::table('expense_types')->where('id', $id)->first();
        if (! $type || ($type->company_id !== null && (int) $type->company_id !== $companyId)) {
            return response()->json(['status' => false, 'message' => 'نوع المصروف غير موجود.'], 404);
        }
        if ($type->company_id === null) {
            return response()->json(['status' => false, 'message' => 'نوع المصروف النظامي لا يمكن تعطيله أو حذفه من شركة مشتركة.'], 403);
        }

        $used = DB::table('expenses')->where('company_id', $companyId)->where('expense_type_id', $id)->exists()
            || DB::table('shipment_costs')->where('company_id', $companyId)->where('expense_type_id', $id)->exists();
        DB::table('expense_types')->where('company_id', $companyId)->where('id', $id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return response()->json([
            'status' => true,
            'message' => $used ? 'نوع المصروف مستخدم في معاملات سابقة؛ تم تعطيله مع الحفاظ على السجل المالي.' : 'تم تعطيل نوع المصروف دون حذف السجل.',
            'data' => ['is_active' => 0, 'was_used' => $used],
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type_name' => ['required', 'string', 'max:150'],
            'type_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'account_id' => ['required', 'integer'],
            'default_scope' => ['nullable', Rule::in(['GENERAL', 'SHIPMENT', 'CAR', 'PURCHASE_INVOICE', 'SALES_INVOICE', 'DRIVER', 'WORKER'])],
            'affects_cost' => ['nullable', 'boolean'], 'usage_type' => ['nullable', Rule::in(['GENERAL', 'SHIPMENT', 'BOTH'])],
            'description' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function validExpenseAccount(int $companyId, int $accountId): bool
    {
        return $accountId > 0 && DB::table('accounts')->where('id', $accountId)->where('company_id', $companyId)
            ->where('account_type', 'EXPENSE')->where('is_active', 1)->where('is_group', 0)->where('allow_posting', 1)->exists();
    }

    private function codeExists(int $companyId, string $code, ?int $exceptId = null): bool
    {
        return DB::table('expense_types')->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->whereRaw('UPPER(type_code) = ?', [$code])->when($exceptId, fn ($query) => $query->where('id', '<>', $exceptId))->exists();
    }

    private function normalizeCode(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' ? null : $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
