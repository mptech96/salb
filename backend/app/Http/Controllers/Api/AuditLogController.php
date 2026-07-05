<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
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

        if ($request->company_id) {
            $query->where('a.company_id', $request->company_id);
        }

        if ($request->module_name) {
            $query->where('a.module_name', $request->module_name);
        }

        if ($request->action_type) {
            $query->where('a.action_type', $request->action_type);
        }

        if ($request->search) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('a.description', 'like', "%{$search}%")
                  ->orWhere('c.company_name', 'like', "%{$search}%")
                  ->orWhere('u.name', 'like', "%{$search}%")
                  ->orWhere('u.username', 'like', "%{$search}%");
            });
        }

        $data = $query
            ->orderByDesc('a.id')
            ->limit(300)
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}