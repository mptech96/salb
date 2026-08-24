<?php

namespace App\Http\Middleware;

use App\Services\Tenant\TenantResourceScopeService;
use App\Support\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantScope
{
    public function __construct(private readonly TenantResourceScopeService $resources) {}

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
        $this->resources->assertRequest($request, $companyId, $branchId);

        return $next($request);
    }

    private function assertStatementTarget(Request $request, int $companyId, ?int $branchId): void
    {
        $uri = $this->resources->normalizedRouteUri($request);
        if (!str_starts_with($uri, 'statements/')) return;
        $parts = explode('/', $uri);
        $type = $parts[1] ?? '';
        $table = ['supplier' => 'suppliers', 'customer' => 'customers', 'driver' => 'drivers', 'worker' => 'workers'][$type] ?? null;
        $id = $request->route('id');
        if (!$table || !$id || !is_numeric($id)) return;
        $this->resources->assertOwned($table, (int) $id, $companyId, $branchId, true);
    }
}
