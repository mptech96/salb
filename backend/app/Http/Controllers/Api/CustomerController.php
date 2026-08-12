<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class CustomerController extends Controller
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

    private function canManageCustomers(): bool
    {
        return in_array(
            $this->roleCode(),
            ['SUPER_ADMIN', 'MANAGER', 'ACCOUNTANT', 'SALES'],
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

        $query = DB::table('customers as s')
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
        if (!$this->canManageCustomers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية إنشاء عميل.',
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'customer_code')
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
            'customer_name.required' => 'اسم العميل مطلوب.',
            'customer_code.unique' => 'كود العميل مستخدم داخل هذا الفرع.',
            'branch_id.exists' => 'الفرع المحدد لا يتبع الشركة الحالية.',
        ]);

        try {
            $customerId = DB::table('customers')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'customer_code' => $request->filled('customer_code')
                    ? trim((string) $request->customer_code)
                    : null,
                'customer_name' => trim((string) $request->customer_name),
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
                'Customers',
                $customerId,
                'تم إنشاء عميل: ' . $request->customer_name
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء العميل بنجاح.',
                'id' => $customerId,
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
        return $this->findCustomerResponse($id);
    }

    private function customerQuery($id)
    {
        $query = DB::table('customers')->where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $this->companyId());

            if (!$this->isCompanyManager()) {
                $query->where('branch_id', $this->branchId());
            }
        }

        return $query;
    }

    private function findCustomerResponse($id)
    {
        $customer = $this->customerQuery($id)->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود أو غير مسموح بالوصول إليه.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $customer,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canManageCustomers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية تعديل العميل.',
            ], 403);
        }

        $customer = $this->customerQuery($id)->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود أو غير مسموح بتعديله.',
            ], 404);
        }

        $companyId = (int) $customer->company_id;
        $branchId = $this->resolveTargetBranch($request) ?: (int) $customer->branch_id;

        if (!$this->isSuper() && $companyId !== (int) $this->companyId()) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بنقل العميل إلى شركة أخرى.',
            ], 403);
        }

        $request->merge(['branch_id' => $branchId]);

        $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'customer_code')
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

        $oldName = $customer->customer_name;

        DB::table('customers')
            ->where('id', $id)
            ->update([
                'branch_id' => $branchId,
                'customer_code' => $request->filled('customer_code')
                    ? trim((string) $request->customer_code)
                    : null,
                'customer_name' => trim((string) $request->customer_name),
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
            'Customers',
            $id,
            'تم تعديل عميل: ' . $oldName . ' إلى ' . $request->customer_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث العميل بنجاح.',
        ]);
    }

    public function destroy($id)
    {
        if (!$this->canManageCustomers()) {
            return response()->json([
                'status' => false,
                'message' => 'لا تملك صلاحية حذف العميل.',
            ], 403);
        }

        $customer = $this->customerQuery($id)->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود أو غير مسموح بحذفه.',
            ], 404);
        }

        try {
            DB::table('customers')->where('id', $id)->delete();

            $this->logDelete(
                'Customers',
                $id,
                'تم حذف عميل: ' . $customer->customer_name
            );

            return response()->json([
                'status' => true,
                'message' => 'تم حذف العميل بنجاح.',
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن حذف العميل لأنه مرتبط بحركات أو فواتير.',
            ], 422);
        }
    }
}
