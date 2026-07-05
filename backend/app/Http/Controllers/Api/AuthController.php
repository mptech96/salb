<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    use LogsActivity;

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $user = DB::table('users as u')
            ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 'u.branch_id')
            ->select(
                'u.*',
                'c.company_name',
                'c.is_active as company_active',
                'b.branch_name'
            )
            ->where('u.username', $request->username)
            ->where('u.password', md5($request->password))
            ->where('u.is_active', 1)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'بيانات الدخول غير صحيحة'
            ], 401);
        }

        $role = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->where('ur.is_active', 1)
            ->select('r.id', 'r.role_name', 'r.role_code')
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'لا يوجد دور مرتبط بهذا المستخدم'
            ], 403);
        }

        $permissions = DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $user->id)
            ->where('ur.is_active', 1)
            ->where('rp.is_active', 1)
            ->pluck('p.permission_code')
            ->unique()
            ->values();

        if ($role->role_code === 'SUPER_ADMIN') {
            $this->logLogin($user->id, 'تسجيل دخول مدير النظام: ' . $user->username);

            return response()->json([
                'status' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'user' => [
                    'id' => $user->id,
                    'company_id' => null,
                    'branch_id' => null,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'company_name' => 'إدارة النظام',
                    'branch_name' => 'مركز التحكم',
                    'role' => $role,
                    'permissions' => $permissions,
                ],
                'subscription' => null
            ]);
        }

        if (!$user->company_id || !$user->company_active) {
            return response()->json([
                'status' => false,
                'message' => 'الشركة غير مفعلة'
            ], 403);
        }

        $subscription = DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $user->company_id)
            ->orderByDesc('s.id')
            ->select(
                's.*',
                'p.plan_name',
                'p.plan_code',
                'p.max_branches',
                'p.max_users'
            )
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => false,
                'message' => 'لا يوجد اشتراك فعال لهذه الشركة'
            ], 403);
        }

        if ($subscription->status !== 'ACTIVE' || date('Y-m-d') > $subscription->end_date) {
            DB::table('subscriptions')
                ->where('id', $subscription->id)
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status' => false,
                'message' => 'انتهى الاشتراك، يرجى التجديد'
            ], 403);
        }

        $this->logLogin($user->id, 'تسجيل دخول المستخدم: ' . $user->username);

        return response()->json([
            'status' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'user' => [
                'id' => $user->id,
                'company_id' => $user->company_id,
                'branch_id' => $user->branch_id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'company_name' => $user->company_name,
                'branch_name' => $user->branch_name,
                'role' => $role,
                'permissions' => $permissions,
            ],
            'subscription' => [
                'plan_name' => $subscription->plan_name,
                'plan_code' => $subscription->plan_code,
                'start_date' => $subscription->start_date,
                'end_date' => $subscription->end_date,
                'max_branches' => $subscription->max_branches,
                'max_users' => $subscription->max_users,
                'status' => $subscription->status,
            ]
        ]);
    }
}