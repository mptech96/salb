<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $companyId = request()->header('X-Company-ID');

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $sales = DB::table('sales_invoices')
            ->where('company_id', $companyId)
            ->sum('total_amount');

        $purchases = DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->sum('total_amount');

        $expenses = DB::table('expenses')
            ->where('company_id', $companyId)
            ->sum('amount');

        $profit = $sales - $purchases - $expenses;

        $openCars = DB::table('cars')
            ->where('company_id', $companyId)
            ->where('car_status', 'OPEN')
            ->count();

        $stockQty = DB::table('stock_movements')
            ->where('company_id', $companyId)
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

        $latestSales = DB::table('sales_invoices as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.company_id', $companyId)
            ->select(
                's.id',
                's.invoice_number',
                's.invoice_date',
                's.total_amount',
                'c.customer_name'
            )
            ->orderByDesc('s.id')
            ->limit(5)
            ->get();

        $latestPurchases = DB::table('purchase_invoices as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.company_id', $companyId)
            ->select(
                'p.id',
                'p.invoice_number',
                'p.invoice_date',
                'p.total_amount',
                's.supplier_name'
            )
            ->orderByDesc('p.id')
            ->limit(5)
            ->get();

        $latestVouchers = DB::table('vouchers')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $topCars = DB::table('sales_invoices as s')
            ->leftJoin('cars as c', 'c.id', '=', 's.car_id')
            ->where('s.company_id', $companyId)
            ->select(
                'c.car_number',
                DB::raw('SUM(s.total_amount) as total_sales')
            )
            ->groupBy('c.car_number')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => true,
            'cards' => [
                'sales' => (float) $sales,
                'purchases' => (float) $purchases,
                'expenses' => (float) $expenses,
                'profit' => (float) $profit,
                'open_cars' => (int) $openCars,
                'stock_qty' => (float) ($stockQty ?? 0),
            ],
            'latest_sales' => $latestSales,
            'latest_purchases' => $latestPurchases,
            'latest_vouchers' => $latestVouchers,
            'top_cars' => $topCars,
        ]);
    }
}