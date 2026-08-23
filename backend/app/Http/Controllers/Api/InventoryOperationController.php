<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\InventoryOperationService;
use App\Services\InventoryLotService;
use App\Services\FinancialAccountService;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryOperationController extends Controller
{
    public function index(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);

        $q = DB::table('inventory_operations as o')
            ->leftJoin('branches as fb', 'fb.id', '=', 'o.from_branch_id')
            ->leftJoin('branches as tb', 'tb.id', '=', 'o.to_branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->where('o.company_id', $companyId);

        if ($branchId !== null) {
            $q->where('o.from_branch_id', $branchId);
        }
        if ($request->filled('type')) {
            $q->where('o.operation_type', strtoupper((string) $request->type));
        }
        if ($request->filled('status')) {
            $q->where('o.status', strtoupper((string) $request->status));
        }
        if ($request->filled('from_date')) {
            $q->whereDate('o.operation_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $q->whereDate('o.operation_date', '<=', $request->to_date);
        }

        return response()->json([
            'status' => true,
            'data' => $q->select(
                'o.*',
                'fb.branch_name as from_branch_name',
                'tb.branch_name as to_branch_name',
                'u.name as created_by_name',
                DB::raw("(SELECT COUNT(*) FROM inventory_operation_lines l WHERE l.operation_id=o.id AND l.line_type='FROM') from_lines_count"),
                DB::raw("(SELECT COUNT(*) FROM inventory_operation_lines l WHERE l.operation_id=o.id AND l.line_type='TO') to_lines_count"),
                DB::raw("(SELECT COALESCE(SUM(total_cost),0) FROM inventory_operation_lines l WHERE l.operation_id=o.id AND l.line_type='FROM') input_cost")
            )
                ->orderByDesc('o.operation_date')
                ->orderByDesc('o.id')
                ->limit(1000)
                ->get(),
        ]);
    }

    public function meta(
        Request $request,
        AccountingContext $context,
        InventoryLotService $lots,
        FinancialAccountService $money
    ) {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);

        $branches = DB::table('branches')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->when($branchId !== null, fn ($q) => $q->where('id', $branchId))
            ->select('id', 'branch_code', 'branch_name')
            ->orderBy('branch_name')
            ->get();

        $items = DB::table('items')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->where('item_type','STOCK')->where('track_inventory',1)
            ->select('id', 'item_code', 'item_name', 'item_grade', 'unit_name', 'base_unit_code', 'commercial_unit_code')
            ->orderBy('item_name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'branches' => $branches,
                'items' => $items,
                'inventory' => $lots->summary($companyId, $branchId),
                'financial_accounts' => DB::table('financial_accounts')->where('company_id',$companyId)->where('is_active',1)
                    ->when($branchId !== null, fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$branchId)->orWhereNull('branch_id')))
                    ->select('id','branch_id','account_name','account_type','currency_code')->orderBy('account_name')->get(),
                'currencies' => DB::table('company_currencies')->where('company_id',$companyId)->where('is_active',1)->orderByDesc('is_base')->get(['currency_code','is_base']),
                'base_currency' => $money->baseCurrency($companyId),
            ],
        ]);
    }

    public function store(
        Request $request,
        InventoryOperationService $service,
        AccountingContext $context
    ) {
        $validated = $request->validate([
            'operation_type' => ['required', 'string', 'max:40'],
            'operation_date' => ['required', 'date'],
            'from_branch_id' => ['nullable', 'integer'],
            'to_branch_id' => ['nullable', 'integer'],
            'allocation_method' => ['nullable', 'in:RELATIVE_VALUE,WEIGHT,MANUAL_PERCENT,MANUAL_COST'],
            'loss_gain_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'from_lines' => ['required', 'array', 'min:1', 'max:100'],
            'from_lines.*.item_id' => ['required', 'integer'],
            'from_lines.*.qty_kg' => ['required', 'numeric', 'gt:0'],
            'from_lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'to_lines' => ['nullable', 'array', 'max:100'],
            'to_lines.*.item_id' => ['required_with:to_lines', 'integer'],
            'to_lines.*.qty_kg' => ['required_with:to_lines', 'numeric', 'gt:0'],
            'to_lines.*.allocation_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'to_lines.*.market_value_per_kg' => ['nullable', 'numeric', 'min:0'],
            'to_lines.*.total_cost' => ['nullable', 'numeric', 'min:0'],
            'to_lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'costs' => ['nullable','array','max:50'],
            'costs.*.cost_type' => ['required_with:costs','string','max:60'],
            'costs.*.amount' => ['required_with:costs','numeric','gt:0'],
            'costs.*.currency_code' => ['nullable','string','max:10'],
            'costs.*.exchange_rate' => ['nullable','numeric','gt:0'],
            'costs.*.payment_status' => ['nullable','in:PAID,UNPAID'],
            'costs.*.financial_account_id' => ['nullable','integer'],
            'costs.*.notes' => ['nullable','string','max:1000'],
        ]);

        try {
            $scoped = TenantScope::branchId($request);
            $fromBranch = $scoped ?? (int) ($validated['from_branch_id'] ?? 0);
            if (!$fromBranch) {
                throw new \RuntimeException('اختر فرع المصدر.');
            }
            TenantScope::assertBranchBelongsToCompany($fromBranch, $request);

            $toBranch = isset($validated['to_branch_id']) && (int) $validated['to_branch_id'] > 0
                ? (int) $validated['to_branch_id']
                : null;
            if ($toBranch) {
                TenantScope::assertBranchBelongsToCompany($toBranch, $request);
            }

            $id = $service->create([
                ...$validated,
                'company_id' => $context->companyId($request),
                'from_branch_id' => $fromBranch,
                'to_branch_id' => $toBranch,
                'created_by' => $context->userId($request),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم حفظ العملية كمسودة. راجعها ثم رحّلها.',
                'id' => $id,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function show(
        Request $request,
        int $id,
        InventoryOperationService $service,
        AccountingContext $context
    ) {
        $data = $service->details(
            $context->companyId($request),
            $id,
            $context->branchFilter($request)
        );

        return $data
            ? response()->json(['status' => true, 'data' => $data])
            : response()->json(['status' => false, 'message' => 'العملية غير موجودة ضمن نطاقك.'], 404);
    }

    public function approve(
        Request $request,
        int $id,
        InventoryOperationService $service,
        AccountingContext $context
    ) {
        try {
            $result = $service->approve(
                $context->companyId($request),
                $id,
                (int) $context->userId($request),
                $context->branchFilter($request)
            );

            return response()->json([
                'status' => true,
                'message' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(
        Request $request,
        int $id,
        InventoryOperationService $service,
        AccountingContext $context
    ) {
        try {
            $service->deleteDraft(
                $context->companyId($request),
                $id,
                $context->branchFilter($request)
            );
            return response()->json(['status' => true, 'message' => 'تم حذف المسودة.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
