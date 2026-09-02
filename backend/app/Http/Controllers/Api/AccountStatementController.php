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
        try{return response()->json([
            'status' => true,
            'data' => $service->ledger(
                $context->companyId($request),
                $this->resolvedBranchId($request, $context),
                $id,
                $request->validate([
                    'financial_year_id'=>'nullable|integer',
                    'from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date','cost_center_id'=>'nullable|integer',
                    'party_type'=>'nullable|string|max:30','party_id'=>'nullable|integer','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|in:25,50,100','search'=>'nullable|string|max:200',
                ])
            ),
        ]);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function accountExport(Request $request,int $id,AccountingReportService $service,AccountingContext $context)
    {
        $filters=$request->validate(['format'=>'required|in:csv,xls','financial_year_id'=>'nullable|integer','from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date','cost_center_id'=>'nullable|integer','party_type'=>'nullable|string|max:30','party_id'=>'nullable|integer','search'=>'nullable|string|max:200']);
        $format=$filters['format'];unset($filters['format']);$companyId=$context->companyId($request);$branchId=$this->resolvedBranchId($request,$context);
        try{$service->ledger($companyId,$branchId,$id,[...$filters,'page'=>1,'per_page'=>25]);}catch(\Throwable$e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
        // تنفيذ التصدير على الخادم وبنفس مرشحات الأستاذ، على دفعات 100 سجل دون تحميل النطاق في المتصفح.
        return response()->streamDownload(function()use($service,$companyId,$branchId,$id,$filters,$format){
            $out=fopen('php://output','w');if($format==='csv')fwrite($out,"\xEF\xBB\xBF");
            $headers=['التاريخ','رقم القيد','المصدر','المرجع/البيان','الفرع','مدين','دائن','الرصيد','الجانب'];
            if($format==='xls')echo "<html dir=\"rtl\"><meta charset=\"UTF-8\"><table border=\"1\"><tr><th>".implode('</th><th>',$headers).'</th></tr>';
            else fputcsv($out,$headers);
            foreach($service->ledgerExportRows($companyId,$branchId,$id,$filters)as$r){$row=[$r->entry_date,$r->entry_number,$r->source_type,$r->description?:$r->entry_description,$r->branch_name?:'الشركة',$r->debit,$r->credit,$r->running_balance,$r->running_side];if($format==='xls')echo '<tr>'.implode('',array_map(fn($v)=>'<td>'.htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8').'</td>',$row)).'</tr>';else fputcsv($out,$row);}
            if($format==='xls')echo '</table></html>';fclose($out);
        },'sulb-ledger-'.$id.'.'.$format,['Content-Type'=>$format==='csv'?'text/csv; charset=UTF-8':'application/vnd.ms-excel; charset=UTF-8']);
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
                    'page',
                    'per_page',
                    'search',
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
