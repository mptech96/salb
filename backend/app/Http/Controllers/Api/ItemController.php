<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $items = Item::where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $items
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
            'item_name' => 'required|string|max:255',
            'item_code' => 'nullable|string|max:50|unique:items,item_code',
            'category_id' => 'nullable|integer',
            'item_grade' => 'nullable|string|max:100',
            'unit_name' => 'nullable|string|max:50',
            'default_buy_price' => 'nullable|numeric',
            'default_sell_price' => 'nullable|numeric',
            'min_sell_price' => 'nullable|numeric',
            'color_notes' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $item = Item::create([
            'company_id' => $companyId,
            'category_id' => $request->category_id,
            'item_code' => $request->item_code,
            'item_name' => $request->item_name,
            'item_grade' => $request->item_grade,
            'unit_name' => $request->unit_name ?? 'طن',
            'default_buy_price' => $request->default_buy_price ?? 0,
            'default_sell_price' => $request->default_sell_price ?? 0,
            'min_sell_price' => $request->min_sell_price ?? 0,
            'color_notes' => $request->color_notes,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء الصنف بنجاح',
            'data' => $item
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $item = Item::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'الصنف غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $item
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $item = Item::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'الصنف غير موجود'
            ], 404);
        }

        $request->validate([
            'item_name' => 'required|string|max:255',
            'item_code' => 'nullable|string|max:50|unique:items,item_code,' . $id,
            'category_id' => 'nullable|integer',
            'item_grade' => 'nullable|string|max:100',
            'unit_name' => 'nullable|string|max:50',
            'default_buy_price' => 'nullable|numeric',
            'default_sell_price' => 'nullable|numeric',
            'min_sell_price' => 'nullable|numeric',
            'color_notes' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $item->update([
            'category_id' => $request->category_id,
            'item_code' => $request->item_code,
            'item_name' => $request->item_name,
            'item_grade' => $request->item_grade,
            'unit_name' => $request->unit_name ?? 'طن',
            'default_buy_price' => $request->default_buy_price ?? 0,
            'default_sell_price' => $request->default_sell_price ?? 0,
            'min_sell_price' => $request->min_sell_price ?? 0,
            'color_notes' => $request->color_notes,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث الصنف بنجاح',
            'data' => $item
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $item = Item::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'الصنف غير موجود'
            ], 404);
        }

        $item->delete();

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الصنف بنجاح'
        ]);
    }
}