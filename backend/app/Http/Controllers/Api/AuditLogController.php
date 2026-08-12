<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->attributes->get('tenant_company_id');
        $branchId = $request->attributes->get('tenant_branch_id');
        $actualRole = strtoupper((string) $request->attributes->get('actual_role_code', ''));
        $effectiveRole = strtoupper((string) $request->attributes->get('effective_role_code', ''));
        $isSupportMode = (bool) $request->attributes->get('is_support_mode', false);
        $isPlatformAdmin = $actualRole === 'SUPER_ADMIN' && !$isSupportMode;

        $query = DB::table('audit_logs as a')
            ->leftJoin('companies as c', 'c.id', '=', 'a.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select(
                'a.*',
                'c.company_name',
                'b.branch_name',
                'u.name as user_name',
                'u.username'
            );

        if ($isPlatformAdmin) {
            if ($request->filled('company_id')) {
                $query->where('a.company_id', $request->integer('company_id'));
            }
        } else {
            if (!$companyId) {
                return response()->json([
                    'status' => false,
                    'message' => 'تعذر تحديد الشركة الحالية.',
                ], 403);
            }

            $query->where('a.company_id', $companyId);

            if ($effectiveRole === 'BRANCH_MANAGER' && $branchId) {
                $query->where('a.branch_id', $branchId);
            }
        }

        if ($request->filled('module_name')) {
            $query->where('a.module_name', $request->module_name);
        }

        if ($request->filled('action_type')) {
            $query->where('a.action_type', $request->action_type);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('a.description', 'like', "%{$search}%")
                    ->orWhere('c.company_name', 'like', "%{$search}%")
                    ->orWhere('u.name', 'like', "%{$search}%")
                    ->orWhere('u.username', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => true,
            'data' => $query
                ->orderByDesc('a.id')
                ->limit(300)
                ->get(),
        ]);
    }
}
