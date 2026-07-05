<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentCostRequest;
use App\Http\Requests\UpdateShipmentCostRequest;
use App\Models\ShipmentCost;
use App\Services\ShipmentCostService;

class ShipmentCostController extends Controller
{
    public function index($shipmentId, ShipmentCostService $service)
    {
        try {
            return response()->json([
                'status' => true,
                'data' => $service->summary((int) $shipmentId)
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'تعذر جلب تكاليف الحمولة: ' . $e->getMessage()
            ], 400);
        }
    }

    public function store(StoreShipmentCostRequest $request, ShipmentCostService $service)
    {
        try {
            $result = $service->store($request->validated());

            return response()->json([
                'status' => true,
                'message' => 'تم إضافة تكلفة الحمولة وإنشاء السند والقيد وتوزيع التكلفة بنجاح.',
                'data' => $result
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل حفظ تكلفة الحمولة: ' . $e->getMessage()
            ], 400);
        }
    }

    public function update(UpdateShipmentCostRequest $request, $id)
    {
        return response()->json([
            'status' => false,
            'message' => 'تعديل تكلفة الحمولة بعد الترحيل غير مفعل حاليًا. سنضيف لاحقًا عكس القيد وإعادة التوزيع.'
        ], 400);
    }

    public function destroy($id)
    {
        return response()->json([
            'status' => false,
            'message' => 'حذف تكلفة الحمولة بعد الترحيل غير مسموح حاليًا. سنضيف لاحقًا إلغاء منظم بعكس القيد.'
        ], 400);
    }
}