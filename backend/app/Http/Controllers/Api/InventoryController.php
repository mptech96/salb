<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
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

        $data = DB::table('stock_movements as s')
            ->leftJoin('items as i', 'i.id', '=', 's.item_id')
            ->leftJoin('cars as c', 'c.id', '=', 's.car_id')
            ->where('s.company_id', $companyId)
            ->select(
                's.item_id',
                's.car_id',
                'i.item_name',
                'c.car_number',
                DB::raw("SUM(CASE WHEN s.movement_type = 'IN' THEN s.qty ELSE 0 END) as total_in"),
                DB::raw("SUM(CASE WHEN s.movement_type = 'OUT' THEN s.qty ELSE 0 END) as total_out"),
                DB::raw("SUM(CASE WHEN s.movement_type = 'IN' THEN s.qty ELSE -s.qty END) as balance_qty"),
                DB::raw("AVG(CASE WHEN s.movement_type = 'IN' THEN s.unit_cost ELSE NULL END) as avg_cost"),
                DB::raw("SUM(CASE WHEN s.movement_type = 'IN' THEN s.qty ELSE -s.qty END) * AVG(CASE WHEN s.movement_type = 'IN' THEN s.unit_cost ELSE NULL END) as stock_value")
            )
            ->groupBy('s.item_id', 's.car_id', 'i.item_name', 'c.car_number')
            ->orderBy('i.item_name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function adjustment(Request $request)
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
            'item_id' => 'required|integer',
            'movement_type' => 'required|in:IN,OUT',
            'qty' => 'required|numeric|min:0.001',
            'unit_cost' => 'nullable|numeric|min:0',
            'car_id' => 'nullable|integer',
            'movement_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        DB::table('stock_movements')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'item_id' => $request->item_id,
            'car_id' => $request->car_id ?: null,
            'movement_type' => $request->movement_type,
            'source_type' => 'ADJUSTMENT',
            'source_id' => null,
            'movement_date' => $request->movement_date ?? now(),
            'qty' => $request->qty,
            'unit_cost' => $request->unit_cost ?? 0,
            'total_cost' => ($request->qty ?? 0) * ($request->unit_cost ?? 0),
            'notes' => $request->notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تمت تسوية المخزون بنجاح'
        ]);
    }
}