<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class SupplierController extends Controller
{
    use LogsActivity;

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
        return strtoupper(trim((string) request()->header('X-Role-Code', '')));
    }

    private function isSuper(): bool
    {
        return $this->roleCode() === 'SUPER_ADMIN';
    }

    private function isCompanyManager(): bool
    {
        return $this->roleCode() === 'MANAGER';
    }

    private function canManageSuppliers(): bool
    {
        return in_array(
            $this->roleCode(),
            ['SUPER_ADMIN', 'MANAGER', 'ACCOUNTANT'],
            true
        );
    }

    private function resolveTargetBranch(Request $request): ?int
    {
        if ($this->isSuper() || $this->isCompanyManager()) {
            return $request->filled('branch_id')
                ? $request->integer('branch_id')
                : $this->branchId();
        }

        return $this->branchId();
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->branchId();

        $query = DB::table('suppliers as s')
            ->leftJoin('companies as c', 'c.id', '=', 's.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->select(
                's.*',
                'c.company_name',
                'b.branch_name'
            );

        if ($this->isSuper()) {
            if ($request->filled('company_id')) {
                $query->where('s.company_id', $request->integer('company_id'));
            }

            if ($request->filled('branch_id')) {
                $query->where('s.branch_id', $request->integer('branch_id'));
            }
        } else {
            if (!$companyId) {
                return response()->json([
                    'status' => false,
                    'message' => 'تعذر تحديد الشركة الحالية.',
                    'data' => [],
                ], 403);
            }

            $query->where('s.company_id', $companyId);

            if (!$this->isCompanyManager()) {
                if (!$branchId) {
                    return response()->json([
                        'status' => false,
                        'message' => 'تعذر تحديد الفرع الحالي.',
                        'data' => [],
                    ], 403);
                }

                $query->where('s.branch_id', $branchId);
            } elseif ($request->filled('branch_id')) {
                $query->where('s.branch_id', $request->integer('branch_id'));
            }
        }

        return response()->json([
            'status' => true,
            'data' => $query->orderByDesc('s.id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (!$this->canManageSuppliers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية إنشاء مورد.',
            ], 403);
        }

        $companyId = $this->companyId();
        $branchId = $this->resolveTargetBranch($request);

        if (!$companyId || !$branchId) {
            return response()->json([
                'status' => false,
                'message' => 'تعذر تحديد الشركة أو الفرع الحالي.',
            ], 422);
        }

        $request->merge([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);

        $request->validate([
            'supplier_name' => ['required', 'string', 'max:255'],
            'supplier_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'supplier_code')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)),
            ],
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'in:0,1'],
        ], [
            'supplier_name.required' => 'اسم المورد مطلوب.',
            'supplier_code.unique' => 'كود المورد مستخدم داخل هذا الفرع.',
            'branch_id.exists' => 'الفرع المحدد لا يتبع الشركة الحالية.',
        ]);

        try {
            $supplierId = DB::table('suppliers')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'supplier_code' => $request->filled('supplier_code')
                    ? trim((string) $request->supplier_code)
                    : null,
                'supplier_name' => trim((string) $request->supplier_name),
                'phone' => $request->filled('phone')
                    ? trim((string) $request->phone)
                    : null,
                'city' => $request->filled('city')
                    ? trim((string) $request->city)
                    : null,
                'address' => $request->filled('address')
                    ? trim((string) $request->address)
                    : null,
                'opening_balance' => round((float) ($request->opening_balance ?? 0), 3),
                'notes' => $request->filled('notes')
                    ? trim((string) $request->notes)
                    : null,
                'is_active' => (int) ($request->is_active ?? 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->logCreate(
                'Suppliers',
                $supplierId,
                'تم إنشاء مورد: ' . $request->supplier_name
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء المورد بنجاح.',
                'id' => $supplierId,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        return $this->findSupplierResponse($id);
    }

    private function supplierQuery($id)
    {
        $query = DB::table('suppliers')->where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $this->companyId());

            if (!$this->isCompanyManager()) {
                $query->where('branch_id', $this->branchId());
            }
        }

        return $query;
    }

    private function findSupplierResponse($id)
    {
        $supplier = $this->supplierQuery($id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود أو غير مسموح بالوصول إليه.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $supplier,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canManageSuppliers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية تعديل المورد.',
            ], 403);
        }

        $supplier = $this->supplierQuery($id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود أو غير مسموح بتعديله.',
            ], 404);
        }

        $companyId = (int) $supplier->company_id;
        $branchId = $this->resolveTargetBranch($request) ?: (int) $supplier->branch_id;

        if (!$this->isSuper() && $companyId !== (int) $this->companyId()) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بنقل المورد إلى شركة أخرى.',
            ], 403);
        }

        $request->merge(['branch_id' => $branchId]);

        $request->validate([
            'supplier_name' => ['required', 'string', 'max:255'],
            'supplier_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'supplier_code')
                    ->ignore($id)
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)),
            ],
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'in:0,1'],
        ]);

        $oldName = $supplier->supplier_name;

        DB::table('suppliers')
            ->where('id', $id)
            ->update([
                'branch_id' => $branchId,
                'supplier_code' => $request->filled('supplier_code')
                    ? trim((string) $request->supplier_code)
                    : null,
                'supplier_name' => trim((string) $request->supplier_name),
                'phone' => $request->filled('phone')
                    ? trim((string) $request->phone)
                    : null,
                'city' => $request->filled('city')
                    ? trim((string) $request->city)
                    : null,
                'address' => $request->filled('address')
                    ? trim((string) $request->address)
                    : null,
                'opening_balance' => round((float) ($request->opening_balance ?? 0), 3),
                'notes' => $request->filled('notes')
                    ? trim((string) $request->notes)
                    : null,
                'is_active' => (int) ($request->is_active ?? 1),
                'updated_at' => now(),
            ]);

        $this->logUpdate(
            'Suppliers',
            $id,
            'تم تعديل مورد: ' . $oldName . ' إلى ' . $request->supplier_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث المورد بنجاح.',
        ]);
    }

    public function destroy($id)
    {
        if (!$this->canManageSuppliers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية حذف المورد.',
            ], 403);
        }

        $supplier = $this->supplierQuery($id)->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود أو غير مسموح بحذفه.',
            ], 404);
        }

        try {
            DB::table('suppliers')->where('id', $id)->delete();

            $this->logDelete(
                'Suppliers',
                $id,
                'تم حذف مورد: ' . $supplier->supplier_name
            );

            return response()->json([
                'status' => true,
                'message' => 'تم حذف المورد بنجاح.',
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن حذف المورد لأنه مرتبط بحركات أو فواتير.',
            ], 422);
        }
    }
}
