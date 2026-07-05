<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentRequest;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use App\Services\ApproveShipmentService;
use App\Services\SellShipmentService;

class ShipmentController extends Controller
{
    use LogsActivity;

    protected ShipmentService $service;

    public function __construct(ShipmentService $service)
    {
        $this->service = $service;
    }

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $data = Shipment::query()
            ->with(['items'])
            ->leftJoin('suppliers as s', 's.id', '=', 'shipments.supplier_id')
            ->leftJoin('drivers as d', 'd.id', '=', 'shipments.driver_id')
            ->leftJoin('cars as c', 'c.id', '=', 'shipments.car_id')
            ->where('shipments.company_id', $companyId)
            ->select(
                'shipments.*',
                's.supplier_name',
                'd.driver_name',
                'c.car_number'
            )
            ->orderByDesc('shipments.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(StoreShipmentRequest $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $shipment = $this->service->save($request->validated());

        $this->logCreate(
            'Shipments',
            $shipment->id,
            'تم إنشاء حمولة: ' . $shipment->shipment_number
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ الحمولة كمسودة',
            'data' => $shipment
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $shipment = Shipment::query()
            ->with(['items'])
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$shipment) {
            return response()->json([
                'status' => false,
                'message' => 'الحمولة غير موجودة'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'shipment' => $shipment,
                'lines' => $shipment->items
            ]
        ]);
    }

    public function update(StoreShipmentRequest $request, $id)
    {
        $companyId = $this->companyId();

        $shipment = Shipment::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$shipment) {
            return response()->json([
                'status' => false,
                'message' => 'الحمولة غير موجودة'
            ], 404);
        }

        if ($shipment->status !== 'DRAFT') {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن تعديل حمولة بعد اعتمادها'
            ], 400);
        }

        $shipment = $this->service->save($request->validated(), $shipment);

        $this->logUpdate(
            'Shipments',
            $shipment->id,
            'تم تعديل حمولة: ' . $shipment->shipment_number
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تعديل الحمولة',
            'data' => $shipment
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $shipment = Shipment::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$shipment) {
            return response()->json([
                'status' => false,
                'message' => 'الحمولة غير موجودة'
            ], 404);
        }

        if ($shipment->status !== 'DRAFT') {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن حذف حمولة بعد اعتمادها'
            ], 400);
        }

        $shipmentNumber = $shipment->shipment_number;
        $shipment->delete();

        $this->logDelete(
            'Shipments',
            $id,
            'تم حذف حمولة: ' . $shipmentNumber
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الحمولة'
        ]);
    }

    public function sell(Request $request, SellShipmentService $sellService)
{
    $request->validate([
        'customer_id' => 'required|integer',
        'invoice_date' => 'required|date',
        'car_id' => 'nullable|integer',
        'commission_amount' => 'nullable|numeric|min:0',
        'notes' => 'nullable|string',

        'items' => 'required|array|min:1',
        'items.*.shipment_item_id' => 'required|integer',
        'items.*.empty_weight' => 'required|numeric|min:0',
        'items.*.loaded_weight' => 'required|numeric|min:0',
        'items.*.deduction_weight' => 'nullable|numeric|min:0',
        'items.*.unit_price' => 'required|numeric|min:0',
        'items.*.discount_amount' => 'nullable|numeric|min:0',
        'items.*.vat_percent' => 'nullable|numeric|min:0|max:100',
    ]);

    try {
        $result = $sellService->sell($request->all());

        $this->logCreate(
            'ShipmentSales',
            $result['sales_invoice_id'],
            'تم إنشاء فاتورة بيع من حمولة: ' . $result['invoice_number']
        );

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء فاتورة البيع وخصم المخزون',
            'data' => $result
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}

    public function approve($id, ApproveShipmentService $approveService)
{
    $companyId = $this->companyId();

    $shipment = Shipment::query()
        ->with('items')
        ->where('company_id', $companyId)
        ->where('id', $id)
        ->first();

    if (!$shipment) {
        return response()->json([
            'status' => false,
            'message' => 'الحمولة غير موجودة'
        ], 404);
    }

    try {
        $result = $approveService->approve($shipment);

        $this->logUpdate(
            'Shipments',
            $shipment->id,
            'تم اعتماد الحمولة وإنشاء فاتورة شراء: ' . $result['invoice_number']
        );

        return response()->json([
            'status' => true,
            'message' => 'تم اعتماد الحمولة وإنشاء فاتورة الشراء وإدخال المخزون',
            'data' => $result
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}

}