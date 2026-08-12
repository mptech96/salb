<?php

namespace App\Http\Middleware;

use App\Support\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantScope
{
    private const ROUTE_RESOURCES = [
        'cars' => ['table' => 'cars', 'param' => 'car', 'branch' => true],
        'suppliers' => ['table' => 'suppliers', 'param' => 'supplier', 'branch' => true],
        'customers' => ['table' => 'customers', 'param' => 'customer', 'branch' => true],
        'drivers' => ['table' => 'drivers', 'param' => 'driver', 'branch' => true],
        'workers' => ['table' => 'workers', 'param' => 'worker', 'branch' => true],
        'expenses' => ['table' => 'expenses', 'param' => 'expense', 'branch' => true],
        'vouchers' => ['table' => 'vouchers', 'param' => 'voucher', 'branch' => true],
        'purchase-invoices' => ['table' => 'purchase_invoices', 'param' => 'purchase_invoice', 'branch' => true],
        'sales-invoices' => ['table' => 'sales_invoices', 'param' => 'sales_invoice', 'branch' => true],
        'shipments' => ['table' => 'shipments', 'param' => 'shipment', 'branch' => true],
        'items' => ['table' => 'items', 'param' => 'item', 'branch' => false],
        'fixed-assets' => ['table' => 'fixed_assets', 'param' => 'id', 'branch' => true],
        'official-documents' => ['table' => 'official_documents', 'param' => 'id', 'branch' => true],
        'payroll' => ['table' => 'worker_salary_runs', 'param' => 'id', 'branch' => true],
    ];

    private const FOREIGN_KEYS = [
        'car_id' => ['cars', true], 'supplier_id' => ['suppliers', true], 'customer_id' => ['customers', true],
        'driver_id' => ['drivers', true], 'worker_id' => ['workers', true], 'shipment_id' => ['shipments', true],
        'purchase_invoice_id' => ['purchase_invoices', true], 'sales_invoice_id' => ['sales_invoices', true],
        'item_id' => ['items', false],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        // لا نثق إطلاقًا في company_id القادم من المتصفح.
        $request->merge(['company_id' => $companyId]);

        // المستخدم المقيد بفرع لا يستطيع اختيار فرع آخر حتى لو عدّل الطلب يدويًا.
        if ($branchId !== null) {
            $request->merge(['branch_id' => $branchId]);
            $request->query->set('branch_id', $branchId);
        } elseif ($request->filled('branch_id')) {
            TenantScope::assertBranchBelongsToCompany((int) $request->input('branch_id'), $request);
        }

        if ($request->filled('to_branch_id')) {
            $toBranchId = (int) $request->input('to_branch_id');
            TenantScope::assertBranchBelongsToCompany($toBranchId, $request);
            if ($branchId !== null && $toBranchId !== $branchId) {
                abort(response()->json(['status' => false, 'code' => 'CROSS_BRANCH_TRANSFER_DENIED', 'message' => 'مدير أو مستخدم الفرع لا يستطيع نقل السجل إلى فرع آخر.'], 403));
            }
        }

        $this->assertStatementTarget($request, $companyId, $branchId);
        $this->assertRouteResource($request, $companyId, $branchId);
        $this->assertForeignKeys($request, $companyId, $branchId);

        return $next($request);
    }

    private function assertRouteResource(Request $request, int $companyId, ?int $branchId): void
    {
        $uri = trim((string) optional($request->route())->uri(), '/');
        $first = explode('/', $uri)[0] ?? '';
        $map = self::ROUTE_RESOURCES[$first] ?? null;
        if (!$map) return;

        $value = $request->route($map['param']);
        if (is_object($value) && isset($value->id)) $value = $value->id;
        if (!$value && $request->route('id')) $value = $request->route('id');
        if (!$value || !is_numeric($value)) return;

        $query = DB::table($map['table'])->where('id', (int) $value)->where('company_id', $companyId);
        if ($map['branch'] && $branchId !== null) $query->where('branch_id', $branchId);
        if (!$query->exists()) abort(response()->json([
            'status' => false, 'code' => 'RESOURCE_OUT_OF_SCOPE', 'message' => 'السجل المطلوب خارج نطاق الشركة أو الفرع المسموح لك.',
        ], 403));
    }

    private function assertStatementTarget(Request $request, int $companyId, ?int $branchId): void
    {
        $uri = trim((string) optional($request->route())->uri(), '/');
        if (!str_starts_with($uri, 'statements/')) return;
        $parts = explode('/', $uri);
        $type = $parts[1] ?? '';
        $table = ['supplier' => 'suppliers', 'customer' => 'customers', 'driver' => 'drivers', 'worker' => 'workers'][$type] ?? null;
        $id = $request->route('id');
        if (!$table || !$id || !is_numeric($id)) return;
        $q = DB::table($table)->where('id', (int) $id)->where('company_id', $companyId);
        if ($branchId !== null) $q->where('branch_id', $branchId);
        if (!$q->exists()) abort(response()->json(['status' => false, 'code' => 'STATEMENT_OUT_OF_SCOPE', 'message' => 'كشف الحساب المطلوب خارج نطاق فرعك.'], 403));
    }

    private function assertForeignKeys(Request $request, int $companyId, ?int $branchId): void
    {
        foreach (self::FOREIGN_KEYS as $field => [$table, $branchScoped]) {
            if (!$request->filled($field)) continue;
            $id = (int) $request->input($field);
            if ($id <= 0) continue;
            $query = DB::table($table)->where('id', $id)->where('company_id', $companyId);
            if ($branchScoped && $branchId !== null) $query->where('branch_id', $branchId);
            if (!$query->exists()) abort(response()->json([
                'status' => false, 'code' => 'FOREIGN_RESOURCE_OUT_OF_SCOPE', 'message' => "القيمة المحددة في {$field} خارج نطاقك المسموح.",
            ], 403));
        }
    }
}
