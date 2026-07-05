<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;

class CarController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $cars = Car::where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $cars
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
            'branch_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'driver_id' => 'nullable|integer',
            'car_number' => 'nullable|string|max:100',
            'plate_number' => 'nullable|string|max:100',
            'weight_card_number' => 'nullable|string|max:100',
            'gross_weight' => 'nullable|numeric',
            'deduction_weight' => 'nullable|numeric',
            'net_weight' => 'nullable|numeric',
            'transport_cost' => 'nullable|numeric',
            'extra_cost' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'car_status' => 'nullable|string|max:50',
            'arrival_date' => 'nullable|date',
        ]);

        $gross = $request->gross_weight ?? 0;
        $deduction = $request->deduction_weight ?? 0;

        $car = Car::create([
            'company_id' => $companyId,
            'branch_id' => $request->branch_id,
            'supplier_id' => $request->supplier_id,
            'driver_id' => $request->driver_id,
            'car_number' => $request->car_number,
            'plate_number' => $request->plate_number,
            'weight_card_number' => $request->weight_card_number,
            'gross_weight' => $gross,
            'deduction_weight' => $deduction,
            'net_weight' => $request->net_weight ?? ($gross - $deduction),
            'transport_cost' => $request->transport_cost ?? 0,
            'extra_cost' => $request->extra_cost ?? 0,
            'notes' => $request->notes,
            'car_status' => $request->car_status ?? 'OPEN',
            'arrival_date' => $request->arrival_date,
        ]);

        $this->logCreate(
            'Cars',
            $car->id,
            'تم إنشاء سيارة: ' . ($car->car_number ?: $car->plate_number ?: $car->id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء السيارة بنجاح',
            'data' => $car
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $car = Car::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'السيارة غير موجودة'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $car
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $car = Car::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'السيارة غير موجودة'
            ], 404);
        }

        $request->validate([
            'branch_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'driver_id' => 'nullable|integer',
            'car_number' => 'nullable|string|max:100',
            'plate_number' => 'nullable|string|max:100',
            'weight_card_number' => 'nullable|string|max:100',
            'gross_weight' => 'nullable|numeric',
            'deduction_weight' => 'nullable|numeric',
            'net_weight' => 'nullable|numeric',
            'transport_cost' => 'nullable|numeric',
            'extra_cost' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'car_status' => 'nullable|string|max:50',
            'arrival_date' => 'nullable|date',
        ]);

        $oldCarName = $car->car_number ?: $car->plate_number ?: $car->id;

        $gross = $request->gross_weight ?? 0;
        $deduction = $request->deduction_weight ?? 0;

        $car->update([
            'branch_id' => $request->branch_id,
            'supplier_id' => $request->supplier_id,
            'driver_id' => $request->driver_id,
            'car_number' => $request->car_number,
            'plate_number' => $request->plate_number,
            'weight_card_number' => $request->weight_card_number,
            'gross_weight' => $gross,
            'deduction_weight' => $deduction,
            'net_weight' => $request->net_weight ?? ($gross - $deduction),
            'transport_cost' => $request->transport_cost ?? 0,
            'extra_cost' => $request->extra_cost ?? 0,
            'notes' => $request->notes,
            'car_status' => $request->car_status ?? 'OPEN',
            'arrival_date' => $request->arrival_date,
        ]);

        $this->logUpdate(
            'Cars',
            $car->id,
            'تم تعديل سيارة: ' . $oldCarName . ' إلى ' . ($car->car_number ?: $car->plate_number ?: $car->id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث السيارة بنجاح',
            'data' => $car
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $car = Car::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$car) {
            return response()->json([
                'status' => false,
                'message' => 'السيارة غير موجودة'
            ], 404);
        }

        $carName = $car->car_number ?: $car->plate_number ?: $car->id;

        $car->delete();

        $this->logDelete(
            'Cars',
            $id,
            'تم حذف سيارة: ' . $carName
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف السيارة بنجاح'
        ]);
    }
}