<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    use LogsActivity;

    public function index()
    {
        $data = DB::table('companies as c')
            ->leftJoin('subscriptions as s', 's.company_id', '=', 'c.id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->select(
                'c.*',
                'p.plan_name',
                's.start_date',
                's.end_date',
                's.status'
            )
            ->orderByDesc('c.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'company_name' => 'required',
            'owner_name' => 'required',
            'phone' => 'required',
            'plan_id' => 'required',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        DB::beginTransaction();

        try {
            $companyId = DB::table('companies')->insertGetId([
                'company_name' => $request->company_name,
                'owner_name' => $request->owner_name,
                'phone' => $request->phone,
                'email' => $request->email,
                'city' => $request->city,
                'address' => $request->address,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('subscriptions')->insert([
                'company_id' => $companyId,
                'plan_id' => $request->plan_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('branches')->insert([
                'company_id' => $companyId,
                'branch_name' => 'الفرع الرئيسي',
                'branch_code' => 'MAIN-' . $companyId,
                'city' => $request->city,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $this->logCreate(
                'Companies',
                $companyId,
                'تم إنشاء شركة جديدة: ' . $request->company_name
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الشركة بنجاح'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function supportAccess($id)
    {
        $company = DB::table('companies')->where('id', $id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'الشركة غير موجودة'
            ], 404);
        }

        $branch = DB::table('branches')
            ->where('company_id', $id)
            ->orderBy('id')
            ->first();

        $subscription = DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $id)
            ->orderByDesc('s.id')
            ->select(
                's.*',
                'p.plan_name',
                'p.plan_code',
                'p.max_branches',
                'p.max_users'
            )
            ->first();

        $permissions = DB::table('permissions')
            ->pluck('permission_code')
            ->values();

        $this->logSupportAccess(
            $company->id,
            'تم الدخول على الشركة كدعم فني: ' . $company->company_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم الدخول كدعم فني',
            'user' => [
                'id' => 0,
                'company_id' => $company->id,
                'branch_id' => $branch->id ?? null,
                'name' => 'دعم النظام',
                'username' => 'support',
                'company_name' => $company->company_name,
                'branch_name' => $branch->branch_name ?? 'الفرع الرئيسي',
                'role' => [
                    'role_name' => 'دعم فني',
                    'role_code' => 'SUPER_ADMIN'
                ],
                'permissions' => $permissions,
                'is_support_mode' => true,
            ],
            'subscription' => $subscription
        ]);
    }
}