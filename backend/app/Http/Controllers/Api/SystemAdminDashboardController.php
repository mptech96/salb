<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SystemAdminDashboardController extends Controller
{
    /**
     * لوحة تحكم مدير المنصة.
     */
    public function index(): JsonResponse
    {
        try {
            $today = Carbon::today();
            $expiringDate = Carbon::today()->addDays(30);

            /*
            |--------------------------------------------------------------------------
            | أحدث اشتراك لكل شركة
            |--------------------------------------------------------------------------
            */

            $latestSubscriptionIds = DB::table('subscriptions')
                ->selectRaw('MAX(id) AS id')
                ->groupBy('company_id');

            /*
            |--------------------------------------------------------------------------
            | إحصائيات الشركات
            |--------------------------------------------------------------------------
            */

            $companies = [
                'total' => DB::table('companies')->count(),

                'active' => DB::table('companies')
                    ->where('is_active', 1)
                    ->count(),

                'inactive' => DB::table('companies')
                    ->where('is_active', 0)
                    ->count(),

                'new_this_month' => DB::table('companies')
                    ->whereYear('created_at', $today->year)
                    ->whereMonth('created_at', $today->month)
                    ->count(),
            ];

            /*
            |--------------------------------------------------------------------------
            | إحصائيات الاشتراكات
            |--------------------------------------------------------------------------
            */

            $subscriptionsQuery = DB::table('subscriptions')
                ->whereIn('id', $latestSubscriptionIds);

            $subscriptions = [
                'total' => (clone $subscriptionsQuery)->count(),

                'active' => (clone $subscriptionsQuery)
                    ->where('status', 'ACTIVE')
                    ->whereDate('end_date', '>=', $today)
                    ->count(),

                'expired' => (clone $subscriptionsQuery)
                    ->where(function ($query) use ($today) {
                        $query
                            ->where('status', 'EXPIRED')
                            ->orWhereDate('end_date', '<', $today);
                    })
                    ->count(),

                'trial' => (clone $subscriptionsQuery)
                    ->where('status', 'TRIAL')
                    ->whereDate('end_date', '>=', $today)
                    ->count(),

                'suspended' => (clone $subscriptionsQuery)
                    ->where('status', 'SUSPENDED')
                    ->count(),

                'cancelled' => (clone $subscriptionsQuery)
                    ->where('status', 'CANCELLED')
                    ->count(),

                'expiring_soon' => (clone $subscriptionsQuery)
                    ->whereIn('status', ['ACTIVE', 'TRIAL'])
                    ->whereBetween('end_date', [
                        $today->toDateString(),
                        $expiringDate->toDateString(),
                    ])
                    ->count(),
            ];

            /*
            |--------------------------------------------------------------------------
            | المستخدمون والفروع
            |--------------------------------------------------------------------------
            */

            $users = [
                'total' => DB::table('users')
                    ->whereNotNull('company_id')
                    ->count(),

                'active' => DB::table('users')
                    ->whereNotNull('company_id')
                    ->where('is_active', 1)
                    ->count(),

                'inactive' => DB::table('users')
                    ->whereNotNull('company_id')
                    ->where('is_active', 0)
                    ->count(),
            ];

            $branches = [
                'total' => DB::table('branches')->count(),

                'active' => DB::table('branches')
                    ->where('is_active', 1)
                    ->count(),

                'inactive' => DB::table('branches')
                    ->where('is_active', 0)
                    ->count(),
            ];

            /*
            |--------------------------------------------------------------------------
            | آخر الشركات المضافة
            |--------------------------------------------------------------------------
            */

            $recentCompanies = DB::table('companies as c')
                ->leftJoinSub(
                    DB::table('subscriptions')
                        ->selectRaw('MAX(id) AS subscription_id, company_id')
                        ->groupBy('company_id'),
                    'latest_subscription',
                    'latest_subscription.company_id',
                    '=',
                    'c.id'
                )
                ->leftJoin(
                    'subscriptions as s',
                    's.id',
                    '=',
                    'latest_subscription.subscription_id'
                )
                ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
                ->select([
                    'c.id',
                    'c.company_name',
                    'c.owner_name',
                    'c.phone',
                    'c.city',
                    'c.is_active',
                    'c.created_at',
                    's.start_date',
                    's.end_date',
                    's.status as subscription_status',
                    'p.plan_name',
                    'p.plan_code',
                ])
                ->orderByDesc('c.id')
                ->limit(8)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | الاشتراكات القريبة من الانتهاء
            |--------------------------------------------------------------------------
            */

            $expiringSubscriptions = DB::table('subscriptions as s')
                ->joinSub(
                    DB::table('subscriptions')
                        ->selectRaw('MAX(id) AS subscription_id, company_id')
                        ->groupBy('company_id'),
                    'latest_subscription',
                    'latest_subscription.subscription_id',
                    '=',
                    's.id'
                )
                ->join('companies as c', 'c.id', '=', 's.company_id')
                ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
                ->whereIn('s.status', ['ACTIVE', 'TRIAL'])
                ->whereBetween('s.end_date', [
                    $today->toDateString(),
                    $expiringDate->toDateString(),
                ])
                ->select([
                    's.id',
                    's.company_id',
                    's.start_date',
                    's.end_date',
                    's.status',
                    'c.company_name',
                    'p.plan_name',
                ])
                ->selectRaw(
                    'DATEDIFF(s.end_date, ?) AS remaining_days',
                    [$today->toDateString()]
                )
                ->orderBy('s.end_date')
                ->limit(10)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | توزيع الشركات حسب الباقات
            |--------------------------------------------------------------------------
            */

            $plansDistribution = DB::table('plans as p')
                ->leftJoin('subscriptions as s', function ($join) use ($latestSubscriptionIds) {
                    $join
                        ->on('s.plan_id', '=', 'p.id')
                        ->whereIn('s.id', $latestSubscriptionIds);
                })
                ->select([
                    'p.id',
                    'p.plan_name',
                    'p.plan_code',
                    'p.monthly_price',
                    'p.is_active',
                ])
                ->selectRaw('COUNT(s.id) AS companies_count')
                ->groupBy(
                    'p.id',
                    'p.plan_name',
                    'p.plan_code',
                    'p.monthly_price',
                    'p.is_active'
                )
                ->orderByDesc('companies_count')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | آخر الأنشطة
            |--------------------------------------------------------------------------
            */

            $recentActivities = DB::table('audit_logs as a')
                ->leftJoin('companies as c', 'c.id', '=', 'a.company_id')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->select([
                    'a.id',
                    'a.company_id',
                    'a.branch_id',
                    'a.user_id',
                    'a.action_type',
                    'a.module_name',
                    'a.record_id',
                    'a.description',
                    'a.ip_address',
                    'a.created_at',
                    'c.company_name',
                    'u.name as user_name',
                    'u.username',
                ])
                ->orderByDesc('a.id')
                ->limit(12)
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'تم تحميل لوحة إدارة المنصة',
                'data' => [
                    'companies' => $companies,
                    'subscriptions' => $subscriptions,
                    'users' => $users,
                    'branches' => $branches,
                    'recent_companies' => $recentCompanies,
                    'expiring_subscriptions' => $expiringSubscriptions,
                    'plans_distribution' => $plansDistribution,
                    'recent_activities' => $recentActivities,
                    'generated_at' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تحميل لوحة إدارة المنصة',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }
}
