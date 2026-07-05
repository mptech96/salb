<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function branchId()
    {
        return request()->header('X-Branch-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $data = DB::table('vouchers as v')
            ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->where('v.company_id', $companyId)
            ->select(
                'v.*',
                't.type_name',
                't.type_code',
                'b.branch_name',
                DB::raw("
                    CASE
                        WHEN v.reference_type = 'CUSTOMER' THEN (
                            SELECT customer_name FROM customers 
                            WHERE customers.id = v.reference_id 
                            AND customers.company_id = v.company_id
                            LIMIT 1
                        )
                        WHEN v.reference_type = 'SUPPLIER' THEN (
                            SELECT supplier_name FROM suppliers 
                            WHERE suppliers.id = v.reference_id 
                            AND suppliers.company_id = v.company_id
                            LIMIT 1
                        )
                        WHEN v.reference_type = 'DRIVER' THEN (
                            SELECT driver_name FROM drivers 
                            WHERE drivers.id = v.reference_id 
                            AND drivers.company_id = v.company_id
                            LIMIT 1
                        )
                        WHEN v.reference_type = 'WORKER' THEN (
                            SELECT worker_name FROM workers 
                            WHERE workers.id = v.reference_id 
                            AND workers.company_id = v.company_id
                            LIMIT 1
                        )
                        ELSE '-'
                    END as reference_name
                ")
            )
            ->orderByDesc('v.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $request->branch_id ?? $this->branchId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'voucher_type_id' => 'required|integer',
            'voucher_date' => 'required|date',
            'reference_type' => 'required|string',
            'reference_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.001',
        ]);

        $voucherNumber = $request->voucher_number;

        if (!$voucherNumber) {
            $prefix = ((int) $request->voucher_type_id === 1) ? 'REC' : 'PAY';

            $lastId = DB::table('vouchers')
                ->where('company_id', $companyId)
                ->max('id') ?? 0;

            $voucherNumber = $prefix . '-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
        }

        $id = DB::table('vouchers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'voucher_type_id' => $request->voucher_type_id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => $request->voucher_date,
            'reference_type' => $request->reference_type,
            'reference_id' => $request->reference_id,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'notes' => $request->notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->logCreate(
            'Vouchers',
            $id,
            'تم إنشاء سند رقم: ' . $voucherNumber . ' بمبلغ: ' . $request->amount
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ السند',
            'id' => $id,
            'voucher_number' => $voucherNumber
        ]);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $voucher = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$voucher) {
            return response()->json([
                'status' => false,
                'message' => 'السند غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $voucher
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();
        $branchId = $request->branch_id ?? $this->branchId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'voucher_type_id' => 'required|integer',
            'voucher_date' => 'required|date',
            'reference_type' => 'required|string',
            'reference_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.001',
        ]);

        $oldVoucher = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$oldVoucher) {
            return response()->json([
                'status' => false,
                'message' => 'السند غير موجود'
            ], 404);
        }

        DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->update([
                'branch_id' => $branchId,
                'voucher_type_id' => $request->voucher_type_id,
                'voucher_number' => $request->voucher_number,
                'voucher_date' => $request->voucher_date,
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'notes' => $request->notes,
                'updated_at' => now(),
            ]);

        $this->logUpdate(
            'Vouchers',
            $id,
            'تم تعديل سند رقم: ' . ($oldVoucher->voucher_number ?: $id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تعديل السند'
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $voucher = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$voucher) {
            return response()->json([
                'status' => false,
                'message' => 'السند غير موجود'
            ], 404);
        }

        DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->delete();

        $this->logDelete(
            'Vouchers',
            $id,
            'تم حذف سند رقم: ' . ($voucher->voucher_number ?: $id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف السند'
        ]);
    }
}