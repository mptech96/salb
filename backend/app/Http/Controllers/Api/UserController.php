<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function isSuper()
    {
        return request()->header('X-Role-Code') === 'SUPER_ADMIN';
    }

    public function index()
    {
        $companyId = $this->companyId();

        $query = DB::table('users as u')
            ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 'u.branch_id')
            ->leftJoin('user_roles as ur', function ($join) {
                $join->on('ur.user_id', '=', 'u.id')
                    ->where('ur.is_active', 1);
            })
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id');

        if (!$this->isSuper()) {
            $query->where('u.company_id', $companyId);
        }

        $data = $query
            ->select(
                'u.id',
                'u.company_id',
                'u.branch_id',
                'u.name',
                'u.username',
                'u.email',
                'u.phone',
                'u.is_active',
                'c.company_name',
                'b.branch_name',
                'r.id as role_id',
                'r.role_name',
                'r.role_code'
            )
            ->orderByDesc('u.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $currentCompanyId = $this->companyId();

        $request->validate([
            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'role_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:150|unique:users,username',
            'password' => 'required|string|min:4',
        ]);

        if (!$this->isSuper() && (int)$request->company_id !== (int)$currentCompanyId) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بإنشاء مستخدم لشركة أخرى'
            ], 403);
        }

        DB::beginTransaction();

        try {
            $userId = DB::table('users')->insertGetId([
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'name' => $request->name,
                'username' => $request->username,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => MD5($request->password),
                'is_active' => $request->is_active ?? 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_roles')->insert([
                'company_id' => $request->company_id,
                'user_id' => $userId,
                'role_id' => $request->role_id,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء المستخدم',
                'id' => $userId
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $currentCompanyId = $this->companyId();

        $request->validate([
            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'role_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:150|unique:users,username,' . $id,
        ]);

        if (!$this->isSuper() && (int)$request->company_id !== (int)$currentCompanyId) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بتعديل مستخدم لشركة أخرى'
            ], 403);
        }

        $existsQuery = DB::table('users')->where('id', $id);

        if (!$this->isSuper()) {
            $existsQuery->where('company_id', $currentCompanyId);
        }

        if (!$existsQuery->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'المستخدم غير موجود'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $updateData = [
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
                'name' => $request->name,
                'username' => $request->username,
                'email' => $request->email,
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? 1,
                'updated_at' => now(),
            ];

            if ($request->password) {
                $updateData['password'] = MD5($request->password);
            }

            DB::table('users')->where('id', $id)->update($updateData);

            DB::table('user_roles')
                ->where('user_id', $id)
                ->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);

            DB::table('user_roles')->insert([
                'company_id' => $request->company_id,
                'user_id' => $id,
                'role_id' => $request->role_id,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تم تعديل المستخدم'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $query = DB::table('users')->where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $companyId);
        }

        $query->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم تعطيل المستخدم'
        ]);
    }
}