<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\AccountingBootstrapService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionContextService;
use App\Services\Entitlement\EntitlementSnapshotService;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompanyController extends Controller
{
    use LogsActivity;

    public function index()
    {
        $latestSubscriptions = DB::table('subscriptions')
            ->selectRaw('MAX(id) AS id, company_id')
            ->groupBy('company_id');

        $data = DB::table('companies as c')
            ->leftJoinSub($latestSubscriptions, 'latest_s', function ($join) {
                $join->on('latest_s.company_id', '=', 'c.id');
            })
            ->leftJoin('subscriptions as s', 's.id', '=', 'latest_s.id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->select(
                'c.*',
                'p.plan_name',
                'p.plan_code',
                's.start_date',
                's.end_date',
                's.status'
            )
            ->orderByDesc('c.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function store(
        Request $request,
        AccountingBootstrapService $bootstrap,
        EntitlementSnapshotService $entitlementSnapshots
    ) {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'min:3', 'max:255'],
            'owner_name' => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{7,15}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'company_name.required' => 'اسم الشركة مطلوب.',
            'company_name.min' => 'اسم الشركة يجب ألا يقل عن 3 أحرف.',
            'owner_name.required' => 'اسم المالك أو المدير مطلوب.',
            'owner_name.min' => 'اسم المالك يجب ألا يقل عن 3 أحرف.',
            'phone.required' => 'رقم الجوال مطلوب.',
            'phone.regex' => 'رقم الجوال يجب أن يحتوي على أرقام فقط من 7 إلى 15 رقمًا.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'plan_id.required' => 'اختر الباقة.',
            'plan_id.exists' => 'الباقة المحددة غير موجودة.',
            'start_date.required' => 'تاريخ بداية الاشتراك مطلوب.',
            'end_date.required' => 'تاريخ نهاية الاشتراك مطلوب.',
            'end_date.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد البداية أو مساويًا لها.',
        ]);

        DB::beginTransaction();

        try {
            $companyId = DB::table('companies')->insertGetId([
                'company_name' => trim((string) $validated['company_name']),
                'owner_name' => trim((string) $validated['owner_name']),
                'phone' => trim((string) $validated['phone']),
                'email' => !empty($validated['email'])
                    ? trim((string) $validated['email'])
                    : null,
                'city' => !empty($validated['city'])
                    ? trim((string) $validated['city'])
                    : null,
                'address' => !empty($validated['address'])
                    ? trim((string) $validated['address'])
                    : null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'company_id' => $companyId,
                'plan_id' => (int) $validated['plan_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $entitlementSnapshots->capture($subscriptionId);

            $branchId = DB::table('branches')->insertGetId([
                'company_id' => $companyId,
                'branch_name' => 'الفرع الرئيسي',
                'branch_code' => 'MAIN-' . $companyId,
                'phone' => $validated['phone'],
                'city' => $validated['city'] ?? null,
                'address' => $validated['address'] ?? null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $accounting = $bootstrap->bootstrapCompany(
                $companyId,
                $branchId
            );

            DB::commit();

            $this->logCreate(
                'Companies',
                $companyId,
                'تم إنشاء شركة جديدة مع التأسيس المحاسبي: ' .
                $validated['company_name']
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الشركة والفرع الرئيسي والسنة المالية وشجرة الحسابات ومراكز التكلفة بنجاح.',
                'data' => [
                    'company_id' => $companyId,
                    'main_branch_id' => $branchId,
                    'financial_year_id' => $accounting['financial_year_id'],
                    'company_cost_center_id' => $accounting['company_cost_center_id'],
                    'branch_cost_center_id' => $accounting['branch_cost_center_id'],
                ],
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر إنشاء الشركة والتأسيس المحاسبي. راجع البيانات وسجل النظام.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function supportAccess(
        Request $request,
        int $id,
        SessionContextService $sessions
    ) {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company = DB::table('companies')->where('id', $id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'الشركة غير موجودة.',
            ], 404);
        }

        $branch = DB::table('branches')
            ->where('company_id', $id)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        /** @var User $platformAdmin */
        $platformAdmin = $request->user();

        // نلغي جلسات الدعم القديمة لنفس مدير المنصة حتى لا تتراكم Tokens.
        $platformAdmin->tokens()
            ->where('name', 'like', 'support:%')
            ->delete();

        $expiresAt = now()->addHours(2);
        $abilities = [
            'session',
            'support-mode',
            'support-company:' . $id,
        ];

        if ($branch?->id) {
            $abilities[] = 'support-branch:' . $branch->id;
        }

        $token = $platformAdmin
            ->createToken(
                'support:' . $id,
                $abilities,
                $expiresAt
            )
            ->plainTextToken;

        $subscription = $sessions->latestSubscription($id);
        $payload = $sessions->supportPayload(
            $platformAdmin,
            $id,
            $branch?->id ? (int) $branch->id : null
        );

        $reason = trim((string) $request->input('reason', ''));

        $this->logSupportAccess(
            $company->id,
            'تم الدخول على الشركة كدعم فني: ' . $company->company_name .
            ($reason !== '' ? ' | السبب: ' . $reason : '')
        );

        return response()->json([
            'status' => true,
            'message' => 'تم فتح جلسة دعم فني آمنة لمدة ساعتين.',
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'user' => $payload,
            'subscription' => $sessions->subscriptionPayload($subscription),
        ]);
    }
}
