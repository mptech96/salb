<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $data = DB::table('drivers')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
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
            'driver_name' => 'required|string|max:255',
        ]);

        $id = DB::table('drivers')->insertGetId([
            'company_id' => $companyId,
            'driver_name' => $request->driver_name,
            'phone' => $request->phone,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ السائق',
            'id' => $id
        ]);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $row = DB::table('drivers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json([
                'status' => false,
                'message' => 'السائق غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $row
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $request->validate([
            'driver_name' => 'required|string|max:255',
        ]);

        $updated = DB::table('drivers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->update([
                'driver_name' => $request->driver_name,
                'phone' => $request->phone,
                'notes' => $request->notes,
                'is_active' => $request->is_active ?? 1,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'status' => false,
                'message' => 'السائق غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم تعديل السائق'
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        DB::table('drivers')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'تم حذف السائق'
        ]);
    }
}