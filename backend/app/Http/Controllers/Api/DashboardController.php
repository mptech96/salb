<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $companyId = TenantScope::companyId($request);
        $branchId = TenantScope::branchId($request);

        $salesQuery = DB::table('sales_invoices')->where('company_id', $companyId);
        TenantScope::apply($salesQuery, $branchId);
        $sales = (float) $salesQuery->sum('total_amount');

        $purchasesQuery = DB::table('purchase_invoices')->where('company_id', $companyId);
        TenantScope::apply($purchasesQuery, $branchId);
        $purchases = (float) $purchasesQuery->sum('total_amount');

        $expensesQuery = DB::table('expenses')->where('company_id', $companyId);
        TenantScope::apply($expensesQuery, $branchId);
        $expenses = (float) $expensesQuery->sum('amount');

        $carsQuery = DB::table('cars')
            ->where('company_id', $companyId)
            ->where('car_status', 'OPEN');
        TenantScope::apply($carsQuery, $branchId);
        $openCars = (int) $carsQuery->count();

        $stockQuery = DB::table('stock_movements')->where('company_id', $companyId);
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

        $latestSalesQuery = DB::table('sales_invoices as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.company_id', $companyId);
        TenantScope::apply($latestSalesQuery, $branchId, 's.branch_id');
        $latestSales = $latestSalesQuery
            ->select('s.id', 's.invoice_number', 's.invoice_date', 's.total_amount', 'c.customer_name')
            ->orderByDesc('s.id')
            ->limit(5)
            ->get();

        $latestPurchasesQuery = DB::table('purchase_invoices as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.company_id', $companyId);
        TenantScope::apply($latestPurchasesQuery, $branchId, 'p.branch_id');
        $latestPurchases = $latestPurchasesQuery
            ->select('p.id', 'p.invoice_number', 'p.invoice_date', 'p.total_amount', 's.supplier_name')
            ->orderByDesc('p.id')
            ->limit(5)
            ->get();

        $latestVouchersQuery = DB::table('vouchers')->where('company_id', $companyId);
        TenantScope::apply($latestVouchersQuery, $branchId);
        $latestVouchers = $latestVouchersQuery
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $topCarsQuery = DB::table('sales_invoices as s')
            ->leftJoin('cars as c', 'c.id', '=', 's.car_id')
            ->where('s.company_id', $companyId);
        TenantScope::apply($topCarsQuery, $branchId, 's.branch_id');
        $topCars = $topCarsQuery
            ->select('c.car_number', DB::raw('SUM(s.total_amount) as total_sales'))
            ->groupBy('c.car_number')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => true,
            'scope' => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'scope_type' => $branchId === null ? 'COMPANY' : 'BRANCH',
            ],
            'cards' => [
                'sales' => $sales,
                'purchases' => $purchases,
                'expenses' => $expenses,
                'profit' => $sales - $purchases - $expenses,
                'open_cars' => $openCars,
                'stock_qty' => (float) ($stockQty ?? 0),
            ],
            'latest_sales' => $latestSales,
            'latest_purchases' => $latestPurchases,
            'latest_vouchers' => $latestVouchers,
            'top_cars' => $topCars,
        ]);
    }
}
