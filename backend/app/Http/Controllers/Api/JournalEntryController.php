<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\JournalService;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function index(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);

        $query = DB::table('journal_entries as e')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->where('e.company_id', $companyId);

        if ($branchId !== null) {
            $query->where('e.branch_id', $branchId);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('e.entry_date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('e.entry_date', '<=', $request->to_date);
        }

        if ($request->filled('source_type')) {
            $query->where('e.source_type', $request->source_type);
        }

        if ($request->filled('q')) {
            $search = '%' . trim((string) $request->q) . '%';

            $query->where(function ($q) use ($search) {
                $q->where('e.entry_number', 'like', $search)
                    ->orWhere('e.reference_no', 'like', $search)
                    ->orWhere('e.description', 'like', $search)
                    ->orWhere('e.source_type', 'like', $search);
            });
        }

        $data = $query
            ->select(
                'e.*',
                'b.branch_name',
                'u.name as created_by_name',
                DB::raw(
                    '(SELECT COALESCE(SUM(debit),0)
                      FROM journal_entry_lines l
                      WHERE l.journal_entry_id=e.id) total_debit'
                ),
                DB::raw(
                    '(SELECT COALESCE(SUM(credit),0)
                      FROM journal_entry_lines l
                      WHERE l.journal_entry_id=e.id) total_credit'
                )
            )
            ->orderByDesc('e.entry_date')
            ->orderByDesc('e.id')
            ->limit(500)
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function store(
        Request $request,
        JournalService $service,
        AccountingContext $context
    ) {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'entry_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $entryId = $service->post([
                ...$validated,
                'company_id' => $context->companyId($request),
                'branch_id' => \App\Support\TenantScope::targetBranchId(
                    $request,
                    true
                ),
                'source_type' => 'MANUAL',
                'is_system_generated' => 0,
                'created_by' => $context->userId($request),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم ترحيل القيد اليدوي بنجاح.',
                'id' => $entryId,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(
        Request $request,
        int $id,
        JournalService $service,
        AccountingContext $context
    ) {
        $data = $service->show(
            $context->companyId($request),
            $id,
            $context->branchFilter($request)
        );

        return $data
            ? response()->json(['status' => true, 'data' => $data])
            : response()->json([
                'status' => false,
                'message' => 'القيد غير موجود ضمن نطاقك.',
            ], 404);
    }

    public function reverse(
        Request $request,
        int $id,
        JournalService $service,
        AccountingContext $context
    ) {
        $companyId = $context->companyId($request);

        $entry = DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$entry) {
            return response()->json([
                'status' => false,
                'message' => 'القيد غير موجود.',
            ], 404);
        }

        if (strtoupper((string) $entry->source_type) !== 'MANUAL') {
            return response()->json([
                'status' => false,
                'message' => 'عكس القيود الآلية يتم من العملية الأصلية، وليس من شاشة القيود.',
            ], 422);
        }

        $branchFilter = $context->branchFilter($request);

        if ($branchFilter !== null
            && (int) $entry->branch_id !== $branchFilter) {
            return response()->json([
                'status' => false,
                'message' => 'القيد خارج نطاق الفرع.',
            ], 403);
        }

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $reversalEntryId = $service->reverse(
                $companyId,
                $id,
                [
                    'entry_date' => $validated['entry_date'],
                    'reason' => $validated['reason'],
                    'created_by' => $context->userId($request),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء القيد العكسي بنجاح.',
                'reversal_entry_id' => $reversalEntryId,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
