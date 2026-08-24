<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Entitlement\EntitlementSnapshotService;
use App\Traits\LogsActivity;
use App\Services\Subscription\SubscriptionLifecycleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    use LogsActivity;

    /**
     * عرض جميع اشتراكات الشركات مع آخر اشتراك لكل شركة.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $latestSubscriptionIds = DB::table('subscriptions')
                ->selectRaw('MAX(id) AS id')
                ->groupBy('company_id');

            $query = DB::table('subscriptions as s')
                ->join('companies as c', 'c.id', '=', 's.company_id')
                ->join('plans as p', 'p.id', '=', 's.plan_id')
                ->whereIn('s.id', $latestSubscriptionIds)
                ->select([
                    's.id',
                    's.company_id',
                    's.plan_id',
                    's.start_date',
                    's.end_date',
                    's.status',
                    's.notes',
                    's.created_at',
                    's.updated_at',

                    'c.company_name',
                    'c.owner_name',
                    'c.phone',
                    'c.email',
                    'c.city',
                    'c.is_active as company_active',

                    'p.plan_name',
                    'p.plan_code',
                    'p.monthly_price',
                    'p.yearly_price',
                    'p.max_branches',
                    'p.max_users',
                    'p.max_cars',
                    'p.max_invoices',
                ])
                ->selectRaw(
                    'DATEDIFF(s.end_date, CURDATE()) AS remaining_days'
                );

            if ($request->filled('status')) {
                $query->where(
                    's.status',
                    strtoupper(trim($request->status))
                );
            }

            if ($request->filled('company_id')) {
                $query->where(
                    's.company_id',
                    (int) $request->company_id
                );
            }

            if ($request->filled('plan_id')) {
                $query->where(
                    's.plan_id',
                    (int) $request->plan_id
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where(
                            'c.company_name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'c.owner_name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'c.phone',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'p.plan_name',
                            'like',
                            '%' . $search . '%'
                        );
                });
            }

            $subscriptions = $query
                ->orderByRaw("
                    CASE
                        WHEN s.status = 'TRIAL' THEN 1
                        WHEN s.status = 'ACTIVE' THEN 2
                        WHEN s.status = 'SUSPENDED' THEN 3
                        WHEN s.status = 'EXPIRED' THEN 4
                        WHEN s.status = 'CANCELLED' THEN 5
                        ELSE 6
                    END
                ")
                ->orderBy('s.end_date')
                ->get();

            $summary = [
                'total' => $subscriptions->count(),

                'active' => $subscriptions
                    ->where('status', 'ACTIVE')
                    ->where('remaining_days', '>=', 0)
                    ->count(),

                'trial' => $subscriptions
                    ->where('status', 'TRIAL')
                    ->where('remaining_days', '>=', 0)
                    ->count(),

                'expired' => $subscriptions
                    ->filter(function ($subscription) {
                        return
                            $subscription->status === 'EXPIRED' ||
                            (int) $subscription->remaining_days < 0;
                    })
                    ->count(),

                'suspended' => $subscriptions
                    ->where('status', 'SUSPENDED')
                    ->count(),

                'cancelled' => $subscriptions
                    ->where('status', 'CANCELLED')
                    ->count(),

                'expiring_soon' => $subscriptions
                    ->filter(function ($subscription) {
                        return
                            in_array(
                                $subscription->status,
                                ['ACTIVE', 'TRIAL'],
                                true
                            ) &&
                            (int) $subscription->remaining_days >= 0 &&
                            (int) $subscription->remaining_days <= 30;
                    })
                    ->count(),
            ];

            return response()->json([
                'status' => true,
                'data' => [
                    'summary' => $summary,
                    'subscriptions' => $subscriptions,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تحميل الاشتراكات',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * عرض اشتراك واحد.
     */
    public function show(int $id): JsonResponse
    {
        $subscription = DB::table('subscriptions as s')
            ->join('companies as c', 'c.id', '=', 's.company_id')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.id', $id)
            ->select([
                's.*',
                'c.company_name',
                'c.owner_name',
                'c.phone',
                'c.email',
                'c.city',
                'c.is_active as company_active',
                'p.plan_name',
                'p.plan_code',
                'p.monthly_price',
                'p.yearly_price',
                'p.max_branches',
                'p.max_users',
                'p.max_cars',
                'p.max_invoices',
            ])
            ->selectRaw(
                'DATEDIFF(s.end_date, CURDATE()) AS remaining_days'
            )
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => false,
                'message' => 'الاشتراك غير موجود',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $subscription,
        ]);
    }

    /**
     * تجديد الاشتراك.
     */
    public function renew(
        Request $request,
        int $id,
        EntitlementSnapshotService $entitlementSnapshots
    ): JsonResponse
    {
        $request->validate([
            'end_date' => 'required|date',
            'start_date' => 'nullable|date',
            'plan_id' => 'nullable|integer|exists:plans,id',
            'billing_period' => [
                'nullable',
                Rule::in([
                    'MONTHLY',
                    'QUARTERLY',
                    'SEMI_ANNUAL',
                    'YEARLY',
                    'CUSTOM',
                ]),
            ],
            'subtotal' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'currency_code' => 'nullable|string|max:10',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $subscription = DB::table('subscriptions')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'الاشتراك غير موجود',
                ], 404);
            }

            $planId = $request->filled('plan_id')
                ? (int) $request->plan_id
                : (int) $subscription->plan_id;

            $plan = DB::table('plans')
                ->where('id', $planId)
                ->lockForUpdate()
                ->first();

            if (!$plan) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'الباقة المحددة غير موجودة',
                ], 404);
            }

            $startDate = $request->filled('start_date')
                ? Carbon::parse($request->start_date)->toDateString()
                : Carbon::today()->toDateString();

            $endDate = Carbon::parse(
                $request->end_date
            )->toDateString();

            if ($endDate < $startDate) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'تاريخ نهاية الاشتراك يجب أن يكون بعد تاريخ البداية',
                ], 422);
            }

            $billingPeriod = strtoupper(
                trim((string) ($request->billing_period ?? 'YEARLY'))
            );

            $subtotal = $this->resolveRenewalSubtotal(
                $plan,
                $billingPeriod,
                $request->subtotal
            );

            $discountAmount = round(
                (float) ($request->discount_amount ?? 0),
                3
            );

            if ($discountAmount > $subtotal) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'قيمة الخصم لا يمكن أن تكون أكبر من قيمة الفاتورة',
                ], 422);
            }

            $taxRate = round(
                (float) ($request->tax_rate ?? 15),
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

            $newSubscriptionId = DB::table('subscriptions')
                ->insertGetId([
                    'company_id' => $subscription->company_id,
                    'plan_id' => $planId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'ACTIVE',
                    'notes' => $request->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $entitlementSnapshots->capture($newSubscriptionId);

            $invoiceDate = Carbon::today()->toDateString();

            $dueDate = $request->filled('due_date')
                ? Carbon::parse($request->due_date)->toDateString()
                : Carbon::today()->addDays(7)->toDateString();

            if ($dueDate < $invoiceDate) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'تاريخ استحقاق الفاتورة لا يمكن أن يكون قبل تاريخ الفاتورة',
                ], 422);
            }

            $invoiceId = DB::table('subscription_invoices')
                ->insertGetId([
                    'company_id' => $subscription->company_id,
                    'subscription_id' => $newSubscriptionId,
                    'plan_id' => $planId,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
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
                    'billing_period' => $billingPeriod,
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'notes' => $request->notes,
                    'created_by' => null,
                    'paid_at' => $totalAmount <= 0
                        ? now()
                        : null,
                    'cancelled_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            $this->logCreate(
                'Subscriptions',
                $newSubscriptionId,
                'تم تجديد اشتراك الشركة رقم: ' .
                    $subscription->company_id
            );

            $this->logCreate(
                'Subscription Invoices',
                $invoiceId,
                'تم إنشاء فاتورة تجديد للاشتراك رقم: ' .
                    $newSubscriptionId
            );

            return response()->json([
                'status' => true,
                'message' => 'تم تجديد الاشتراك وإنشاء الفاتورة بنجاح',
                'data' => [
                    'subscription_id' => $newSubscriptionId,
                    'invoice_id' => $invoiceId,
                    'billing_period' => $billingPeriod,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تجديد الاشتراك وإنشاء الفاتورة',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * تغيير الباقة.
     */
    public function changePlan(
        Request $request,
        int $id
    ): JsonResponse {
        $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $subscription = DB::table('subscriptions')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'الاشتراك غير موجود',
                ], 404);
            }

            DB::table('subscriptions')
                ->where('id', $id)
                ->update([
                    'plan_id' => $request->plan_id,
                    'notes' => $request->notes
                        ?? $subscription->notes,
                    'updated_at' => now(),
                ]);

            DB::commit();

            $this->logUpdate(
                'Subscriptions',
                $id,
                'تم تغيير باقة الاشتراك رقم: ' . $id
            );

            return response()->json([
                'status' => true,
                'message' => 'تم تغيير الباقة بنجاح',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تغيير الباقة',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * تحديث حالة الاشتراك.
     */
    public function updateStatus(
        Request $request,
        int $id,
        SubscriptionLifecycleService $lifecycle
    ): JsonResponse {
        $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'PENDING',
                    'ACTIVE',
                    'TRIAL',
                    'SUSPENDED',
                    'EXPIRED',
                    'CANCELLED',
                ]),
            ],
            'notes' => 'nullable|string',
        ]);

        try {
            $status = strtoupper($request->status);
            $subscription = $lifecycle->transition($id, $status, $request->notes);

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث حالة الاشتراك بنجاح',
                'data' => $subscription,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 422);
        }
    }

    /**
     * تمديد تاريخ نهاية الاشتراك الحالي.
     */
    public function extend(
        Request $request,
        int $id,
        SubscriptionLifecycleService $lifecycle
    ): JsonResponse {
        $request->validate([
            'days' => 'required|integer|min:1|max:3650',
            'notes' => 'nullable|string',
        ]);

        try {
            $subscription = $lifecycle->extend($id, (int) $request->days, $request->notes);

            return response()->json([
                'status' => true,
                'message' => 'تم تمديد الاشتراك بنجاح',
                'data' => [
                    'end_date' => $subscription->end_date,
                    'status' => $subscription->status,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تمديد الاشتراك',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 422);
        }
    }

    /**
     * حساب قيمة فاتورة التجديد حسب دورة الفوترة.
     */
    private function resolveRenewalSubtotal(
        object $plan,
        string $billingPeriod,
        mixed $customSubtotal = null
    ): float {
        $monthlyPrice = round((float) $plan->monthly_price, 3);

        return match ($billingPeriod) {
            'MONTHLY' => $monthlyPrice,
            'QUARTERLY' => round($monthlyPrice * 3, 3),
            'SEMI_ANNUAL' => round($monthlyPrice * 6, 3),
            'YEARLY' => $this->resolveYearlyPrice($plan),
            'CUSTOM' => $this->resolveCustomSubtotal($customSubtotal),
            default => throw new \RuntimeException(
                'دورة الفوترة المحددة غير صحيحة'
            ),
        };
    }

    /**
     * اعتماد السعر السنوي المحدد في الباقة.
     */
    private function resolveYearlyPrice(object $plan): float
    {
        if ($plan->yearly_price === null || $plan->yearly_price === '') {
            throw new \RuntimeException(
                'السعر السنوي غير محدد لهذه الباقة'
            );
        }

        return round((float) $plan->yearly_price, 3);
    }

    /**
     * اعتماد مبلغ التجديد المخصص.
     */
    private function resolveCustomSubtotal(mixed $customSubtotal): float
    {
        if (
            $customSubtotal === null ||
            $customSubtotal === '' ||
            !is_numeric($customSubtotal) ||
            (float) $customSubtotal < 0
        ) {
            throw new \RuntimeException(
                'أدخل مبلغًا صحيحًا لدورة الفوترة المخصصة'
            );
        }

        return round((float) $customSubtotal, 3);
    }

    /**
     * إنشاء رقم فاتورة اشتراك فريد.
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

}
