<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\AccountingReportService;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Accounting\PostingSupport;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountStatementController extends Controller
{
    public function entities(Request $request, string $kind, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $this->resolvedBranchId($request, $context);
        $kind = strtolower(trim($kind));

        if ($kind === 'account') {
            $rows = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->where('is_group', 0)
                ->where('allow_posting', 1)
                ->select('id', 'account_code', 'account_name')
                ->orderBy('account_code')
                ->get();

            return response()->json(['status' => true, 'data' => $rows]);
        }

        $map = [
            'customer' => ['customers', 'customer_name'],
            'supplier' => ['suppliers', 'supplier_name'],
            'driver' => ['drivers', 'driver_name'],
            'worker' => ['workers', 'worker_name'],
        ];

        if (!isset($map[$kind])) {
            return response()->json([
                'status' => false,
                'message' => 'نوع كشف الحساب غير معروف.',
            ], 404);
        }

        [$table, $name] = $map[$kind];

        $query = DB::table($table)
            ->where('company_id', $companyId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', 1);
        }

        return response()->json([
            'status' => true,
            'data' => $query->select('id', $name)->orderBy($name)->get(),
        ]);
    }

    public function account(
        Request $request,
        int $id,
        AccountingReportService $service,
        AccountingContext $context
    ) {
        return response()->json([
            'status' => true,
            'data' => $service->ledger(
                $context->companyId($request),
                $this->resolvedBranchId($request, $context),
                $id,
                $request->only([
                    'financial_year_id',
                    'from_date',
                    'to_date',
                    'cost_center_id',
                    'party_type',
                    'party_id',
                ])
            ),
        ]);
    }

    private function party(
        Request $request,
        string $type,
        int $id,
        AccountingReportService $service,
        AccountingContext $context,
        PostingSupport $posting
    ) {
        $companyId = $context->companyId($request);
        $map = [
            'CUSTOMER' => ['customers', 'customer_name', 'CUSTOMER_ACCOUNT'],
            'SUPPLIER' => ['suppliers', 'supplier_name', 'SUPPLIER_ACCOUNT'],
            'DRIVER' => ['drivers', 'driver_name', 'DRIVER_ADVANCE_ACCOUNT'],
            'WORKER' => ['workers', 'worker_name', 'WORKER_PAYABLE_ACCOUNT'],
        ];

        [$table, $name, $setting] = $map[$type];

        $query = DB::table($table)
            ->where('company_id', $companyId)
            ->where('id', $id);

        $branchId = $this->resolvedBranchId($request, $context);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $entity = $query->first();

        if (!$entity) {
            return response()->json([
                'status' => false,
                'message' => 'الحساب الفرعي غير موجود ضمن نطاقك.',
            ], 404);
        }

        $data = $service->ledger(
            $companyId,
            $branchId,
            $posting->setting($companyId, $setting),
            array_merge(
                $request->only([
                    'financial_year_id',
                    'from_date',
                    'to_date',
                    'cost_center_id',
                ]),
                ['party_type' => $type, 'party_id' => $id]
            )
        );

        $data['name'] = $entity->{$name};

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    private function resolvedBranchId(Request $request, AccountingContext $context): ?int
    {
        $scoped = $context->branchFilter($request);
        if ($scoped !== null) {
            return $scoped;
        }

        $requested = (int) $request->input('branch_id', 0);
        if ($requested > 0) {
            TenantScope::assertBranchBelongsToCompany($requested, $request);
            return $requested;
        }

        return null;
    }

    public function customer(Request $request, int $id, AccountingReportService $service, AccountingContext $context, PostingSupport $posting)
    {
        return $this->party($request, 'CUSTOMER', $id, $service, $context, $posting);
    }

    public function supplier(Request $request, int $id, AccountingReportService $service, AccountingContext $context, PostingSupport $posting)
    {
        return $this->party($request, 'SUPPLIER', $id, $service, $context, $posting);
    }

    public function driver(Request $request, int $id, AccountingReportService $service, AccountingContext $context, PostingSupport $posting)
    {
        return $this->party($request, 'DRIVER', $id, $service, $context, $posting);
    }

    public function worker(Request $request, int $id, AccountingReportService $service, AccountingContext $context, PostingSupport $posting)
    {
        return $this->party($request, 'WORKER', $id, $service, $context, $posting);
    }
}
