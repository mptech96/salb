<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Entitlement\EntitlementSnapshotService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PublicRegistrationController extends Controller
{
    public function register(Request $request, EntitlementSnapshotService $entitlementSnapshots)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'username' => ['required', 'string', 'max:150', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_period' => ['required', Rule::in(['MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'YEARLY'])],
        ], [
            'company_name.required' => 'اسم الشركة مطلوب.',
            'owner_name.required' => 'اسم مدير الشركة مطلوب.',
            'phone.required' => 'رقم الجوال مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'username.required' => 'اسم المستخدم مطلوب.',
            'username.unique' => 'اسم المستخدم مستخدم مسبقًا.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 6 خانات.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'plan_id.required' => 'اختر الباقة.',
            'plan_id.exists' => 'الباقة المحددة غير موجودة.',
            'billing_period.required' => 'اختر دورة الفوترة.',
            'billing_period.in' => 'دورة الفوترة المحددة غير صحيحة.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'يرجى مراجعة بيانات التسجيل.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $entitlementSnapshots) {
                $plan = DB::table('plans')
                    ->where('id', $request->integer('plan_id'))
                    ->lockForUpdate()
                    ->first();

                if (!$plan) {
                    throw new \RuntimeException('الباقة المحددة غير موجودة.');
                }

                if (property_exists($plan, 'is_active') && (int) $plan->is_active !== 1) {
                    throw new \RuntimeException('الباقة المحددة غير متاحة حاليًا.');
                }

                $role = $this->findCompanyAdminRole();

                if (!$role) {
                    throw new \RuntimeException(
                        'لم يتم العثور على دور مدير الشركة. أنشئ دورًا بالكود COMPANY_ADMIN أو COMPANY_OWNER أو ADMIN أو MANAGER.'
                    );
                }

                $billingPeriod = strtoupper(trim((string) $request->billing_period));
                $period = $this->resolveBillingPeriod($plan, $billingPeriod);
                $periodStart = Carbon::today();
                $periodEnd = $periodStart->copy()->addMonthsNoOverflow($period['months'])->subDay();

                $companyId = DB::table('companies')->insertGetId([
                    'company_name' => trim((string) $request->company_name),
                    'owner_name' => trim((string) $request->owner_name),
                    'phone' => trim((string) $request->phone),
                    'email' => $request->filled('email') ? trim((string) $request->email) : null,
                    'city' => $request->filled('city') ? trim((string) $request->city) : null,
                    'address' => $request->filled('address') ? trim((string) $request->address) : null,
                    'is_active' => $period['subtotal'] <= 0 ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $branchId = DB::table('branches')->insertGetId([
                    'company_id' => $companyId,
                    'branch_name' => 'الفرع الرئيسي',
                    'branch_code' => 'MAIN-' . $companyId,
                    'city' => $request->filled('city') ? trim((string) $request->city) : null,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $userId = DB::table('users')->insertGetId([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'name' => trim((string) $request->owner_name),
                    'username' => trim((string) $request->username),
                    'email' => $request->filled('email') ? trim((string) $request->email) : null,
                    'phone' => trim((string) $request->phone),
                    'password' => Hash::make((string) $request->password),
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('user_roles')->insert([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'role_id' => $role->id,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $subscriptionId = DB::table('subscriptions')->insertGetId([
                    'company_id' => $companyId,
                    'plan_id' => $plan->id,
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'status' => $period['subtotal'] <= 0 ? 'ACTIVE' : 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $entitlementSnapshots->capture($subscriptionId);

                $taxRate = round((float) env('SUBSCRIPTION_TAX_RATE', 0), 3);
                $subtotal = round((float) $period['subtotal'], 3);
                $taxAmount = round($subtotal * ($taxRate / 100), 3);
                $totalAmount = round($subtotal + $taxAmount, 3);

                $invoiceId = DB::table('subscription_invoices')->insertGetId([
                    'company_id' => $companyId,
                    'subscription_id' => $subscriptionId,
                    'plan_id' => $plan->id,
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'invoice_date' => $periodStart->toDateString(),
                    'due_date' => $periodStart->copy()->addDays((int) env('SUBSCRIPTION_INVOICE_DUE_DAYS', 3))->toDateString(),
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                    'paid_amount' => 0,
                    'remaining_amount' => $totalAmount,
                    'currency_code' => strtoupper((string) env('SUBSCRIPTION_CURRENCY', 'SAR')),
                    'status' => $totalAmount <= 0 ? 'PAID' : 'UNPAID',
                    'billing_period' => $billingPeriod,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'notes' => 'فاتورة تسجيل شركة جديدة عبر بوابة التسجيل الذاتي.',
                    'created_by' => $userId,
                    'paid_at' => $totalAmount <= 0 ? now() : null,
                    'cancelled_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('company_settings')->insert([
                    'company_id' => $companyId,
                    'print_company_name' => trim((string) $request->company_name),
                    'print_phone' => trim((string) $request->phone),
                    'print_email' => $request->filled('email') ? trim((string) $request->email) : null,
                    'print_city' => $request->filled('city') ? trim((string) $request->city) : null,
                    'print_address' => $request->filled('address') ? trim((string) $request->address) : null,
                    'currency_name' => 'ريال',
                    'currency_code' => strtoupper((string) env('SUBSCRIPTION_CURRENCY', 'SAR')),
                    'primary_color' => '#0B2A4A',
                    'secondary_color' => '#123D68',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $invoice = DB::table('subscription_invoices')->where('id', $invoiceId)->first();

                return [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_status' => $invoice->status,
                    'subtotal' => (float) $invoice->subtotal,
                    'tax_rate' => (float) $invoice->tax_rate,
                    'tax_amount' => (float) $invoice->tax_amount,
                    'total_amount' => (float) $invoice->total_amount,
                    'remaining_amount' => (float) $invoice->remaining_amount,
                    'currency_code' => $invoice->currency_code,
                    'billing_period' => $invoice->billing_period,
                    'period_start' => $invoice->period_start,
                    'period_end' => $invoice->period_end,
                    'due_date' => $invoice->due_date,
                    'username' => trim((string) $request->username),
                    'company_active' => $totalAmount <= 0,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => $result['company_active']
                    ? 'تم إنشاء الحساب وتفعيله بنجاح.'
                    : 'تم إنشاء الحساب والفاتورة بنجاح. سيتم تفعيل الشركة بعد سداد الفاتورة.',
                'data' => $result,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function findCompanyAdminRole(): ?object
    {
        foreach (['COMPANY_ADMIN', 'COMPANY_OWNER', 'ADMIN', 'MANAGER'] as $roleCode) {
            $role = DB::table('roles')->where('role_code', $roleCode)->first();

            if ($role) {
                return $role;
            }
        }

        return null;
    }

    private function resolveBillingPeriod(object $plan, string $billingPeriod): array
    {
        $monthlyPrice = round((float) ($plan->monthly_price ?? 0), 3);
        $yearlyPrice = round((float) ($plan->yearly_price ?? ($monthlyPrice * 12)), 3);

        return match ($billingPeriod) {
            'MONTHLY' => ['months' => 1, 'subtotal' => $monthlyPrice],
            'QUARTERLY' => ['months' => 3, 'subtotal' => round($monthlyPrice * 3, 3)],
            'SEMI_ANNUAL' => ['months' => 6, 'subtotal' => round($monthlyPrice * 6, 3)],
            'YEARLY' => ['months' => 12, 'subtotal' => $yearlyPrice],
            default => throw new \RuntimeException('دورة الفوترة المحددة غير مدعومة.'),
        };
    }

    private function generateInvoiceNumber(): string
    {
        do {
            $number = 'SUB-INV-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
            $exists = DB::table('subscription_invoices')->where('invoice_number', $number)->exists();
        } while ($exists);

        return $number;
    }
}
