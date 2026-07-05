<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function profit()
    {
        $companyId = $this->companyId();

        $data = DB::table('sales_invoice_lines as sl')
            ->join('sales_invoices as s', 's.id', '=', 'sl.sales_invoice_id')
            ->leftJoin('items as i', 'i.id', '=', 'sl.item_id')
            ->leftJoin('cars as c', 'c.id', '=', 'sl.car_id')
            ->where('sl.company_id', $companyId)
            ->where('s.company_id', $companyId)
            ->select(
                'sl.item_id',
                'sl.car_id',
                'i.item_name',
                'c.car_number',
                DB::raw('SUM(sl.qty) as sold_qty'),
                DB::raw('SUM(sl.line_total) as sales_total'),
                DB::raw('
                    (
                        SELECT AVG(sm.unit_cost)
                        FROM stock_movements sm
                        WHERE sm.company_id = sl.company_id
                        AND sm.item_id = sl.item_id
                        AND sm.movement_type = "IN"
                        AND (
                            (sl.car_id IS NULL AND sm.car_id IS NULL)
                            OR sm.car_id = sl.car_id
                        )
                    ) as avg_cost
                ')
            )
            ->groupBy('sl.item_id', 'sl.car_id', 'i.item_name', 'c.car_number', 'sl.company_id')
            ->get()
            ->map(function ($row) {
                $avgCost = (float) ($row->avg_cost ?? 0);
                $soldQty = (float) ($row->sold_qty ?? 0);
                $salesTotal = (float) ($row->sales_total ?? 0);

                $row->cost_total = $soldQty * $avgCost;
                $row->profit = $salesTotal - $row->cost_total;

                return $row;
            });

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function carProfit()
    {
        $companyId = $this->companyId();

        $data = DB::table('cars as c')
            ->where('c.company_id', $companyId)
            ->select('c.id', 'c.car_number', 'c.plate_number')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($car) use ($companyId) {

                $purchaseTotal = DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id)
                    ->sum('total_amount');

                $salesTotal = DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id)
                    ->sum('total_amount');

                $expensesTotal = DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($car, $companyId) {
                        $q->where('car_id', $car->id)
                            ->orWhereIn('purchase_invoice_id', function ($sub) use ($car, $companyId) {
                                $sub->select('id')
                                    ->from('purchase_invoices')
                                    ->where('company_id', $companyId)
                                    ->where('car_id', $car->id);
                            })
                            ->orWhereIn('sales_invoice_id', function ($sub) use ($car, $companyId) {
                                $sub->select('id')
                                    ->from('sales_invoices')
                                    ->where('company_id', $companyId)
                                    ->where('car_id', $car->id);
                            });
                    })
                    ->sum('amount');

                $stockQty = DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id)
                    ->selectRaw("
                        SUM(
                            CASE
                                WHEN movement_type = 'IN' THEN qty
                                WHEN movement_type = 'OUT' THEN -qty
                                ELSE 0
                            END
                        ) as balance
                    ")
                    ->value('balance');

                $profit = $salesTotal - $purchaseTotal - $expensesTotal;

                return [
                    'car_id' => $car->id,
                    'car_number' => $car->car_number ?: $car->plate_number,
                    'purchase_total' => (float) $purchaseTotal,
                    'sales_total' => (float) $salesTotal,
                    'expenses_total' => (float) $expensesTotal,
                    'stock_qty' => (float) ($stockQty ?? 0),
                    'profit' => (float) $profit,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function supplierBalances()
    {
        $companyId = $this->companyId();

        $data = DB::table('suppliers as s')
            ->where('s.company_id', $companyId)
            ->select('s.id', 's.supplier_name', 's.phone', 's.opening_balance')
            ->orderByDesc('s.id')
            ->get()
            ->map(function ($supplier) use ($companyId) {

                $purchases = DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('supplier_id', $supplier->id)
                    ->sum('total_amount');

                $payments = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'SUPPLIER')
                    ->where('v.reference_id', $supplier->id)
                    ->where('t.type_code', 'PAYMENT')
                    ->sum('v.amount');

                $receipts = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'SUPPLIER')
                    ->where('v.reference_id', $supplier->id)
                    ->where('t.type_code', 'RECEIPT')
                    ->sum('v.amount');

                $balance = ((float) $supplier->opening_balance + (float) $purchases + (float) $receipts) - (float) $payments;

                return [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->supplier_name,
                    'phone' => $supplier->phone,
                    'opening_balance' => (float) $supplier->opening_balance,
                    'purchases' => (float) $purchases,
                    'payments' => (float) $payments,
                    'receipts' => (float) $receipts,
                    'balance' => $balance,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function customerBalances()
    {
        $companyId = $this->companyId();

        $data = DB::table('customers as c')
            ->where('c.company_id', $companyId)
            ->select('c.id', 'c.customer_name', 'c.phone', 'c.opening_balance')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($customer) use ($companyId) {

                $sales = DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('customer_id', $customer->id)
                    ->sum('total_amount');

                $receipts = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'CUSTOMER')
                    ->where('v.reference_id', $customer->id)
                    ->where('t.type_code', 'RECEIPT')
                    ->sum('v.amount');

                $payments = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'CUSTOMER')
                    ->where('v.reference_id', $customer->id)
                    ->where('t.type_code', 'PAYMENT')
                    ->sum('v.amount');

                $balance = ((float) $customer->opening_balance + (float) $sales + (float) $payments) - (float) $receipts;

                return [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'phone' => $customer->phone,
                    'opening_balance' => (float) $customer->opening_balance,
                    'sales' => (float) $sales,
                    'receipts' => (float) $receipts,
                    'payments' => (float) $payments,
                    'balance' => $balance,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function driverBalances()
    {
        $companyId = $this->companyId();

        $data = DB::table('drivers as d')
            ->where('d.company_id', $companyId)
            ->select('d.id', 'd.driver_name', 'd.phone')
            ->orderByDesc('d.id')
            ->get()
            ->map(function ($driver) use ($companyId) {

                $expenses = DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->where('driver_id', $driver->id)
                    ->sum('amount');

                $payments = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'DRIVER')
                    ->where('v.reference_id', $driver->id)
                    ->where('t.type_code', 'PAYMENT')
                    ->sum('v.amount');

                $receipts = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'DRIVER')
                    ->where('v.reference_id', $driver->id)
                    ->where('t.type_code', 'RECEIPT')
                    ->sum('v.amount');

                $balance = ((float) $expenses + (float) $receipts) - (float) $payments;

                return [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->driver_name,
                    'phone' => $driver->phone,
                    'expenses' => (float) $expenses,
                    'payments' => (float) $payments,
                    'receipts' => (float) $receipts,
                    'balance' => $balance,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function expenseSummary()
    {
        $companyId = $this->companyId();

        $data = DB::table('expenses as e')
            ->leftJoin('expense_types as t', 't.id', '=', 'e.expense_type_id')
            ->where('e.company_id', $companyId)
            ->select(
                'e.expense_type_id',
                't.type_name',
                't.type_code',
                DB::raw('SUM(e.amount) as total_amount'),
                DB::raw('COUNT(e.id) as count_expenses')
            )
            ->groupBy('e.expense_type_id', 't.type_name', 't.type_code')
            ->orderByDesc('total_amount')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}