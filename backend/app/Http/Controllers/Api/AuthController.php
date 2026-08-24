<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionContextService;
use App\Services\Support\SupportSessionService;
use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use LogsActivity;

    public function login(
        Request $request,
        SessionContextService $sessions,
        SubscriptionAccessModeResolver $accessModes,
    ) {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'username.required' => 'اسم المستخدم مطلوب.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ]);

        $user = User::query()
            ->where('username', trim((string) $validated['username']))
            ->first();

        if (!$user || !$this->passwordIsValid($user, (string) $validated['password'])) {
            return response()->json([
                'status' => false,
                'code' => 'LOGIN_FAILED',
                'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
            ], 401);
        }

        if ((int) $user->is_active !== 1) {
            return response()->json([
                'status' => false,
                'code' => 'USER_INACTIVE',
                'message' => 'تم تعطيل هذا المستخدم. راجع مدير النظام.',
            ], 403);
        }

        $role = $sessions->roleForUser((int) $user->id);

        if (!$role) {
            return response()->json([
                'status' => false,
                'code' => 'ROLE_MISSING',
                'message' => 'لا يوجد دور فعال مرتبط بهذا المستخدم.',
            ], 403);
        }

        $roleCode = strtoupper(trim((string) $role->role_code));
        $subscription = null;

        if ($roleCode !== 'SUPER_ADMIN') {
            if (!$user->company_id) {
                return response()->json([
                    'status' => false,
                    'code' => 'COMPANY_MISSING',
                    'message' => 'المستخدم غير مرتبط بشركة.',
                ], 403);
            }

            $company = DB::table('companies')
                ->where('id', $user->company_id)
                ->first();

            if (!$company || (int) $company->is_active !== 1) {
                return response()->json([
                    'status' => false,
                    'code' => 'COMPANY_INACTIVE',
                    'message' => 'الشركة غير مفعلة.',
                ], 403);
            }

            if ($user->branch_id && !DB::table('branches')
                ->where('id', $user->branch_id)
                ->where('company_id', $user->company_id)
                ->where('is_active', 1)
                ->exists()) {
                return response()->json([
                    'status' => false,
                    'code' => 'BRANCH_INACTIVE',
                    'message' => 'فرع المستخدم غير فعال أو لا يتبع الشركة.',
                ], 403);
            }

            $subscription = $sessions->effectiveSubscription((int) $user->company_id);

            if (!$subscription) {
                return response()->json([
                    'status' => false,
                    'code' => 'SUBSCRIPTION_MISSING',
                    'message' => 'لا يوجد اشتراك فعال لهذه الشركة.',
                ], 403);
            }

            $accessMode = $accessModes->resolve($subscription);
            if ($accessMode === SubscriptionAccessModeResolver::BLOCKED) {
                return response()->json([
                    'status' => false,
                    'code' => 'SUBSCRIPTION_BLOCKED',
                    'message' => 'الاشتراك الحالي لا يسمح بالدخول إلى بوابة الشركة.',
                ], 403);
            }
        }

        $isRemembered = (bool) ($validated['remember'] ?? false);
        $expiresAt = $isRemembered
            ? now()->addDays(30)
            : now()->addHours(12);

        $tokenName = $roleCode === 'SUPER_ADMIN'
            ? 'platform-session'
            : 'company-session';

        $abilities = $roleCode === 'SUPER_ADMIN'
            ? ['session', 'platform-admin']
            : ['session'];

        $plainTextToken = $user
            ->createToken($tokenName, $abilities, $expiresAt)
            ->plainTextToken;

        $payload = $sessions->userPayload($user);

        $this->logLogin(
            (int) $user->id,
            $roleCode === 'SUPER_ADMIN'
                ? 'تسجيل دخول مدير منصة صلب: ' . $user->username
                : 'تسجيل دخول المستخدم: ' . $user->username
        );

        return response()->json([
            'status' => true,
            'message' => 'تم تسجيل الدخول بنجاح.',
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'user' => $payload,
            'subscription' => $sessions->subscriptionPayload($subscription),
        ]);
    }

    public function me(
        Request $request,
        SessionContextService $sessions
    ) {
        /** @var User $user */
        $user = $request->user();
        $isSupportMode = (bool) $request->attributes->get('is_support_mode', false);
        $companyId = $request->attributes->get('tenant_company_id');
        $branchId = $request->attributes->get('tenant_branch_id');

        $payload = $isSupportMode
            ? $sessions->supportPayload($user,$request->attributes->get('support_session'))
            : $sessions->userPayload($user);

        $subscription = $companyId
            ? $sessions->effectiveSubscription((int) $companyId)
            : null;

        return response()->json([
            'status' => true,
            'user' => $payload,
            'subscription' => $sessions->subscriptionPayload($subscription),
        ]);
    }

    public function logout(Request $request,SupportSessionService $supportSessions)
    {
        if((bool)$request->attributes->get('is_support_mode',false)){
            $supportSessions->exit($request,$request->attributes->get('support_session'));
            return response()->json(['status'=>true,'message'=>'تم إنهاء جلسة الدعم الفني.']);
        }
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'status' => true,
            'message' => 'تم تسجيل الخروج بنجاح.',
        ]);
    }

    public function exitSupport(Request $request,SupportSessionService $supportSessions)
    {
        if (!(bool) $request->attributes->get('is_support_mode', false)) {
            return response()->json([
                'status' => false,
                'code' => 'NOT_SUPPORT_SESSION',
                'message' => 'الجلسة الحالية ليست جلسة دعم فني.',
            ], 422);
        }

        $supportSessions->exit($request,$request->attributes->get('support_session'));

        return response()->json([
            'status' => true,
            'message' => 'تم إنهاء جلسة الدعم الفني.',
        ]);
    }

    public function updatePassword(Request $request)
    {
        if ((bool) $request->attributes->get('is_support_mode', false)) {
            return response()->json([
                'status' => false,
                'code' => 'SUPPORT_PASSWORD_CHANGE_BLOCKED',
                'message' => 'اخرج من وضع الدعم الفني قبل تغيير كلمة مرور مدير المنصة.',
            ], 422);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:100',
                'confirmed',
                'different:current_password',
            ],
        ], [
            'current_password.required' => 'أدخل كلمة المرور الحالية.',
            'password.required' => 'أدخل كلمة المرور الجديدة.',
            'password.min' => 'كلمة المرور الجديدة يجب ألا تقل عن 8 خانات.',
            'password.max' => 'كلمة المرور الجديدة طويلة جدًا.',
            'password.confirmed' => 'تأكيد كلمة المرور الجديدة غير مطابق.',
            'password.different' => 'كلمة المرور الجديدة يجب أن تختلف عن الحالية.',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!$user || !$this->passwordIsValid($user, (string) $validated['current_password'])) {
            return response()->json([
                'status' => false,
                'code' => 'CURRENT_PASSWORD_INVALID',
                'message' => 'كلمة المرور الحالية غير صحيحة.',
            ], 422);
        }

        DB::transaction(function () use ($user, $validated) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'password' => Hash::make((string) $validated['password']),
                    'updated_at' => now(),
                ]);

            $currentTokenId = $user->currentAccessToken()?->id;

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->when(
                    $currentTokenId,
                    fn ($query) => $query->where('id', '<>', $currentTokenId)
                )
                ->delete();
        });

        return response()->json([
            'status' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح وإلغاء الجلسات الأخرى.',
        ]);
    }

    private function passwordIsValid(User $user, string $plainPassword): bool
    {
        $storedPassword = (string) $user->password;
        $isLegacyMd5 = preg_match('/^[a-f0-9]{32}$/i', $storedPassword) === 1;

        if ($isLegacyMd5) {
            $valid = hash_equals(
                strtolower($storedPassword),
                md5($plainPassword)
            );

            if ($valid) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'password' => Hash::make($plainPassword),
                        'updated_at' => now(),
                    ]);
            }

            return $valid;
        }

        return Hash::check($plainPassword, $storedPassword);
    }
}
