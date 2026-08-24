<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use App\Services\Subscription\SubscriptionLifecycleService;

class SubscriptionPaymentController extends Controller
{
    /**
     * ملخص الفواتير والمدفوعات.
     */
    public function dashboard()
    {
        $summary = [
            'companies' => DB::table('companies')->count(),

            'subscriptions' => DB::table('subscriptions')->count(),

            'invoices' => DB::table('subscription_invoices')->count(),

            'payments' => DB::table('subscription_payments')->count(),

            'paid_amount' => DB::table('subscription_payments')
                ->where('payment_status', 'CONFIRMED')
                ->sum('amount'),

            'unpaid_amount' => DB::table('subscription_invoices')
                ->whereIn('status', ['UNPAID', 'PARTIAL'])
                ->sum('remaining_amount'),

            'paid_invoices' => DB::table('subscription_invoices')
                ->where('status', 'PAID')
                ->count(),

            'unpaid_invoices' => DB::table('subscription_invoices')
                ->where('status', 'UNPAID')
                ->count(),

            'partial_invoices' => DB::table('subscription_invoices')
                ->where('status', 'PARTIAL')
                ->count(),

            'cancelled_invoices' => DB::table('subscription_invoices')
                ->where('status', 'CANCELLED')
                ->count(),
        ];

        return response()->json([
            'status' => true,
            'data' => $summary,
        ]);
    }

