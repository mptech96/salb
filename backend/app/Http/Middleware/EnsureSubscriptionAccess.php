<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSubscriptionAccess
{
    public function __construct(
        private SubscriptionLifecycleService $lifecycle,
        private SubscriptionAccessModeResolver $accessModes,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $request->attributes->get('is_support_mode', false)
            && $request->attributes->get('support_access_level') === 'READ_ONLY') {
            // Narrow diagnostic exception: read-only support may inspect suspended/expired tenants.
            $request->attributes->set('subscription_access_mode', SubscriptionAccessModeResolver::RESTRICTED_READ_ONLY);
            return $next($request);
        }

        $companyId = (int) $request->attributes->get('tenant_company_id', 0);
        $subscription = $companyId > 0 ? $this->lifecycle->effectiveForCompany($companyId) : null;
        $mode = $this->accessModes->resolve($subscription);

        $request->attributes->set('effective_subscription', $subscription);
        $request->attributes->set('subscription_access_mode', $mode);

        if ($mode === SubscriptionAccessModeResolver::FULL) {
            return $next($request);
        }

        if ($mode === SubscriptionAccessModeResolver::RESTRICTED_READ_ONLY) {
            if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return $next($request);
            }

            return response()->json([
                'status' => false,
                'code' => 'SUBSCRIPTION_READ_ONLY',
                'message' => 'الاشتراك الحالي يسمح بالعرض فقط. جدد الاشتراك أو تواصل مع إدارة المنصة لتنفيذ عمليات جديدة.',
                'access_mode' => $mode,
                'subscription_status' => $subscription->effective_status ?? $subscription->status ?? null,
            ], 403);
        }

        return response()->json([
            'status' => false,
            'code' => $subscription ? 'SUBSCRIPTION_BLOCKED' : 'SUBSCRIPTION_MISSING',
            'message' => 'لا يسمح الاشتراك الحالي بالدخول إلى العمليات العادية للشركة.',
            'access_mode' => $mode,
            'subscription_status' => $subscription->effective_status ?? $subscription->status ?? null,
        ], 403);
    }
}
