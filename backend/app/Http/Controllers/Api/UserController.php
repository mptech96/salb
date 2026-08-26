<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    private const SUPER_ADMIN = 'SUPER_ADMIN';
    private const BRANCH_MANAGER = 'BRANCH_MANAGER';

    private const COMPANY_MANAGER_ROLES = [
        'COMPANY_MANAGER',
        'COMPANY_ADMIN',
        'COMPANY_OWNER',
        'MANAGER',
    ];

    private function companyId(): ?int
    {
        $value = request()->attributes->get(
            'tenant_company_id',
            request()->header('X-Company-ID')
        );

        return is_numeric($value) ? (int) $value : null;
    }

    private function branchId(): ?int
    {
        $value = request()->attributes->get(
            'tenant_branch_id',
            request()->header('X-Branch-ID')
        );

        return is_numeric($value) ? (int) $value : null;
    }

    private function currentUserId(): ?int
    {
        $value = request()->attributes->get(
            'authenticated_user_id',
            request()->header('X-User-ID')
        );

        return is_numeric($value) ? (int) $value : null;
    }

    private function roleCode(): string
    {
        return strtoupper(trim((string) request()->attributes->get(
            'effective_role_code',
            request()->header('X-Role-Code', '')
        )));
    }

    private function isSuper(): bool
    {
        return $this->roleCode() === self::SUPER_ADMIN;
    }

    private function isCompanyManager(): bool
    {
        return in_array($this->roleCode(), self::COMPANY_MANAGER_ROLES, true);
    }

    private function isBranchManager(): bool
    {
        return $this->roleCode() === self::BRANCH_MANAGER;
    }

    private function canManageUsers(): bool
    {
        return $this->isSuper()
            || $this->isCompanyManager()
            || $this->isBranchManager();
    }

    private function forbidden(string $message)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], 403);
    }

    private function roleIsAllowed(int $roleId): bool
    {
        $selectedRole = DB::table('roles')
            ->where('id', $roleId)
            ->value('role_code');

        if (!$selectedRole) {
            return false;
        }

        $selectedRole = strtoupper(trim((string) $selectedRole));

        if ($this->isSuper()) {
            return true;
        }

        if ($this->isCompanyManager()) {
            return $selectedRole !== self::SUPER_ADMIN;
        }

        if ($this->isBranchManager()) {
            $blockedRoles = array_merge(
                [self::SUPER_ADMIN, self::BRANCH_MANAGER],
                self::COMPANY_MANAGER_ROLES
            );

            return !in_array($selectedRole, $blockedRoles, true);
        }

        return false;
    }

    private function effectiveCompanyId(Request $request): ?int
    {
        if ($this->isSuper()) {
            return $request->filled('company_id')
                ? $request->integer('company_id')
                : null;
        }

        return $this->companyId();
    }

    private function effectiveBranchId(Request $request): ?int
    {
        if ($this->isBranchManager()) {
            return $this->branchId();
        }

        return $request->filled('branch_id')
            ? $request->integer('branch_id')
            : null;
    }

    private function validationRules(Request $request, ?int $userId = null): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query->where(
                        'company_id',
                        $request->integer('company_id')
                    )
                ),
            ],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                "regex:/^[\p{L}\s.'-]+$/u",
            ],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Za-z0-9._-]+$/',
                $userId
                    ? Rule::unique('users', 'username')->ignore($userId)
                    : Rule::unique('users', 'username'),
            ],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => [
                'nullable',
                'string',
                'regex:/^[0-9]{7,15}$/',
            ],
            'password' => $userId
                ? ['nullable', 'string', 'min:6', 'max:100']
                : ['required', 'string', 'min:6', 'max:100'],
            'is_active' => ['nullable', 'in:0,1'],
        ];
    }

    private function validationMessages(): array
    {
        return [
            'company_id.required' => 'الشركة مطلوبة.',
            'company_id.integer' => 'قيمة الشركة غير صحيحة.',
            'company_id.exists' => 'الشركة المحددة غير موجودة.',
            'branch_id.required' => 'الفرع مطلوب.',
            'branch_id.integer' => 'قيمة الفرع غير صحيحة.',
            'branch_id.exists' => 'الفرع المحدد لا يتبع الشركة المختارة.',
            'role_id.required' => 'الدور مطلوب.',
            'role_id.integer' => 'قيمة الدور غير صحيحة.',
            'role_id.exists' => 'الدور المحدد غير موجود.',
            'name.required' => 'اسم المستخدم مطلوب.',
            'name.min' => 'اسم المستخدم يجب ألا يقل عن 3 أحرف.',
            'name.regex' => 'اسم المستخدم يجب أن يحتوي على حروف ومسافات فقط.',
            'username.required' => 'اسم الدخول مطلوب.',
            'username.min' => 'اسم الدخول يجب ألا يقل عن 3 خانات.',
            'username.max' => 'اسم الدخول يجب ألا يزيد على 50 خانة.',
            'username.regex' => 'اسم الدخول يقبل الحروف الإنجليزية والأرقام والنقطة والشرطة فقط.',
            'username.unique' => 'اسم الدخول مستخدم مسبقًا.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'phone.regex' => 'رقم الجوال يجب أن يحتوي على أرقام فقط، من 7 إلى 15 رقمًا.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 6 خانات.',
            'password.max' => 'كلمة المرور طويلة جدًا.',
            'is_active.in' => 'حالة المستخدم غير صحيحة.',
        ];
    }

    public function index()
    {
        if (!$this->canManageUsers()) {
            return $this->forbidden('لا تملك صلاحية عرض وإدارة المستخدمين.');
        }

        $companyId = $this->companyId();
        $branchId = $this->branchId();

        $activeRoles = DB::table('user_roles')
            ->selectRaw('MAX(id) as id, user_id')
            ->where('is_active', 1)
            ->groupBy('user_id');

        $query = DB::table('users as u')
            ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 'u.branch_id')
            ->leftJoinSub($activeRoles, 'active_ur', function ($join) {
                $join->on('active_ur.user_id', '=', 'u.id');
            })
            ->leftJoin('user_roles as ur', 'ur.id', '=', 'active_ur.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id');

        if (!$this->isSuper()) {
            if (!$companyId) {
                return $this->forbidden('تعذر تحديد الشركة الحالية.');
            }

            $query->where('u.company_id', $companyId);
        }

        if ($this->isBranchManager()) {
            if (!$branchId) {
                return $this->forbidden('تعذر تحديد الفرع الحالي.');
            }

            $query->where('u.branch_id', $branchId);
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
            'data' => $data,
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->canManageUsers()) {
            return $this->forbidden('لا تملك صلاحية إنشاء مستخدمين.');
        }

        $companyId = $this->effectiveCompanyId($request);
        $branchId = $this->effectiveBranchId($request);

        if (!$companyId) {
            return $this->forbidden('تعذر تحديد الشركة التي سيُنشأ المستخدم داخلها.');
        }

        if (!$branchId) {
            return response()->json([
                'status' => false,
                'message' => 'اختر الفرع.',
            ], 422);
        }

        $request->merge([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);

        $validated = $request->validate(
            $this->validationRules($request),
            $this->validationMessages()
        );

        if (!$this->roleIsAllowed((int) $validated['role_id'])) {
            return $this->forbidden('غير مسموح لك بمنح الدور المحدد.');
        }

        try {
            $userId = DB::transaction(function () use ($validated) {
                $userId = DB::table('users')->insertGetId([
                    'company_id' => (int) $validated['company_id'],
                    'branch_id' => (int) $validated['branch_id'],
                    'name' => trim((string) $validated['name']),
                    'username' => trim((string) $validated['username']),
                    'email' => !empty($validated['email'])
                        ? trim((string) $validated['email'])
                        : null,
                    'phone' => !empty($validated['phone'])
                        ? trim((string) $validated['phone'])
                        : null,
                    'password' => Hash::make((string) $validated['password']),
                    'is_active' => (int) ($validated['is_active'] ?? 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('user_roles')->insert([
                    'company_id' => (int) $validated['company_id'],
                    'user_id' => $userId,
                    'role_id' => (int) $validated['role_id'],
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $userId;
            });

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء المستخدم وربطه بالشركة والفرع بنجاح.',
                'id' => $userId,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'تعذر إنشاء المستخدم. راجع البيانات ثم حاول مرة أخرى.',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        if (!$this->canManageUsers()) {
            return $this->forbidden('لا تملك صلاحية تعديل المستخدمين.');
        }

        $companyId = $this->effectiveCompanyId($request);
        $branchId = $this->effectiveBranchId($request);

        if (!$companyId || !$branchId) {
            return $this->forbidden('تعذر تحديد الشركة أو الفرع الحالي.');
        }

        $userQuery = DB::table('users')->where('id', $id);

        if (!$this->isSuper()) {
            $userQuery->where('company_id', $companyId);
        }

        if ($this->isBranchManager()) {
            $userQuery->where('branch_id', $this->branchId());
        }

        $existingUser = $userQuery->first();

        if (!$existingUser) {
            return response()->json([
                'status' => false,
                'message' => 'المستخدم غير موجود أو لا تملك صلاحية تعديله.',
            ], 404);
        }

        $request->merge([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);

        $validated = $request->validate(
            $this->validationRules($request, (int) $id),
            $this->validationMessages()
        );

        if (!$this->roleIsAllowed((int) $validated['role_id'])) {
            return $this->forbidden('غير مسموح لك بمنح الدور المحدد.');
        }

        try {
            DB::transaction(function () use ($validated, $id) {
                $updateData = [
                    'company_id' => (int) $validated['company_id'],
                    'branch_id' => (int) $validated['branch_id'],
                    'name' => trim((string) $validated['name']),
                    'username' => trim((string) $validated['username']),
                    'email' => !empty($validated['email'])
                        ? trim((string) $validated['email'])
                        : null,
                    'phone' => !empty($validated['phone'])
                        ? trim((string) $validated['phone'])
                        : null,
                    'is_active' => (int) ($validated['is_active'] ?? 1),
                    'updated_at' => now(),
                ];

                if (!empty($validated['password'])) {
                    $updateData['password'] = Hash::make((string) $validated['password']);
                }

                DB::table('users')->where('id', $id)->update($updateData);

                DB::table('user_roles')
                    ->where('user_id', $id)
                    ->where('is_active', 1)
                    ->update([
                        'is_active' => 0,
                        'updated_at' => now(),
                    ]);

                DB::table('user_roles')->insert([
                    'company_id' => (int) $validated['company_id'],
                    'user_id' => (int) $id,
                    'role_id' => (int) $validated['role_id'],
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return response()->json([
                'status' => true,
                'message' => 'تم تعديل المستخدم بنجاح.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تعديل المستخدم. راجع البيانات ثم حاول مرة أخرى.',
            ], 500);
        }
    }

    public function destroy($id)
    {
        if (!$this->canManageUsers()) {
            return $this->forbidden('لا تملك صلاحية تعطيل المستخدمين.');
        }

        $companyId = $this->companyId();
        $branchId = $this->branchId();
        $currentUserId = $this->currentUserId();

        if ($currentUserId && (int) $id === $currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكنك تعطيل حسابك الحالي.',
            ], 422);
        }

        $query = DB::table('users')->where('id', $id);

        if (!$this->isSuper()) {
            if (!$companyId) {
                return $this->forbidden('تعذر تحديد الشركة الحالية.');
            }

            $query->where('company_id', $companyId);
        }

        if ($this->isBranchManager()) {
            if (!$branchId) {
                return $this->forbidden('تعذر تحديد الفرع الحالي.');
            }

            $query->where('branch_id', $branchId);
        }

        $updated = $query->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        if (!$updated) {
            return response()->json([
                'status' => false,
                'message' => 'المستخدم غير موجود أو غير مسموح بتعطيله.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم تعطيل المستخدم بنجاح.',
        ]);
    }
}
