<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\AccountingService;

class WorkerController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function branchId()
    {
        return request()->header('X-Branch-ID');
    }

    private function clean($v)
    {
        return $v === '' ? null : $v;
    }

    public function index()
    {
        $companyId = $this->companyId();
        $branchScopeId = (int) $this->branchId();

        return response()->json([
            'status' => true,
            'data' => DB::table('workers')
                ->where('company_id', $companyId)
                ->when($branchScopeId > 0, fn ($q) => $q->where('branch_id', $branchScopeId))
                ->orderByDesc('id')
                ->get()
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json(['status' => false, 'message' => 'لم يتم تحديد الشركة'], 400);
        }

        $request->validate([
            'worker_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'salary_type' => ['required', Rule::in(['HOURLY','DAILY','WEEKLY','MONTHLY'])],
            'salary_rate' => 'nullable|numeric|min:0',
            'worker_status' => ['nullable', Rule::in(['ACTIVE','INACTIVE','ENDED'])],
            'contract_type' => ['nullable', Rule::in(['FULL_TIME','PART_TIME','TEMP'])],
        ], [
            'worker_name.required' => 'اسم العامل مطلوب',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة',
            'salary_type.in' => 'نوع الراتب غير صحيح',
            'salary_rate.numeric' => 'الأجر يجب أن يكون رقم',
        ]);

        try {
            $id = DB::table('workers')->insertGetId($this->workerPayload($request, $companyId, true));

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء العامل بنجاح',
                'id' => $id
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل حفظ العامل: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $branchScopeId = (int) $this->branchId();

        $worker = DB::table('workers')
            ->where('company_id', $companyId)
            ->when($branchScopeId > 0, fn ($q) => $q->where('branch_id', $branchScopeId))
            ->where('id', $id)
            ->first();

        if (!$worker) {
            return response()->json(['status' => false, 'message' => 'العامل غير موجود'], 404);
        }

        $loans = DB::table('worker_loans')->where('company_id', $companyId)->when($branchScopeId > 0, fn ($q) => $q->where('branch_id', $branchScopeId))->where('worker_id', $id)->orderByDesc('id')->get();
        $commissions = DB::table('worker_commissions')->where('company_id', $companyId)->when($branchScopeId > 0, fn ($q) => $q->where('branch_id', $branchScopeId))->where('worker_id', $id)->orderByDesc('id')->get();
        $attendance = DB::table('worker_attendance')->where('company_id', $companyId)->when($branchScopeId > 0, fn ($q) => $q->where('branch_id', $branchScopeId))->where('worker_id', $id)->orderByDesc('attendance_date')->limit(50)->get();

        $salaryLines = DB::table('worker_salary_lines as l')
            ->leftJoin('worker_salary_runs as r', 'r.id', '=', 'l.salary_run_id')
            ->where('l.company_id', $companyId)
            ->when($branchScopeId > 0, fn ($q) => $q->where('r.branch_id', $branchScopeId))
            ->where('l.worker_id', $id)
            ->select('l.*', 'r.run_number', 'r.salary_month', 'r.status as run_status')
            ->orderByDesc('l.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'worker' => $worker,
                'loans' => $loans,
                'commissions' => $commissions,
                'attendance' => $attendance,
                'salary_lines' => $salaryLines,
                'summary' => [
                    'total_loans' => $loans->sum('amount'),
                    'total_commissions' => $commissions->sum('amount'),
                    'total_net_salary' => $salaryLines->sum('net_salary'),
                ]
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $request->validate([
            'worker_name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'salary_type' => ['required', Rule::in(['HOURLY','DAILY','WEEKLY','MONTHLY'])],
            'salary_rate' => 'nullable|numeric|min:0',
            'worker_status' => ['nullable', Rule::in(['ACTIVE','INACTIVE','ENDED'])],
            'contract_type' => ['nullable', Rule::in(['FULL_TIME','PART_TIME','TEMP'])],
        ]);

        try {
            DB::table('workers')
                ->where('company_id', $companyId)
                ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
                ->where('id', $id)
                ->update($this->workerPayload($request, $companyId, false));

            return response()->json(['status' => true, 'message' => 'تم تعديل العامل']);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل تعديل العامل: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::table('workers')
            ->where('company_id', $this->companyId())
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->delete();

        return response()->json(['status' => true, 'message' => 'تم حذف العامل']);
    }

   public function addLoan(Request $request, $id, \App\Services\AccountingService $accounting)
{
    $companyId = $this->companyId();
    $branchId = $request->branch_id ?? $this->branchId();

    $request->validate([
        'loan_date' => 'required|date',
        'amount' => 'required|numeric|min:0.001',
        'payment_method' => 'nullable|string',
    ]);

    return DB::transaction(function () use ($request, $id, $companyId, $branchId, $accounting) {

        $lastId = DB::table('vouchers')->where('company_id', $companyId)->max('id') ?? 0;
        $voucherNumber = 'PAY-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

        $voucherId = DB::table('vouchers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'voucher_type_id' => 2,
            'voucher_number' => $voucherNumber,
            'voucher_date' => $request->loan_date,
            'reference_type' => 'WORKER_LOAN',
            'reference_id' => 0,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method ?? 'CASH',
            'notes' => 'سند صرف سلفة عامل',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loanId = DB::table('worker_loans')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'worker_id' => $id,
            'loan_date' => $request->loan_date,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method ?? 'CASH',
            'voucher_id' => $voucherId,
            'notes' => $request->notes,
            'created_by' => request()->header('X-User-ID'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vouchers')
            ->where('id', $voucherId)
            ->update([
                'reference_id' => $loanId,
                'notes' => 'سند صرف سلفة عامل رقم ' . $loanId,
                'updated_at' => now(),
            ]);

        $loanAccount = $accounting->settingAccount($companyId, 'WORKER_LOAN_ACCOUNT');
        $cashAccount = $accounting->settingAccount($companyId, 'CASH_ACCOUNT');

        $entryId = $accounting->post([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'entry_date' => $request->loan_date,
            'source_type' => 'WORKER_LOAN',
            'source_id' => $loanId,
            'description' => 'صرف سلفة عامل رقم ' . $loanId,
            'lines' => [
                [
                    'account_id' => $loanAccount,
                    'debit' => $request->amount,
                    'credit' => 0,
                    'description' => 'سلفة عامل',
                ],
                [
                    'account_id' => $cashAccount,
                    'debit' => 0,
                    'credit' => $request->amount,
                    'description' => 'صرف نقدي لسلفة عامل',
                ],
            ],
        ]);

        DB::table('worker_loans')
            ->where('id', $loanId)
            ->update([
                'journal_entry_id' => $entryId,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ السلفة وإنشاء سند الصرف والقيد',
            'id' => $loanId,
            'voucher_id' => $voucherId,
            'journal_entry_id' => $entryId
        ]);
    });
}

    public function addCommission(Request $request, $id, AccountingService $accounting)
    {
    $companyId = $this->companyId();
    $branchId = $request->branch_id ?? $this->branchId();

    $request->validate([
        'commission_date' => 'required|date',
        'amount' => 'required|numeric|min:0.001',
        'status' => 'nullable|in:PENDING,APPROVED,PAID',
    ]);

    return DB::transaction(function () use ($request, $id, $companyId, $branchId, $accounting) {

        $status = $request->status ?? 'PENDING';

        $commissionId = DB::table('worker_commissions')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'worker_id' => $id,
            'shipment_id' => $request->shipment_id,
            'sales_invoice_id' => $request->sales_invoice_id,
            'commission_date' => $request->commission_date,
            'amount' => $request->amount,
            'status' => $status,
            'notes' => $request->notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journalEntryId = null;

        if (in_array($status, ['APPROVED', 'PAID'])) {
            $expenseAccount = $accounting->settingAccount($companyId, 'WORKER_COMMISSION_EXPENSE_ACCOUNT');
            $payableAccount = $accounting->settingAccount($companyId, 'WORKER_PAYABLE_ACCOUNT');

            $journalEntryId = $accounting->post([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'entry_date' => $request->commission_date,
                'source_type' => 'WORKER_COMMISSION',
                'source_id' => $commissionId,
                'description' => 'اعتماد عمولة عامل رقم ' . $commissionId,
                'lines' => [
                    [
                        'account_id' => $expenseAccount,
                        'debit' => $request->amount,
                        'credit' => 0,
                        'description' => 'مصروف عمولة عامل',
                    ],
                    [
                        'account_id' => $payableAccount,
                        'debit' => 0,
                        'credit' => $request->amount,
                        'description' => 'استحقاق عمولة عامل',
                    ],
                ],
            ]);

            DB::table('worker_commissions')
                ->where('id', $commissionId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ العمولة',
            'id' => $commissionId,
            'journal_entry_id' => $journalEntryId
        ]);
    });
   } 

    public function addAttendance(Request $request, $id)
    {
        $request->validate(['attendance_date' => 'required|date']);

        $attendanceId = DB::table('worker_attendance')->insertGetId([
            'company_id' => $this->companyId(),
            'branch_id' => $request->branch_id ?? $this->branchId(),
            'worker_id' => $id,
            'attendance_date' => $request->attendance_date,
            'check_in' => $this->clean($request->check_in),
            'check_out' => $this->clean($request->check_out),
            'work_hours' => $request->work_hours ?? 0,
            'overtime_hours' => $request->overtime_hours ?? 0,
            'status' => $request->status ?? 'PRESENT',
            'notes' => $request->notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'تم حفظ الحضور', 'id' => $attendanceId]);
    }

    private function workerPayload(Request $request, $companyId, bool $isCreate): array
    {
        $data = [
            'branch_id' => $request->branch_id ?? $this->branchId(),
            'employee_no' => $this->clean($request->employee_no) ?: ($isCreate ? $this->generateEmployeeNo($companyId) : null),
            'worker_name' => $request->worker_name,
            'phone' => $this->clean($request->phone),
            'email' => $this->clean($request->email),
            'job_title' => $this->clean($request->job_title),
            'department' => $this->clean($request->department),
            'salary_type' => $request->salary_type ?? 'MONTHLY',
            'salary_rate' => $request->salary_rate ?? 0,
            'hire_date' => $this->clean($request->hire_date),
            'end_date' => $this->clean($request->end_date),
            'national_id' => $this->clean($request->national_id),
            'iqama_number' => $this->clean($request->iqama_number),
            'passport_number' => $this->clean($request->passport_number),
            'nationality' => $this->clean($request->nationality),
            'birth_date' => $this->clean($request->birth_date),
            'bank_name' => $this->clean($request->bank_name),
            'iban' => $this->clean($request->iban),
            'emergency_contact' => $this->clean($request->emergency_contact),
            'emergency_phone' => $this->clean($request->emergency_phone),
            'contract_type' => $request->contract_type ?? 'FULL_TIME',
            'worker_status' => $request->worker_status ?? 'ACTIVE',
            'photo' => $this->clean($request->photo),
            'notes' => $this->clean($request->notes),
            'updated_at' => now(),
        ];

        if ($isCreate) {
            $data['company_id'] = $companyId;
            $data['created_at'] = now();
        } else {
            if ($data['employee_no'] === null) unset($data['employee_no']);
        }

        return $data;
    }

    private function generateEmployeeNo($companyId)
    {
        $lastId = DB::table('workers')->where('company_id', $companyId)->max('id') ?? 0;
        return 'EMP-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
    }
    public function approveCommission($commissionId, \App\Services\AccountingService $accounting)
{
    $companyId = $this->companyId();

    return DB::transaction(function () use ($commissionId, $companyId, $accounting) {

        $commission = DB::table('worker_commissions')
            ->where('company_id', $companyId)
            ->where('id', $commissionId)
            ->first();

        if (!$commission) {
            return response()->json(['status' => false, 'message' => 'العمولة غير موجودة'], 404);
        }

        if ($commission->status !== 'PENDING') {
            return response()->json(['status' => false, 'message' => 'لا يمكن اعتماد عمولة ليست معلقة'], 400);
        }

        $expenseAccount = $accounting->settingAccount($companyId, 'WORKER_COMMISSION_EXPENSE_ACCOUNT');
        $payableAccount = $accounting->settingAccount($companyId, 'WORKER_PAYABLE_ACCOUNT');

        $entryId = $accounting->post([
            'company_id' => $companyId,
            'branch_id' => $commission->branch_id,
            'entry_date' => $commission->commission_date,
            'source_type' => 'WORKER_COMMISSION',
            'source_id' => $commission->id,
            'description' => 'اعتماد عمولة عامل رقم ' . $commission->id,
            'lines' => [
                ['account_id' => $expenseAccount, 'debit' => $commission->amount, 'credit' => 0, 'description' => 'مصروف عمولة عامل'],
                ['account_id' => $payableAccount, 'debit' => 0, 'credit' => $commission->amount, 'description' => 'استحقاق عمولة عامل'],
            ],
        ]);

        DB::table('worker_commissions')
            ->where('id', $commissionId)
            ->update([
                'status' => 'APPROVED',
                'journal_entry_id' => $entryId,
                'updated_at' => now(),
            ]);

        return response()->json(['status' => true, 'message' => 'تم اعتماد العمولة', 'journal_entry_id' => $entryId]);
    });
}

public function payCommission($commissionId, \App\Services\AccountingService $accounting)
{
    $companyId = $this->companyId();

    return DB::transaction(function () use ($commissionId, $companyId, $accounting) {

        $commission = DB::table('worker_commissions')
            ->where('company_id', $companyId)
            ->where('id', $commissionId)
            ->first();

        if (!$commission) {
            return response()->json(['status' => false, 'message' => 'العمولة غير موجودة'], 404);
        }

        if ($commission->status !== 'APPROVED') {
            return response()->json(['status' => false, 'message' => 'لا يمكن دفع عمولة غير معتمدة'], 400);
        }

        $lastId = DB::table('vouchers')->where('company_id', $companyId)->max('id') ?? 0;
        $voucherNumber = 'PAY-' . date('Y') . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

        $voucherId = DB::table('vouchers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $commission->branch_id,
            'voucher_type_id' => 2,
            'voucher_number' => $voucherNumber,
            'voucher_date' => date('Y-m-d'),
            'reference_type' => 'WORKER_COMMISSION',
            'reference_id' => $commission->id,
            'amount' => $commission->amount,
            'payment_method' => 'CASH',
            'notes' => 'سند صرف عمولة عامل رقم ' . $commission->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payableAccount = $accounting->settingAccount($companyId, 'WORKER_PAYABLE_ACCOUNT');
        $cashAccount = $accounting->settingAccount($companyId, 'CASH_ACCOUNT');

        $paymentEntryId = $accounting->post([
            'company_id' => $companyId,
            'branch_id' => $commission->branch_id,
            'entry_date' => date('Y-m-d'),
            'source_type' => 'WORKER_COMMISSION_PAYMENT',
            'source_id' => $commission->id,
            'description' => 'دفع عمولة عامل رقم ' . $commission->id,
            'lines' => [
                ['account_id' => $payableAccount, 'debit' => $commission->amount, 'credit' => 0, 'description' => 'تسوية مستحق عمولة عامل'],
                ['account_id' => $cashAccount, 'debit' => 0, 'credit' => $commission->amount, 'description' => 'صرف نقدي لعمولة عامل'],
            ],
        ]);

        DB::table('worker_commissions')
            ->where('id', $commissionId)
            ->update([
                'status' => 'PAID',
                'voucher_id' => $voucherId,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => 'تم دفع العمولة',
            'voucher_id' => $voucherId,
            'journal_entry_id' => $paymentEntryId
        ]);
    });
}
}