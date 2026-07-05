<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $data = Customer::where('company_id', $companyId)
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
            'customer_name' => 'required|string|max:255',
            'customer_code' => 'nullable|string|max:50|unique:customers,customer_code',
            'branch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $customer = Customer::create([
            'company_id' => $companyId,
            'branch_id' => $request->branch_id,
            'customer_code' => $request->customer_code,
            'customer_name' => $request->customer_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'opening_balance' => $request->opening_balance ?? 0,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        $this->logCreate(
            'Customers',
            $customer->id,
            'تم إنشاء عميل: ' . $customer->customer_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء العميل بنجاح',
            'data' => $customer
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $customer = Customer::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $customer
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $customer = Customer::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود'
            ], 404);
        }

        $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_code' => 'nullable|string|max:50|unique:customers,customer_code,' . $id,
            'branch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $oldName = $customer->customer_name;

        $customer->update([
            'branch_id' => $request->branch_id,
            'customer_code' => $request->customer_code,
            'customer_name' => $request->customer_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'opening_balance' => $request->opening_balance ?? 0,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        $this->logUpdate(
            'Customers',
            $customer->id,
            'تم تعديل عميل: ' . $oldName . ' إلى ' . $customer->customer_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث العميل بنجاح',
            'data' => $customer
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $customer = Customer::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود'
            ], 404);
        }

        $customerName = $customer->customer_name;

        $customer->delete();

        $this->logDelete(
            'Customers',
            $id,
            'تم حذف عميل: ' . $customerName
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف العميل بنجاح'
        ]);
    }
}