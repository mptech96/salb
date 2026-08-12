<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\FixedAssets\FixedAssetService;
use Illuminate\Http\Request;
use App\Services\FixedAssets\FixedAssetTransferService;
use App\Services\FixedAssets\FixedAssetMaintenanceService;
use App\Services\FixedAssets\FixedAssetDepreciationRunService;
use App\Services\FixedAssets\FixedAssetDisposalService;
use App\Services\FixedAssets\FixedAssetSaleService;
use App\Services\FixedAssets\FixedAssetReportService;
class FixedAssetController extends Controller
{
    public function __construct(
        private FixedAssetService $service,
        private FixedAssetTransferService $transferService,
        private FixedAssetMaintenanceService $maintenanceService,
        private FixedAssetDepreciationRunService $depreciationRunService,
        private FixedAssetDisposalService $disposalService,
        private FixedAssetSaleService $saleService,
        private FixedAssetReportService $reportService

    ) {}

    public function index(Request $request)
    {
        $companyId = (int) $request->header('X-Company-ID');
        $branchId = (int) $request->header('X-Branch-ID');

        $assets = FixedAsset::with('category')
            ->where('company_id', $companyId)
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data' => $assets
        ]);
    }

    public function show(Request $request, $id)
    {
        $companyId = (int) $request->header('X-Company-ID');
        $branchId = (int) $request->header('X-Branch-ID');

        $asset = FixedAsset::with([
            'category',
            'movements',
            'depreciations',
            'maintenances'
        ])
        ->where('company_id', $companyId)
        ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
        ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $asset
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $data['company_id'] = (int) $request->header('X-Company-ID');
        $data['branch_id'] = (int) $request->header('X-Branch-ID');
        $data['created_by'] = (int) $request->header('X-User-ID');

        $asset = $this->service->create($data);

        return response()->json([
            'status' => true,
            'data' => $asset
        ], 201);
    }
    public function transfer(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    $validated = $request->validate([
        'to_branch_id' => [
            'nullable',
            'integer',
        ],

        'to_worker_id' => [
            'nullable',
            'integer',
        ],

        'to_location' => [
            'nullable',
            'string',
            'max:255',
        ],

        'transfer_date' => [
            'required',
            'date',
        ],

        'reference_no' => [
            'nullable',
            'string',
            'max:100',
        ],

        'notes' => [
            'nullable',
            'string',
        ],
    ], [
        'transfer_date.required' =>
            'تاريخ نقل الأصل مطلوب.',

        'transfer_date.date' =>
            'تاريخ نقل الأصل غير صحيح.',

        'to_branch_id.integer' =>
            'الفرع الجديد غير صحيح.',

        'to_worker_id.integer' =>
            'الموظف الجديد غير صحيح.',
    ]);

    try {
        $data = $this->transferService->transfer(
            $id,
            $validated,
            [
                'company_id' => $companyId,
                'created_by' =>
                    $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم نقل الأصل وتحديث العهدة بنجاح.',
            'data' => $data,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}
public function createMaintenance(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    $validated = $request->validate([
        'maintenance_date' => ['required', 'date'],
        'maintenance_type' => ['nullable', 'string', 'max:100'],
        'supplier_name' => ['nullable', 'string', 'max:200'],
        'invoice_number' => ['nullable', 'string', 'max:100'],
        'maintenance_cost' => ['required', 'numeric', 'min:0'],
        'cost_treatment' => ['required', 'in:EXPENSE,CAPITALIZE'],
        'expense_account_id' => ['nullable', 'integer'],
        'payment_account_id' => ['nullable', 'integer'],
        'description' => ['nullable', 'string'],
        'notes' => ['nullable', 'string'],
    ]);

    try {
        $data = $this->maintenanceService->create(
            $id,
            $validated,
            [
                'company_id' => $companyId,
                'created_by' => $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم فتح عملية صيانة للأصل.',
            'data' => $data,
        ], 201);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}

public function approveMaintenance(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    try {
        $data = $this->maintenanceService->approve(
            $id,
            [
                'company_id' => $companyId,
                'created_by' => $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم اعتماد عملية الصيانة وإنشاء القيد المحاسبي.',
            'data' => $data,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}

public function completeMaintenance(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    try {
        $data = $this->maintenanceService->complete(
            $id,
            [
                'company_id' => $companyId,
                'created_by' => $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم إغلاق الصيانة وإعادة الأصل إلى الحالة النشطة.',
            'data' => $data,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}
public function runDepreciation(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    $validated = $request->validate([
        'depreciation_month' => [
            'required',
            'date',
        ],

        'branch_id' => [
            'nullable',
            'integer',
        ],
    ], [
        'depreciation_month.required' =>
            'شهر الإهلاك مطلوب.',

        'depreciation_month.date' =>
            'تاريخ شهر الإهلاك غير صحيح.',
    ]);

    try {

        $result = $this->depreciationRunService->run(
            $validated['depreciation_month'],
            [
                'company_id' => $companyId,
                'branch_id' => $validated['branch_id'] ?? null,
                'created_by' => $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تشغيل الإهلاك بنجاح.',
            'data' => $result,
        ]);

    } catch (\Throwable $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);

    }
}
public function dispose(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    $validated = $request->validate([
        'disposal_date' => [
            'required',
            'date',
        ],

        'reference_no' => [
            'nullable',
            'string',
            'max:100',
        ],

        'notes' => [
            'nullable',
            'string',
        ],

        'asset_account_id' => [
            'nullable',
            'integer',
        ],

        'accumulated_account_id' => [
            'nullable',
            'integer',
        ],

        'disposal_loss_account_id' => [
            'nullable',
            'integer',
        ],
    ], [
        'disposal_date.required' =>
            'تاريخ شطب الأصل مطلوب.',

        'disposal_date.date' =>
            'تاريخ شطب الأصل غير صحيح.',
    ]);

    try {
        $data = $this->disposalService->dispose(
            $id,
            $validated,
            [
                'company_id' => $companyId,
                'created_by' => $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم شطب الأصل وإنشاء القيد المحاسبي بنجاح.',
            'data' => $data,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}
public function sell(Request $request, int $id)
{
    $companyId = (int) $request->header('X-Company-ID');

    if (!$companyId) {
        return response()->json([
            'status' => false,
            'message' => 'لم يتم تحديد الشركة الحالية.',
        ], 400);
    }

    $validated = $request->validate([
        'sale_date' => [
            'required',
            'date',
        ],

        'sale_amount' => [
            'required',
            'numeric',
            'min:0',
        ],

        'collection_account_id' => [
            'required',
            'integer',
        ],

        'asset_account_id' => [
            'nullable',
            'integer',
        ],

        'accumulated_account_id' => [
            'nullable',
            'integer',
        ],

        'disposal_gain_account_id' => [
            'nullable',
            'integer',
        ],

        'disposal_loss_account_id' => [
            'nullable',
            'integer',
        ],

        'reference_no' => [
            'nullable',
            'string',
            'max:100',
        ],

        'notes' => [
            'nullable',
            'string',
        ],
    ], [
        'sale_date.required' =>
            'تاريخ بيع الأصل مطلوب.',

        'sale_date.date' =>
            'تاريخ بيع الأصل غير صحيح.',

        'sale_amount.required' =>
            'قيمة بيع الأصل مطلوبة.',

        'sale_amount.numeric' =>
            'قيمة بيع الأصل غير صحيحة.',

        'collection_account_id.required' =>
            'حساب تحصيل قيمة البيع مطلوب.',
    ]);

    try {
        $data = $this->saleService->sell(
            $id,
            $validated,
            [
                'company_id' => $companyId,
                'created_by' =>
                    $request->header('X-User-ID')
                    ? (int) $request->header('X-User-ID')
                    : null,
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'تم بيع الأصل وإنشاء القيد المحاسبي بنجاح.',
            'data' => $data,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
        ], 422);
    }
}
public function reportSummary(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    return response()->json([
        'status' => true,
        'data' => $this->reportService->summary([
            'company_id' => $companyId,
            'branch_id' => $request->branch_id,
        ]),
    ]);
}
public function reportAssets(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    return response()->json([
        'status' => true,
        'data' => $this->reportService->assets([
            ...$request->all(),
            'company_id' => $companyId,
        ]),
    ]);
}
public function reportDepreciations(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    return response()->json([
        'status' => true,
        'data' => $this->reportService->depreciations([
            ...$request->all(),
            'company_id' => $companyId,
        ]),
    ]);
}
public function reportMaintenances(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    return response()->json([
        'status' => true,
        'data' => $this->reportService->maintenances([
            ...$request->all(),
            'company_id' => $companyId,
        ]),
    ]);
}
public function reportMovements(Request $request)
{
    $companyId = (int) $request->header('X-Company-ID');

    return response()->json([
        'status' => true,
        'data' => $this->reportService->movements([
            ...$request->all(),
            'company_id' => $companyId,
        ]),
    ]);
}
}
