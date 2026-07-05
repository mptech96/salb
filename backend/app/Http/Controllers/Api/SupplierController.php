<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $data = Supplier::where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_code' => 'nullable|string|max:50|unique:suppliers,supplier_code',
            'branch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $supplier = Supplier::create([
            'company_id' => $companyId,
            'branch_id' => $request->branch_id,
            'supplier_code' => $request->supplier_code,
            'supplier_name' => $request->supplier_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'opening_balance' => $request->opening_balance ?? 0,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        $this->logCreate(
            'Suppliers',
            $supplier->id,
            'تم إنشاء مورد: ' . $supplier->supplier_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء المورد بنجاح',
            'data' => $supplier
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $supplier = Supplier::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $supplier
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $supplier = Supplier::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود'
            ], 404);
        }

        $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_code' => 'nullable|string|max:50|unique:suppliers,supplier_code,' . $id,
            'branch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $oldName = $supplier->supplier_name;

        $supplier->update([
            'branch_id' => $request->branch_id,
            'supplier_code' => $request->supplier_code,
            'supplier_name' => $request->supplier_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'opening_balance' => $request->opening_balance ?? 0,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        $this->logUpdate(
            'Suppliers',
            $supplier->id,
            'تم تعديل مورد: ' . $oldName . ' إلى ' . $supplier->supplier_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث المورد بنجاح',
            'data' => $supplier
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $supplier = Supplier::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json([
                'status' => false,
                'message' => 'المورد غير موجود'
            ], 404);
        }

        $supplierName = $supplier->supplier_name;

        $supplier->delete();

        $this->logDelete(
            'Suppliers',
            $id,
            'تم حذف مورد: ' . $supplierName
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف المورد بنجاح'
        ]);
    }
}