    /**
     * عرض فواتير الاشتراكات.
     */
    public function invoices(Request $request)
    {
        $query = DB::table('subscription_invoices as i')
            ->leftJoin('companies as c', 'c.id', '=', 'i.company_id')
            ->leftJoin('plans as p', 'p.id', '=', 'i.plan_id')
            ->leftJoin('subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->select(
                'i.*',
                'c.company_name',
                'p.plan_name'
            );

        if ($request->filled('company_id')) {
            $query->where('i.company_id', $request->integer('company_id'));
        }

        if ($request->filled('status')) {
            $query->where(
                'i.status',
                strtoupper(trim((string) $request->status))
            );
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('i.invoice_number', 'like', "%{$search}%")
                    ->orWhere('c.company_name', 'like', "%{$search}%")
                    ->orWhere('p.plan_name', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('i.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $rows,
        ]);
    }

    /**
     * إنشاء فاتورة اشتراك.
     */
    public function storeInvoice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => [
                'required',
                'integer',
                'exists:companies,id',
            ],

            'subscription_id' => [
                'nullable',
                'integer',
                'exists:subscriptions,id',
            ],

            'plan_id' => [
                'required',
                'integer',
                'exists:plans,id',
            ],

            'invoice_date' => [
                'required',
                'date',
            ],

            'due_date' => [
                'nullable',
                'date',
                'after_or_equal:invoice_date',
            ],

            'subtotal' => [
                'required',
                'numeric',
                'min:0',
            ],

            'discount_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'tax_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'currency_code' => [
                'nullable',
                'string',
                'max:10',
            ],

            'billing_period' => [
                'required',
                Rule::in([
                    'MONTHLY',
                    'QUARTERLY',
                    'SEMI_ANNUAL',
                    'YEARLY',
                    'CUSTOM',
                ]),
            ],

            'period_start' => [
                'nullable',
                'date',
            ],

            'period_end' => [
                'nullable',
                'date',
                'after_or_equal:period_start',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'created_by' => [
                'nullable',
                'integer',
            ],
        ], [
            'company_id.required' => 'الشركة مطلوبة.',
            'company_id.exists' => 'الشركة المحددة غير موجودة.',
            'plan_id.required' => 'الباقة مطلوبة.',
            'plan_id.exists' => 'الباقة المحددة غير موجودة.',
            'invoice_date.required' => 'تاريخ الفاتورة مطلوب.',
            'subtotal.required' => 'قيمة الفاتورة قبل الضريبة مطلوبة.',
            'billing_period.required' => 'دورة الفوترة مطلوبة.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'بيانات الفاتورة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $invoice = DB::transaction(function () use ($request) {
                $subtotal = round((float) $request->subtotal, 3);

                $discountAmount = round(
                    (float) ($request->discount_amount ?? 0),
                    3
                );

                if ($discountAmount > $subtotal) {
                    throw new \RuntimeException(
                        'قيمة الخصم لا يمكن أن تكون أكبر من قيمة الفاتورة.'
                    );
                }

                $taxRate = round(
                    (float) ($request->tax_rate ?? 0),
                    3
                );

                $taxableAmount = max(
                    $subtotal - $discountAmount,
                    0
                );

                $taxAmount = round(
                    $taxableAmount * ($taxRate / 100),
                    3
                );

                $totalAmount = round(
                    $taxableAmount + $taxAmount,
                    3
                );

                $invoiceId = DB::table('subscription_invoices')->insertGetId([
                    'company_id' => $request->integer('company_id'),

                    'subscription_id' => $request->filled('subscription_id')
                        ? $request->integer('subscription_id')
                        : null,

                    'plan_id' => $request->integer('plan_id'),

                    'invoice_number' => $this->generateInvoiceNumber(),

                    'invoice_date' => $request->invoice_date,

                    'due_date' => $request->due_date,

                    'subtotal' => $subtotal,

                    'discount_amount' => $discountAmount,

                    'tax_rate' => $taxRate,

                    'tax_amount' => $taxAmount,

                    'total_amount' => $totalAmount,

                    'paid_amount' => 0,

                    'remaining_amount' => $totalAmount,

                    'currency_code' => strtoupper(
                        trim((string) ($request->currency_code ?? 'SAR'))
                    ),

                    'status' => $totalAmount <= 0
                        ? 'PAID'
                        : 'UNPAID',

                    'billing_period' => strtoupper(
                        trim((string) $request->billing_period)
                    ),

                    'period_start' => $request->period_start,

                    'period_end' => $request->period_end,

                    'notes' => $request->notes,

                    'created_by' => $request->created_by,

                    'paid_at' => $totalAmount <= 0
                        ? now()
                        : null,

                    'cancelled_at' => null,

                    'created_at' => now(),

                    'updated_at' => now(),
                ]);

                return DB::table('subscription_invoices')
                    ->where('id', $invoiceId)
                    ->first();
            });

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء فاتورة الاشتراك بنجاح.',
                'data' => $invoice,
            ], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * عرض المدفوعات.
     */
    public function payments(Request $request)
    {
        $query = DB::table('subscription_payments as p')
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->leftJoin(
                'subscription_invoices as i',
                'i.id',
                '=',
                'p.invoice_id'
            )
            ->select(
                'p.*',
                'c.company_name',
                'i.invoice_number'
            );

        if ($request->filled('company_id')) {
            $query->where('p.company_id', $request->integer('company_id'));
        }

        if ($request->filled('invoice_id')) {
            $query->where('p.invoice_id', $request->integer('invoice_id'));
        }

        if ($request->filled('payment_status')) {
            $query->where(
                'p.payment_status',
                strtoupper(trim((string) $request->payment_status))
            );
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('p.payment_number', 'like', "%{$search}%")
                    ->orWhere('p.reference_number', 'like', "%{$search}%")
                    ->orWhere('c.company_name', 'like', "%{$search}%")
                    ->orWhere('i.invoice_number', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('p.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $rows,
        ]);
    }

    /**
     * تسجيل دفعة جديدة وتحديث الفاتورة تلقائيًا.
     */
    public function storePayment(Request $request, SubscriptionLifecycleService $lifecycle)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => [
                'required',
                'integer',
                'exists:subscription_invoices,id',
            ],

            'payment_date' => [
                'required',
                'date',
            ],

            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'currency_code' => [
                'nullable',
                'string',
                'max:10',
            ],

            'payment_method' => [
                'required',
                Rule::in([
                    'CASH',
                    'BANK_TRANSFER',
                    'CARD',
                    'ONLINE',
                    'CHEQUE',
                ]),
            ],

            'reference_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'gateway_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'gateway_transaction_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'bank_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'account_number' => [
                'nullable',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'received_by' => [
                'nullable',
                'integer',
            ],
        ], [
            'invoice_id.required' => 'الفاتورة مطلوبة.',
            'invoice_id.exists' => 'الفاتورة المحددة غير موجودة.',
            'payment_date.required' => 'تاريخ الدفعة مطلوب.',
            'amount.required' => 'مبلغ الدفعة مطلوب.',
            'amount.gt' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر.',
            'payment_method.required' => 'طريقة الدفع مطلوبة.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'بيانات الدفعة غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $lifecycle) {
                $invoice = DB::table('subscription_invoices')
                    ->where('id', $request->integer('invoice_id'))
                    ->lockForUpdate()
                    ->first();

                if (!$invoice) {
                    throw new \RuntimeException(
                        'الفاتورة المحددة غير موجودة.'
                    );
                }

                if ($invoice->status === 'CANCELLED') {
                    throw new \RuntimeException(
                        'لا يمكن تسجيل دفعة على فاتورة ملغاة.'
                    );
                }

                if ($invoice->status === 'PAID') {
                    throw new \RuntimeException(
                        'الفاتورة مدفوعة بالكامل مسبقًا.'
                    );
                }

                $amount = round((float) $request->amount, 3);

                $remainingBeforePayment = round(
                    (float) $invoice->remaining_amount,
                    3
                );

                if ($amount > $remainingBeforePayment) {
                    throw new \RuntimeException(
                        'مبلغ الدفعة أكبر من المبلغ المتبقي على الفاتورة.'
                    );
                }

                $paymentId = DB::table('subscription_payments')->insertGetId([
                    'company_id' => $invoice->company_id,

                    'subscription_id' => $invoice->subscription_id,

                    'invoice_id' => $invoice->id,

                    'payment_number' => $this->generatePaymentNumber(),

                    'payment_date' => $request->payment_date,

                    'amount' => $amount,

                    'currency_code' => strtoupper(
                        trim(
                            (string) (
                                $request->currency_code
                                ?? $invoice->currency_code
                                ?? 'SAR'
                            )
                        )
                    ),

                    'payment_method' => strtoupper(
                        trim((string) $request->payment_method)
                    ),

                    'payment_status' => 'CONFIRMED',

                    'reference_number' => $request->reference_number,

                    'gateway_name' => $request->gateway_name,

                    'gateway_transaction_id' =>
                        $request->gateway_transaction_id,

                    'bank_name' => $request->bank_name,

                    'account_number' => $request->account_number,

                    'notes' => $request->notes,

                    'received_by' => $request->received_by,

                    'confirmed_at' => now(),

                    'refunded_at' => null,

                    'created_at' => now(),

                    'updated_at' => now(),
                ]);

                $newPaidAmount = round(
                    (float) $invoice->paid_amount + $amount,
                    3
                );

                $newRemainingAmount = round(
                    max(
                        (float) $invoice->total_amount - $newPaidAmount,
                        0
                    ),
                    3
                );

                $newStatus = $newRemainingAmount <= 0
                    ? 'PAID'
                    : 'PARTIAL';

                DB::table('subscription_invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'paid_amount' => $newPaidAmount,

                        'remaining_amount' => $newRemainingAmount,

                        'status' => $newStatus,

                        'paid_at' => $newStatus === 'PAID'
                            ? now()
                            : null,

                        'updated_at' => now(),
                    ]);

                if ($newStatus === 'PAID') {
                    if ($invoice->subscription_id) {
                        $lifecycle->transition((int) $invoice->subscription_id, 'ACTIVE');
                    }

                }

                return [
                    'payment' => DB::table('subscription_payments')
                        ->where('id', $paymentId)
                        ->first(),

                    'invoice' => DB::table('subscription_invoices')
                        ->where('id', $invoice->id)
                        ->first(),
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'تم تسجيل الدفعة وتحديث الفاتورة بنجاح.',
                'data' => $result,
            ], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * إلغاء فاتورة غير مدفوعة.
     */
    public function cancelInvoice($id)
    {
        try {
            $invoice = DB::transaction(function () use ($id) {
                $row = DB::table('subscription_invoices')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    throw new \RuntimeException('الفاتورة غير موجودة.');
                }

                if ($row->status === 'PAID') {
                    throw new \RuntimeException(
                        'لا يمكن إلغاء فاتورة مدفوعة بالكامل.'
                    );
                }

                if ((float) $row->paid_amount > 0) {
                    throw new \RuntimeException(
                        'لا يمكن إلغاء فاتورة تحتوي على دفعات.'
                    );
                }

                DB::table('subscription_invoices')
                    ->where('id', $id)
                    ->update([
                        'status' => 'CANCELLED',
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);

                return DB::table('subscription_invoices')
                    ->where('id', $id)
                    ->first();
            });

            return response()->json([
                'status' => true,
                'message' => 'تم إلغاء الفاتورة بنجاح.',
                'data' => $invoice,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * إنشاء رقم فاتورة فريد.
     */
    private function generateInvoiceNumber(): string
    {
        do {
            $number =
                'SUB-INV-' .
                now()->format('YmdHis') .
                '-' .
                random_int(1000, 9999);

            $exists = DB::table('subscription_invoices')
                ->where('invoice_number', $number)
                ->exists();
        } while ($exists);

        return $number;
    }

    /**
     * إنشاء رقم دفعة فريد.
     */
    private function generatePaymentNumber(): string
    {
        do {
            $number =
                'SUB-PAY-' .
                now()->format('YmdHis') .
                '-' .
                random_int(1000, 9999);

            $exists = DB::table('subscription_payments')
                ->where('payment_number', $number)
                ->exists();
        } while ($exists);

        return $number;
    }
}
