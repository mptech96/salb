<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoadWaybillController extends Controller
{
    public function index(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);
        $rows = DB::table('road_waybills')
            ->where('company_id', $companyId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($request->query('search'), fn ($query) => $query->where(function ($q) use ($request) {
                $search = '%' . addcslashes((string)$request->query('search'), '%_') . '%';
                $q->where('document_number', 'like', $search)->orWhere('driver_name', 'like', $search)
                    ->orWhere('material_owner', 'like', $search);
            }))
            ->orderByDesc('id')->paginate(min(100, max(1, (int)$request->query('per_page', 25))));
        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function meta(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);
        $branches = DB::table('branches')->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('id', $branchId))
            ->select('id', 'branch_name')->orderBy('id')->get();
        $drivers = DB::table('drivers')->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('id', 'branch_id', 'driver_name')->orderBy('driver_name')->limit(250)->get();
        $cars = DB::table('cars')->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('id', 'branch_id', 'plate_number')->orderBy('id')->limit(250)->get();
        $shipments = DB::table('shipments')->where('company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('id', 'branch_id', 'shipment_number')->orderByDesc('id')->limit(100)->get();
        return response()->json(['status' => true, 'data' => compact('branches', 'drivers', 'cars', 'shipments')]);
    }

    public function show(Request $request, int $id, AccountingContext $context)
    {
        $row = $this->scoped($request, $context)->where('id', $id)->first();
        abort_unless($row, 404);
        $row->lines = json_decode((string)$row->lines_json, true) ?: [];
        $row->visibility = json_decode((string)$row->visibility_json, true) ?: [];
        return response()->json(['status' => true, 'data' => $row]);
    }

    public function store(Request $request, AccountingContext $context)
    {
        $payload = $this->validatePayload($request, $context);
        $id = DB::transaction(function () use ($payload, $request, $context) {
            $id = DB::table('road_waybills')->insertGetId($payload + [
                'company_id' => $context->companyId($request),
                'created_by' => $context->userId($request),
                'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('road_waybills')->where('id', $id)->update(['document_number' => sprintf('RWB-%d-%06d', $context->companyId($request), $id)]);
            return $id;
        });
        return $this->show($request, $id, $context);
    }

    public function update(Request $request, int $id, AccountingContext $context)
    {
        $current = $this->scoped($request, $context)->where('id', $id)->first();
        abort_unless($current, 404);
        abort_unless($current->status === 'DRAFT', 409, 'Only a draft road waybill may be edited.');
        $payload = $this->validatePayload($request, $context);
        DB::table('road_waybills')->where('id', $id)->where('company_id', $context->companyId($request))
            ->where('branch_id', $current->branch_id)->update($payload + ['updated_at' => now()]);
        return $this->show($request, $id, $context);
    }

    private function scoped(Request $request, AccountingContext $context)
    {
        return DB::table('road_waybills')->where('company_id', $context->companyId($request))
            ->when($context->branchFilter($request), fn ($query, $branch) => $query->where('branch_id', $branch));
    }

    private function validatePayload(Request $request, AccountingContext $context): array
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer', 'document_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:document_date',
            'driver_id' => 'nullable|integer', 'car_id' => 'nullable|integer', 'shipment_id' => 'nullable|integer',
            'driver_name' => 'required|string|max:200', 'nationality' => 'nullable|string|max:100',
            'identity_number' => 'nullable|string|max:100', 'vehicle_type' => 'nullable|string|max:100',
            'plate_number' => 'nullable|string|max:100', 'material_owner' => 'required|string|max:200',
            'origin_city' => 'required|string|max:150', 'destination_city' => 'required|string|max:150',
            'phone' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.item_name' => 'required|string|max:200',
            'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.unit' => 'required|string|max:30',
            'visibility' => 'nullable|array', 'visibility.*' => 'boolean',
            'template_key' => 'nullable|in:ROAD_BOXES,CLASSIC,MODERN,COMPACT,FULL_HEADER',
        ]);
        $scoped = TenantScope::branchId($request);
        if ($scoped !== null && !empty($data['branch_id']) && (int)$data['branch_id'] !== $scoped) {
            throw ValidationException::withMessages(['branch_id' => ['الفرع خارج نطاق المستخدم.']]);
        }
        $branchId = $context->branchForOperation($request);
        $companyId = $context->companyId($request);
        foreach (['driver_id' => 'drivers', 'car_id' => 'cars', 'shipment_id' => 'shipments'] as $field => $table) {
            if (empty($data[$field])) continue;
            $query = DB::table($table)->where('id', $data[$field])->where('company_id', $companyId);
            $query->where(function ($q) use ($table, $branchId, $scoped) {
                $q->where('branch_id', $branchId);
                if ($scoped === null && $table !== 'shipments') $q->orWhereNull('branch_id');
            });
            if (!$query->exists()) throw ValidationException::withMessages([$field => ['المرجع لا يتبع الشركة والفرع الحاليين.']]);
        }
        $data['branch_id'] = $branchId;
        $data['lines_json'] = json_encode($data['lines'], JSON_UNESCAPED_UNICODE);
        $data['visibility_json'] = json_encode($data['visibility'] ?? [], JSON_UNESCAPED_UNICODE);
        $data['template_key'] = $data['template_key'] ?? 'ROAD_BOXES';
        unset($data['lines'], $data['visibility']);
        return $data;
    }
}
