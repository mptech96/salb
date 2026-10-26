<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DriverShipmentStatement;
use Illuminate\Http\Request;

final class DriverShipmentStatementController extends Controller
{
    public function drivers(Request $request, DriverShipmentStatement $service)
    {
        return response()->json(['status'=>true,'data'=>$service->drivers($request)]);
    }
    public function show(Request $request, int $id, DriverShipmentStatement $service)
    {
        return response()->json(['status'=>true,'data'=>$service->report($request,$id)]);
    }
}
