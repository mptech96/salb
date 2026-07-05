<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

class AccountStatementController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function emptyResponse($name = '')
    {
        return response()->json([
            'status' => true,
            'data' => [
                'name' => $name,
                'rows' => [],
                'total_debit' => 0,
                'total_credit' => 0,
                'balance' => 0
            ]
        ]);
    }

    public function supplier($id)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json(['status' => false, 'message' => 'لم يتم تحديد الشركة الحالية'], 400);
        }

        $supplier = DB::table('suppliers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$supplier) {
            return response()->json(['status' => false, 'message' => 'المورد غير موجود'], 404);
        }

        $rows = collect();

        $purchases = DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('supplier_id', $id)
            ->select(
                'invoice_date as trx_date',
                'invoice_number as trx_number',
                DB::raw("'فاتورة شراء' as trx_type"),
                DB::raw('total_amount as debit'),
                DB::raw('0 as credit')
            )
            ->get();

        $vouchers = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('reference_type', 'SUPPLIER')
            ->where('reference_id', $id)
            ->select(
                'voucher_date as trx_date',
                'voucher_number as trx_number',
                DB::raw("'سند صرف' as trx_type"),
                DB::raw('0 as debit'),
                DB::raw('amount as credit')
            )
            ->get();

        $rows = $rows->merge($purchases)->merge($vouchers)->sortBy('trx_date')->values();

        $totalDebit = (float) $rows->sum('debit');
        $totalCredit = (float) $rows->sum('credit');

        $this->logView('Statements', $id, 'تم عرض كشف حساب مورد: ' . $supplier->supplier_name);

        return response()->json([
            'status' => true,
            'data' => [
                'name' => $supplier->supplier_name,
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit
            ]
        ]);
    }

    public function customer($id)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json(['status' => false, 'message' => 'لم يتم تحديد الشركة الحالية'], 400);
        }

        $customer = DB::table('customers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            return response()->json(['status' => false, 'message' => 'العميل غير موجود'], 404);
        }

        $sales = DB::table('sales_invoices')
            ->where('company_id', $companyId)
            ->where('customer_id', $id)
            ->select(
                'invoice_date as trx_date',
                'invoice_number as trx_number',
                DB::raw("'فاتورة بيع' as trx_type"),
                DB::raw('total_amount as debit'),
                DB::raw('0 as credit')
            )
            ->get();

        $vouchers = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('reference_type', 'CUSTOMER')
            ->where('reference_id', $id)
            ->select(
                'voucher_date as trx_date',
                'voucher_number as trx_number',
                DB::raw("'سند قبض' as trx_type"),
                DB::raw('0 as debit'),
                DB::raw('amount as credit')
            )
            ->get();

        $rows = collect()->merge($sales)->merge($vouchers)->sortBy('trx_date')->values();

        $totalDebit = (float) $rows->sum('debit');
        $totalCredit = (float) $rows->sum('credit');

        $this->logView('Statements', $id, 'تم عرض كشف حساب عميل: ' . $customer->customer_name);

        return response()->json([
            'status' => true,
            'data' => [
                'name' => $customer->customer_name,
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit
            ]
        ]);
    }

    public function driver($id)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json(['status' => false, 'message' => 'لم يتم تحديد الشركة الحالية'], 400);
        }

        $driver = DB::table('drivers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$driver) {
            return response()->json(['status' => false, 'message' => 'السائق غير موجود'], 404);
        }

        $expenses = DB::table('expenses')
            ->where('company_id', $companyId)
            ->where('driver_id', $id)
            ->select(
                'expense_date as trx_date',
                DB::raw("CONCAT('EXP-', id) as trx_number"),
                DB::raw("'مصروف سائق' as trx_type"),
                DB::raw('amount as debit'),
                DB::raw('0 as credit')
            )
            ->get();

        $vouchers = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('reference_type', 'DRIVER')
            ->where('reference_id', $id)
            ->select(
                'voucher_date as trx_date',
                'voucher_number as trx_number',
                DB::raw("'سند سائق' as trx_type"),
                DB::raw('0 as debit'),
                DB::raw('amount as credit')
            )
            ->get();

        $rows = collect()->merge($expenses)->merge($vouchers)->sortBy('trx_date')->values();

        $totalDebit = (float) $rows->sum('debit');
        $totalCredit = (float) $rows->sum('credit');

        $this->logView('Statements', $id, 'تم عرض كشف حساب سائق: ' . $driver->driver_name);

        return response()->json([
            'status' => true,
            'data' => [
                'name' => $driver->driver_name,
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit
            ]
        ]);
    }

    public function worker($id)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json(['status' => false, 'message' => 'لم يتم تحديد الشركة الحالية'], 400);
        }

        $worker = DB::table('workers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$worker) {
            return response()->json(['status' => false, 'message' => 'العامل غير موجود'], 404);
        }

        $expenses = DB::table('expenses')
            ->where('company_id', $companyId)
            ->where('worker_id', $id)
            ->select(
                'expense_date as trx_date',
                DB::raw("CONCAT('EXP-', id) as trx_number"),
                DB::raw("'مصروف عامل' as trx_type"),
                DB::raw('amount as debit'),
                DB::raw('0 as credit')
            )
            ->get();

        $vouchers = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->where('reference_type', 'WORKER')
            ->where('reference_id', $id)
            ->select(
                'voucher_date as trx_date',
                'voucher_number as trx_number',
                DB::raw("'سند عامل' as trx_type"),
                DB::raw('0 as debit'),
                DB::raw('amount as credit')
            )
            ->get();

        $rows = collect()->merge($expenses)->merge($vouchers)->sortBy('trx_date')->values();

        $totalDebit = (float) $rows->sum('debit');
        $totalCredit = (float) $rows->sum('credit');

        $this->logView('Statements', $id, 'تم عرض كشف حساب عامل: ' . $worker->worker_name);

        return response()->json([
            'status' => true,
            'data' => [
                'name' => $worker->worker_name,
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit
            ]
        ]);
    }
}