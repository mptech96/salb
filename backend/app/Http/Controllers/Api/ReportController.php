<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function profit(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $query = DB::table('sales_invoice_lines as sl')
            ->join('sales_invoices as s', 's.id', '=', 'sl.sales_invoice_id')
            ->leftJoin('items as i', 'i.id', '=', 'sl.item_id')
            ->leftJoin('cars as c', 'c.id', '=', 'sl.car_id')
            ->where('sl.company_id', $companyId)
            ->where('s.company_id', $companyId);

        TenantScope::apply($query, $branchId, 's.branch_id');

        $data = $query
            ->select(
                'sl.item_id',
                'sl.car_id',
                'i.item_name',
                'c.car_number',
                DB::raw('SUM(sl.qty) as sold_qty'),
                DB::raw('SUM(sl.line_total) as sales_total')
            )
            ->groupBy('sl.item_id', 'sl.car_id', 'i.item_name', 'c.car_number')
            ->get()
            ->map(function ($row) use ($companyId, $branchId) {
                $costQuery = DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('item_id', $row->item_id)
                    ->where('movement_type', 'IN');

                TenantScope::apply($costQuery, $branchId);

                if ($row->car_id === null) {
                    $costQuery->whereNull('car_id');
                } else {
                    $costQuery->where('car_id', $row->car_id);
                }

                $avgCost = (float) ($costQuery->avg('unit_cost') ?? 0);
                $soldQty = (float) ($row->sold_qty ?? 0);
                $salesTotal = (float) ($row->sales_total ?? 0);

                $row->avg_cost = $avgCost;
                $row->cost_total = $soldQty * $avgCost;
                $row->profit = $salesTotal - $row->cost_total;

                return $row;
            });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function carProfit(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $carsQuery = DB::table('cars as c')
            ->where('c.company_id', $companyId);

        TenantScope::apply($carsQuery, $branchId, 'c.branch_id');

        $data = $carsQuery
            ->select('c.id', 'c.car_number', 'c.plate_number')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($car) use ($companyId, $branchId) {
                $purchaseQuery = DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id);
                TenantScope::apply($purchaseQuery, $branchId);
                $purchaseTotal = (float) $purchaseQuery->sum('total_amount');

                $salesQuery = DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id);
                TenantScope::apply($salesQuery, $branchId);
                $salesTotal = (float) $salesQuery->sum('total_amount');

                $expenseQuery = DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($car, $companyId, $branchId) {
                        $q->where('car_id', $car->id)
                            ->orWhereIn('purchase_invoice_id', function ($sub) use ($car, $companyId, $branchId) {
                                $sub->select('id')
                                    ->from('purchase_invoices')
                                    ->where('company_id', $companyId)
                                    ->where('car_id', $car->id);

                                if ($branchId !== null) {
                                    $sub->where('branch_id', $branchId);
                                }
                            })
                            ->orWhereIn('sales_invoice_id', function ($sub) use ($car, $companyId, $branchId) {
                                $sub->select('id')
                                    ->from('sales_invoices')
                                    ->where('company_id', $companyId)
                                    ->where('car_id', $car->id);

                                if ($branchId !== null) {
                                    $sub->where('branch_id', $branchId);
                                }
                            });
                    });
                TenantScope::apply($expenseQuery, $branchId);
                $expensesTotal = (float) $expenseQuery->sum('amount');

                $stockQuery = DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('car_id', $car->id);
                TenantScope::apply($stockQuery, $branchId);

                $stockQty = $stockQuery
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

                return [
                    'car_id' => $car->id,
                    'car_number' => $car->car_number ?: $car->plate_number,
                    'purchase_total' => $purchaseTotal,
                    'sales_total' => $salesTotal,
                    'expenses_total' => $expensesTotal,
                    'stock_qty' => (float) ($stockQty ?? 0),
                    'profit' => $salesTotal - $purchaseTotal - $expensesTotal,
                ];
            });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function supplierBalances(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $suppliersQuery = DB::table('suppliers as s')
            ->where('s.company_id', $companyId);
        TenantScope::apply($suppliersQuery, $branchId, 's.branch_id');

        $data = $suppliersQuery
            ->select('s.id', 's.supplier_name', 's.phone')
            ->orderByDesc('s.id')
            ->get()
            ->map(function ($supplier) use ($companyId, $branchId) {
                $purchaseQuery = DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('supplier_id', $supplier->id);
                TenantScope::apply($purchaseQuery, $branchId);
                $purchases = (float) $purchaseQuery->sum('total_amount');

                $paymentsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'SUPPLIER')
                    ->where('v.reference_id', $supplier->id)
                    ->where('t.type_code', 'PAYMENT');
                TenantScope::apply($paymentsQuery, $branchId, 'v.branch_id');
                $payments = (float) $paymentsQuery->sum('v.amount');

                $receiptsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'SUPPLIER')
                    ->where('v.reference_id', $supplier->id)
                    ->where('t.type_code', 'RECEIPT');
                TenantScope::apply($receiptsQuery, $branchId, 'v.branch_id');
                $receipts = (float) $receiptsQuery->sum('v.amount');

                $openingQuery=DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('l.company_id',$companyId)->where('j.status','POSTED')->where('j.source_type','OPENING_BALANCE')->where('l.party_type','SUPPLIER')->where('l.party_id',$supplier->id);TenantScope::apply($openingQuery,$branchId,'l.branch_id');$opening=(float)$openingQuery->sum(DB::raw('l.credit-l.debit'));

                return [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->supplier_name,
                    'phone' => $supplier->phone,
                    'opening_balance' => $opening,
                    'purchases' => $purchases,
                    'payments' => $payments,
                    'receipts' => $receipts,
                    'balance' => ($opening + $purchases + $receipts) - $payments,
                ];
            });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function customerBalances(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $customersQuery = DB::table('customers as c')
            ->where('c.company_id', $companyId);
        TenantScope::apply($customersQuery, $branchId, 'c.branch_id');

        $data = $customersQuery
            ->select('c.id', 'c.customer_name', 'c.phone')
            ->orderByDesc('c.id')
            ->get()
            ->map(function ($customer) use ($companyId, $branchId) {
                $salesQuery = DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('customer_id', $customer->id);
                TenantScope::apply($salesQuery, $branchId);
                $sales = (float) $salesQuery->sum('total_amount');

                $receiptsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'CUSTOMER')
                    ->where('v.reference_id', $customer->id)
                    ->where('t.type_code', 'RECEIPT');
                TenantScope::apply($receiptsQuery, $branchId, 'v.branch_id');
                $receipts = (float) $receiptsQuery->sum('v.amount');

                $paymentsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'CUSTOMER')
                    ->where('v.reference_id', $customer->id)
                    ->where('t.type_code', 'PAYMENT');
                TenantScope::apply($paymentsQuery, $branchId, 'v.branch_id');
                $payments = (float) $paymentsQuery->sum('v.amount');

                $openingQuery=DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('l.company_id',$companyId)->where('j.status','POSTED')->where('j.source_type','OPENING_BALANCE')->where('l.party_type','CUSTOMER')->where('l.party_id',$customer->id);TenantScope::apply($openingQuery,$branchId,'l.branch_id');$opening=(float)$openingQuery->sum(DB::raw('l.debit-l.credit'));

                return [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'phone' => $customer->phone,
                    'opening_balance' => $opening,
                    'sales' => $sales,
                    'receipts' => $receipts,
                    'payments' => $payments,
                    'balance' => ($opening + $sales + $payments) - $receipts,
                ];
            });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function driverBalances(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $driversQuery = DB::table('drivers as d')
            ->where('d.company_id', $companyId);
        TenantScope::apply($driversQuery, $branchId, 'd.branch_id');

        $data = $driversQuery
            ->select('d.id', 'd.driver_name', 'd.phone')
            ->orderByDesc('d.id')
            ->get()
            ->map(function ($driver) use ($companyId, $branchId) {
                $expensesQuery = DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->where('driver_id', $driver->id);
                TenantScope::apply($expensesQuery, $branchId);
                $expenses = (float) $expensesQuery->sum('amount');

                $paymentsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'DRIVER')
                    ->where('v.reference_id', $driver->id)
                    ->where('t.type_code', 'PAYMENT');
                TenantScope::apply($paymentsQuery, $branchId, 'v.branch_id');
                $payments = (float) $paymentsQuery->sum('v.amount');

                $receiptsQuery = DB::table('vouchers as v')
                    ->leftJoin('voucher_types as t', 't.id', '=', 'v.voucher_type_id')
                    ->where('v.company_id', $companyId)
                    ->where('v.reference_type', 'DRIVER')
                    ->where('v.reference_id', $driver->id)
                    ->where('t.type_code', 'RECEIPT');
                TenantScope::apply($receiptsQuery, $branchId, 'v.branch_id');
                $receipts = (float) $receiptsQuery->sum('v.amount');

                return [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->driver_name,
                    'phone' => $driver->phone,
                    'expenses' => $expenses,
                    'payments' => $payments,
                    'receipts' => $receipts,
                    'balance' => ($expenses + $receipts) - $payments,
                ];
            });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function expenseSummary(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $query = DB::table('expenses as e')
            ->leftJoin('expense_types as t', 't.id', '=', 'e.expense_type_id')
            ->where('e.company_id', $companyId);

        TenantScope::apply($query, $branchId, 'e.branch_id');

        $data = $query
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

        return response()->json(['status' => true, 'data' => $data]);
    }
}
