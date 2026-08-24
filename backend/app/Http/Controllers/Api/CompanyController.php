<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionContextService;
use App\Services\Provisioning\CompanyProvisioningService;
use App\Services\Support\SupportSessionService;
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
        CompanyProvisioningService $provisioning
    ) {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key', $request->input('idempotency_key'))]);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'company_name' => ['required', 'string', 'min:3', 'max:255'],
            'owner_name' => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{7,15}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'username' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:12', 'max:100'],
            'subscription_mode' => ['nullable', 'in:PAID,TRIAL'],
            'company_is_active' => ['nullable', 'boolean'],
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

        try {
            $result = $provisioning->provision([...$validated,
                'channel' => 'PLATFORM_ADMIN',
                'trial_allowed' => ($validated['subscription_mode'] ?? 'PAID') === 'TRIAL',
                'billing_period' => 'CUSTOM',
                'currency_code' => 'SAR',
                'company_is_active' => $validated['company_is_active'] ?? true,
            ]);

            if (!$result['idempotent_replay']) {
                $this->logCreate(
                    'Companies',
                    $result['company_id'],
                    'تم إنشاء شركة جديدة مع التأسيس المحاسبي: ' . $validated['company_name']
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الشركة والفرع الرئيسي والسنة المالية وشجرة الحسابات ومراكز التكلفة بنجاح.',
                'data' => $result,
            ], $result['idempotent_replay'] ? 200 : 201);
        } catch (Throwable $e) {
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
        SessionContextService $sessions,
        SupportSessionService $supportSessions
    ) {
        $validated=$request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'ticket_reference'=>['required','string','max:150'],
            'expires_at'=>['required','date','after:now'],
            'access_level'=>['nullable','in:READ_ONLY,WRITE'],
            'capabilities'=>['nullable','array'],
            'capabilities.*'=>['string','max:255'],
            'branch_id'=>['nullable','integer'],
        ]);
        /** @var User $platformAdmin */
        $platformAdmin = $request->user();
        $created=$supportSessions->create($request,$platformAdmin,[...$validated,'company_id'=>$id]);$session=$created['session'];
        $subscription = $sessions->latestSubscription($id);
        return response()->json([
            'status'=>true,'message'=>'تم فتح جلسة دعم مسجلة وآمنة.','token'=>$created['plain_text_token'],'token_type'=>'Bearer',
            'expires_at'=>$session->expires_at,'support_session_id'=>$session->support_session_id,
            'user'=>$sessions->supportPayload($platformAdmin,$session),
            'subscription' => $sessions->subscriptionPayload($subscription),
        ]);
    }

    public function revokeSupport(Request $request,string $supportSessionId,SupportSessionService $sessions)
    {
        $session=DB::table('support_sessions')->where('support_session_id',$supportSessionId)->first();
        if(!$session)return response()->json(['status'=>false,'message'=>'جلسة الدعم غير موجودة.'],404);
        $sessions->revoke($request,$session);return response()->json(['status'=>true,'message'=>'تم إلغاء جلسة الدعم.']);
    }
}
