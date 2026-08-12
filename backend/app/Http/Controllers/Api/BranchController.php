<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\AccountingBootstrapService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class BranchController extends Controller
{
    private const COMPANY_MANAGER_ROLES = [
        'COMPANY_MANAGER',
        'COMPANY_ADMIN',
        'MANAGER',
    ];

    private function companyId(): ?int
    {
        $value = request()->header('X-Company-ID');

        return is_numeric($value) ? (int) $value : null;
    }

    private function branchId(): ?int
    {
        $value = request()->header('X-Branch-ID');

        return is_numeric($value) ? (int) $value : null;
    }

    private function roleCode(): string
    {
        return strtoupper(
            trim((string) request()->header('X-Role-Code', ''))
        );
    }

    private function isSuper(): bool
    {
        return $this->roleCode() === 'SUPER_ADMIN';
    }

    private function isCompanyManager(): bool
    {
        return in_array(
            $this->roleCode(),
            self::COMPANY_MANAGER_ROLES,
            true
        );
    }

    private function isBranchManager(): bool
    {
        return $this->roleCode() === 'BRANCH_MANAGER';
    }

    private function canViewBranches(): bool
    {
        return $this->isSuper()
            || $this->isCompanyManager()
            || $this->isBranchManager();
    }

    private function canManageBranches(): bool
    {
        return $this->isSuper() || $this->isCompanyManager();
    }

    private function validationRules(
        int $companyId,
        ?int $ignoreBranchId = null
    ): array {
        $uniqueCodeRule = Rule::unique('branches', 'branch_code')
            ->where(
                fn ($query) => $query->where(
                    'company_id',
                    $companyId
                )
            );

        if ($ignoreBranchId !== null) {
            $uniqueCodeRule->ignore($ignoreBranchId);
        }

        return [
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],
            'branch_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'regex:/^[\p{L}\p{N}\s\-\._\(\)\']+$/u',
            ],
            'branch_code' => [
                'nullable',
                'string',
                'min:2',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                $uniqueCodeRule,
            ],
            'phone' => [
                'nullable',
                'string',
                'regex:/^[0-9]{7,15}$/',
            ],
            'city' => [
                'nullable',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\s\-\.\'\(\)]+$/u',
            ],
            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'is_active' => [
                'nullable',
                'in:0,1',
            ],
        ];
    }

    private function validationMessages(): array
    {
        return [
            'company_id.required' => 'الشركة مطلوبة.',
            'company_id.integer' => 'قيمة الشركة غير صحيحة.',
            'company_id.exists' => 'الشركة المحددة غير موجودة.',

            'branch_name.required' => 'اسم الفرع مطلوب.',
            'branch_name.min' => 'اسم الفرع يجب ألا يقل عن حرفين.',
            'branch_name.max' => 'اسم الفرع لا يمكن أن يتجاوز 255 حرفًا.',
            'branch_name.regex' => 'اسم الفرع يحتوي على رموز غير مسموحة.',

            'branch_code.min' => 'كود الفرع يجب ألا يقل عن خانتين.',
            'branch_code.max' => 'كود الفرع لا يمكن أن يتجاوز 50 خانة.',
            'branch_code.regex' => 'كود الفرع يقبل الحروف الإنجليزية والأرقام والشرطة فقط.',
            'branch_code.unique' => 'كود الفرع مستخدم مسبقًا داخل هذه الشركة.',

            'phone.regex' => 'رقم الجوال يجب أن يحتوي على أرقام فقط، من 7 إلى 15 رقمًا.',

            'city.min' => 'اسم المدينة يجب ألا يقل عن حرفين.',
            'city.max' => 'اسم المدينة لا يمكن أن يتجاوز 100 حرف.',
            'city.regex' => 'اسم المدينة يجب أن يحتوي على حروف ومسافات فقط.',

            'address.max' => 'العنوان لا يمكن أن يتجاوز 1000 حرف.',
            'is_active.in' => 'حالة الفرع غير صحيحة.',
        ];
    }

    private function normalizeRequest(
        Request $request,
        int $companyId
    ): void {
        $request->merge([
            'company_id' => $companyId,
            'branch_code' => $request->filled('branch_code')
                ? strtoupper(trim((string) $request->branch_code))
                : null,
            'branch_name' => trim((string) $request->branch_name),
            'phone' => $request->filled('phone')
                ? trim((string) $request->phone)
                : null,
            'city' => $request->filled('city')
                ? trim((string) $request->city)
                : null,
            'address' => $request->filled('address')
                ? trim((string) $request->address)
                : null,
            'is_active' => (int) ($request->is_active ?? 1),
        ]);
    }

    private function failureResponse(
        Throwable $exception,
        string $message
    ) {
        report($exception);

        $response = [
            'status' => false,
            'message' => $message,
        ];

        if (app()->isLocal()) {
            $response['technical_message'] = $exception->getMessage();
        }

        return response()->json($response, 500);
    }

    public function index()
    {
        if (!$this->canViewBranches()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية عرض الفروع.',
                'data' => [],
            ], 403);
        }

        $companyId = $this->companyId();
        $branchId = $this->branchId();

        $query = DB::table('branches as b')
            ->leftJoin(
                'companies as c',
                'c.id',
                '=',
                'b.company_id'
            )
            ->leftJoin('users as u', function ($join) {
                $join->on('u.branch_id', '=', 'b.id')
                    ->where('u.is_active', 1);
            })
            ->leftJoin('cost_centers as cc', function ($join) {
                $join->on('cc.branch_id', '=', 'b.id')
                    ->where('cc.is_active', 1);
            });

        if (!$this->isSuper()) {
            if (!$companyId) {
                return response()->json([
                    'status' => false,
                    'message' => 'تعذر تحديد الشركة الحالية.',
                    'data' => [],
                ], 403);
            }

            $query->where('b.company_id', $companyId);
        }

        if ($this->isBranchManager()) {
            if (!$branchId) {
                return response()->json([
                    'status' => false,
                    'message' => 'تعذر تحديد الفرع الحالي.',
                    'data' => [],
                ], 403);
            }

            $query->where('b.id', $branchId);
        }

        $branches = $query
            ->select(
                'b.id',
                'b.company_id',
                'b.branch_code',
                'b.branch_name',
                'b.phone',
                'b.city',
                'b.address',
                'b.is_active',
                'b.created_at',
                'b.updated_at',
                'c.company_name',
                'cc.id as cost_center_id',
                'cc.cost_center_code',
                'cc.cost_center_name',
                DB::raw(
                    'COUNT(DISTINCT u.id) as users_count'
                )
            )
            ->groupBy(
                'b.id',
                'b.company_id',
                'b.branch_code',
                'b.branch_name',
                'b.phone',
                'b.city',
                'b.address',
                'b.is_active',
                'b.created_at',
                'b.updated_at',
                'c.company_name',
                'cc.id',
                'cc.cost_center_code',
                'cc.cost_center_name'
            )
            ->orderByDesc('b.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $branches,
        ]);
    }

    public function store(
        Request $request,
        AccountingBootstrapService $bootstrap
    ) {
        if (!$this->canManageBranches()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية إنشاء فرع.',
            ], 403);
        }

        $currentCompanyId = $this->companyId();

        if (!$this->isSuper() && !$currentCompanyId) {
            return response()->json([
                'status' => false,
                'message' => 'تعذر تحديد الشركة الحالية.',
            ], 403);
        }

        $targetCompanyId = $this->isSuper()
            ? $request->integer('company_id')
            : (int) $currentCompanyId;

        if (!$targetCompanyId) {
            return response()->json([
                'status' => false,
                'message' => 'اختر الشركة التي يتبع لها الفرع.',
            ], 422);
        }

        if (
            !$this->isSuper()
            && $request->filled('company_id')
            && $request->integer('company_id') !== $targetCompanyId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بإنشاء فرع لشركة أخرى.',
            ], 403);
        }

        $this->normalizeRequest($request, $targetCompanyId);

        $validated = $request->validate(
            $this->validationRules($targetCompanyId),
            $this->validationMessages()
        );

        DB::beginTransaction();

        try {
            $branchId = DB::table('branches')->insertGetId([
                'company_id' => $targetCompanyId,
                'branch_code' => $validated['branch_code'] ?? null,
                'branch_name' => $validated['branch_name'],
                'phone' => $validated['phone'] ?? null,
                'city' => $validated['city'] ?? null,
                'address' => $validated['address'] ?? null,
                'is_active' => (int) ($validated['is_active'] ?? 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $costCenterId = $bootstrap->bootstrapBranch(
                $targetCompanyId,
                $branchId,
                $validated['branch_name']
            );

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الفرع ومركز التكلفة بنجاح.',
                'data' => [
                    'branch_id' => $branchId,
                    'cost_center_id' => $costCenterId,
                ],
            ], 201);
        } catch (Throwable $exception) {
            DB::rollBack();

            return $this->failureResponse(
                $exception,
                'تعذر إنشاء الفرع. تحقق من البيانات وحاول مرة أخرى.'
            );
        }
    }

    public function show($id)
    {
        if (!$this->canViewBranches()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية عرض الفرع.',
            ], 403);
        }

        $query = DB::table('branches as b')
            ->leftJoin(
                'companies as c',
                'c.id',
                '=',
                'b.company_id'
            )
            ->leftJoin(
                'cost_centers as cc',
                'cc.branch_id',
                '=',
                'b.id'
            )
            ->select(
                'b.*',
                'c.company_name',
                'cc.id as cost_center_id',
                'cc.cost_center_code',
                'cc.cost_center_name'
            )
            ->where('b.id', $id);

        if (!$this->isSuper()) {
            $query->where('b.company_id', $this->companyId());
        }

        if ($this->isBranchManager()) {
            $query->where('b.id', $this->branchId());
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود أو غير مسموح بعرضه.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $branch,
        ]);
    }

    public function update(
        Request $request,
        $id,
        AccountingBootstrapService $bootstrap
    ) {
        if (!$this->canManageBranches()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية تعديل الفرع.',
            ], 403);
        }

        $query = DB::table('branches')->where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $this->companyId());
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود أو غير مسموح بتعديله.',
            ], 404);
        }

        $targetCompanyId = (int) $branch->company_id;

        if (
            $request->filled('company_id')
            && $request->integer('company_id') !== $targetCompanyId
        ) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن نقل الفرع من شركة إلى شركة أخرى.',
            ], 422);
        }

        $this->normalizeRequest($request, $targetCompanyId);

        $validated = $request->validate(
            $this->validationRules(
                $targetCompanyId,
                (int) $id
            ),
            $this->validationMessages()
        );

        DB::beginTransaction();

        try {
            DB::table('branches')
                ->where('id', $id)
                ->where('company_id', $targetCompanyId)
                ->update([
                    'branch_code' => $validated['branch_code'] ?? null,
                    'branch_name' => $validated['branch_name'],
                    'phone' => $validated['phone'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'is_active' => (int) ($validated['is_active'] ?? 1),
                    'updated_at' => now(),
                ]);

            $costCenter = DB::table('cost_centers')
                ->where('company_id', $targetCompanyId)
                ->where('branch_id', $id)
                ->first();

            if ($costCenter) {
                DB::table('cost_centers')
                    ->where('id', $costCenter->id)
                    ->update([
                        'cost_center_name' =>
                            'مركز تكلفة ' . $validated['branch_name'],
                        'is_active' => (int) ($validated['is_active'] ?? 1),
                        'updated_at' => now(),
                    ]);

                $costCenterId = $costCenter->id;
            } else {
                $costCenterId = $bootstrap->bootstrapBranch(
                    $targetCompanyId,
                    (int) $id,
                    $validated['branch_name']
                );
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث الفرع ومركز التكلفة بنجاح.',
                'data' => [
                    'branch_id' => (int) $id,
                    'cost_center_id' => $costCenterId,
                ],
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return $this->failureResponse(
                $exception,
                'تعذر تحديث الفرع. تحقق من البيانات وحاول مرة أخرى.'
            );
        }
    }

    public function destroy($id)
    {
        if (!$this->canManageBranches()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية حذف الفرع.',
            ], 403);
        }

        $query = DB::table('branches')->where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $this->companyId());
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود أو غير مسموح بحذفه.',
            ], 404);
        }

        $relatedTables = [
            ['users', 'branch_id', 'مستخدمين'],
            ['journal_entries', 'branch_id', 'قيود محاسبية'],
            ['purchase_invoices', 'branch_id', 'فواتير مشتريات'],
            ['sales_invoices', 'branch_id', 'فواتير مبيعات'],
            ['shipments', 'branch_id', 'شحنات'],
            ['stock_movements', 'branch_id', 'حركات مخزون'],
            ['inventory_operations', 'branch_id', 'عمليات مخزنية'],
            ['suppliers', 'branch_id', 'موردين'],
            ['customers', 'branch_id', 'عملاء'],
            ['expenses', 'branch_id', 'مصروفات'],
            ['vouchers', 'branch_id', 'سندات مالية'],
            ['workers', 'branch_id', 'عاملين'],
            ['fixed_assets', 'branch_id', 'أصول ثابتة'],
        ];

        foreach ($relatedTables as [$table, $column, $label]) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $id)->exists()
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        "لا يمكن حذف الفرع لأنه مرتبط بـ {$label}. "
                        . 'عطّل الفرع بدلًا من حذفه.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            DB::table('cost_centers')
                ->where('branch_id', $id)
                ->delete();

            DB::table('branches')
                ->where('id', $id)
                ->where('company_id', $branch->company_id)
                ->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تم حذف الفرع ومركز التكلفة بنجاح.',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return $this->failureResponse(
                $exception,
                'لا يمكن حذف الفرع لأنه مرتبط ببيانات أخرى. عطّل الفرع بدلًا من حذفه.'
            );
        }
    }
}