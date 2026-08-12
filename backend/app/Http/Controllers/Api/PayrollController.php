<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payroll\PayrollApprover;
use App\Services\Payroll\PayrollPayment;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payroll,
        private PayrollApprover $approver,
        private PayrollPayment $payment
    ) {
    }

    private function companyId(): int
    {
        return (int) request()->header('X-Company-ID');
    }

    private function branchId(): ?int
    {
        $branchId = request()->header('X-Branch-ID');

        return $branchId ? (int) $branchId : null;
    }

    private function companyRequired()
    {
        if (!$this->companyId()) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية.',
            ], 400);
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        $query = DB::table('worker_salary_runs as sr')
            ->leftJoin('branches as b', 'b.id', '=', 'sr.branch_id')
            ->where('sr.company_id', $this->companyId())
            ->when($this->branchId(), fn ($q) => $q->where('sr.branch_id', $this->branchId()))
            ->select(
                'sr.*',
                'b.branch_name',
                DB::raw('(
                    SELECT COUNT(*)
                    FROM worker_salary_lines sl
                    WHERE sl.salary_run_id = sr.id
                      AND sl.company_id = sr.company_id
                ) AS workers_count')
            );

        if ($request->filled('status')) {
            $query->where('sr.status', $request->status);
        }

        if ($request->filled('salary_month')) {
            $month = date('Y-m-01', strtotime($request->salary_month));
            $query->whereDate('sr.salary_month', $month);
        }

        if ($request->filled('branch_id')) {
            $query->where('sr.branch_id', (int) $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('sr.run_number', 'like', "%{$search}%")
                    ->orWhere('sr.status', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => true,
            'data' => $query
                ->orderByDesc('sr.salary_month')
                ->orderByDesc('sr.id')
                ->paginate(20),
        ]);
    }

    public function generate(Request $request)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        $validated = $request->validate([
            'salary_month' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ], [
            'salary_month.required' => 'شهر الرواتب مطلوب.',
            'salary_month.date' => 'شهر الرواتب غير صحيح.',
            'branch_id.integer' => 'الفرع المحدد غير صحيح.',
        ]);

        try {
            $data = $this->payroll->generate([
                'company_id' => $this->companyId(),
                'branch_id' => $validated['branch_id'] ?? $this->branchId(),
                'salary_month' => $validated['salary_month'],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء مسير الرواتب بنجاح.',
                'data' => $data,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(int $id)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        $run = DB::table('worker_salary_runs as sr')
            ->leftJoin('branches as b', 'b.id', '=', 'sr.branch_id')
            ->where('sr.company_id', $this->companyId())
            ->when($this->branchId(), fn ($q) => $q->where('sr.branch_id', $this->branchId()))
            ->where('sr.id', $id)
            ->select('sr.*', 'b.branch_name')
            ->first();

        if (!$run) {
            return response()->json([
                'status' => false,
                'message' => 'مسير الرواتب غير موجود.',
            ], 404);
        }

        $lines = DB::table('worker_salary_lines as sl')
            ->join('workers as w', function ($join) {
                $join->on('w.id', '=', 'sl.worker_id')
                    ->on('w.company_id', '=', 'sl.company_id');
            })
            ->where('sl.company_id', $this->companyId())
            ->where('sl.salary_run_id', $id)
            ->select(
                'sl.*',
                'w.worker_name',
                'w.employee_no',
                'w.job_title',
                'w.department'
            )
            ->orderBy('w.worker_name')
            ->get();

        return response()->json([
            'status' => true,
            'run' => $run,
            'lines' => $lines,
        ]);
    }

    public function approve(int $id)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        try {
            $data = $this->approver->approve($id);

            return response()->json([
                'status' => true,
                'message' => 'تم اعتماد مسير الرواتب وإنشاء القيد المحاسبي.',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function pay(Request $request, int $id)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        $validated = $request->validate([
            'payment_method' => ['required', 'in:CASH,BANK'],
        ], [
            'payment_method.required' => 'طريقة الدفع مطلوبة.',
            'payment_method.in' => 'طريقة الدفع يجب أن تكون نقدًا أو بنكًا.',
        ]);

        try {
            $data = $this->payment->pay(
                $id,
                $validated['payment_method']
            );

            return response()->json([
                'status' => true,
                'message' => 'تم صرف الرواتب وإنشاء قيد الصرف.',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function salarySlip(int $runId, int $workerId)
    {
        if ($error = $this->companyRequired()) {
            return $error;
        }

        $salary = DB::table('worker_salary_lines as sl')
            ->join('worker_salary_runs as sr', function ($join) {
                $join->on('sr.id', '=', 'sl.salary_run_id')
                    ->on('sr.company_id', '=', 'sl.company_id');
            })
            ->join('workers as w', function ($join) {
                $join->on('w.id', '=', 'sl.worker_id')
                    ->on('w.company_id', '=', 'sl.company_id');
            })
            ->leftJoin('branches as b', 'b.id', '=', 'sr.branch_id')
            ->where('sl.company_id', $this->companyId())
            ->when($this->branchId(), fn ($q) => $q->where('sr.branch_id', $this->branchId()))
            ->where('sl.salary_run_id', $runId)
            ->where('sl.worker_id', $workerId)
            ->select(
                'sl.*',
                'sr.run_number',
                'sr.salary_month',
                'sr.status as run_status',
                'sr.journal_entry_id as run_journal_entry_id',
                'sr.approved_at',
                'sr.paid_at as run_paid_at',
                'w.worker_name',
                'w.employee_no',
                'w.job_title',
                'w.department',
                'w.national_id',
                'w.iqama_number',
                'w.phone',
                'w.bank_name',
                'w.iban',
                'b.branch_name'
            )
            ->first();

        if (!$salary) {
            return response()->json([
                'status' => false,
                'message' => 'كشف الراتب غير موجود لهذا الموظف في المسير المحدد.',
            ], 404);
        }

        $company = DB::table('companies')
            ->where('id', $this->companyId())
            ->first();

        return response()->json([
            'status' => true,
            'data' => [
                'company' => $company,
                'salary' => $salary,
                'summary' => [
                    'total_additions' => round(
                        (float) $salary->overtime_amount
                        + (float) $salary->allowance_amount
                        + (float) $salary->bonus_amount
                        + (float) $salary->commission_amount,
                        3
                    ),
                    'total_deductions' => round(
                        (float) $salary->loan_deduction
                        + (float) $salary->other_deduction,
                        3
                    ),
                    'net_salary' => round(
                        (float) $salary->net_salary,
                        3
                    ),
                ],
            ],
        ]);
    }
}