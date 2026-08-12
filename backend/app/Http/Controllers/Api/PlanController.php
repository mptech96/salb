<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    use LogsActivity;

    public function index(): JsonResponse
    {
        $data = DB::table('plans')
            ->where('is_active', 1)
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = DB::table('plans as p')
                ->leftJoin('subscriptions as s', 's.plan_id', '=', 'p.id')
                ->select([
                    'p.id',
                    'p.plan_name',
                    'p.plan_code',
                    'p.monthly_price',
                    'p.yearly_price',
                    'p.max_branches',
                    'p.max_users',
                    'p.max_cars',
                    'p.max_invoices',
                    'p.is_active',
                    'p.created_at',
                    'p.updated_at',
                ])
                ->selectRaw('COUNT(DISTINCT s.company_id) AS companies_count')
                ->groupBy([
                    'p.id',
                    'p.plan_name',
                    'p.plan_code',
                    'p.monthly_price',
                    'p.yearly_price',
                    'p.max_branches',
                    'p.max_users',
                    'p.max_cars',
                    'p.max_invoices',
                    'p.is_active',
                    'p.created_at',
                    'p.updated_at',
                ]);

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('p.plan_name', 'like', '%' . $search . '%')
                        ->orWhere('p.plan_code', 'like', '%' . $search . '%');
                });
            }

            if ($request->filled('status')) {
                if ($request->status === 'ACTIVE') {
                    $query->where('p.is_active', 1);
                }

                if ($request->status === 'INACTIVE') {
                    $query->where('p.is_active', 0);
                }
            }

            $plans = $query
                ->orderBy('p.monthly_price')
                ->orderBy('p.id')
                ->get();

            return response()->json([
                'status' => true,
                'data' => [
                    'summary' => [
                        'total' => $plans->count(),
                        'active' => $plans->where('is_active', 1)->count(),
                        'inactive' => $plans->where('is_active', 0)->count(),
                        'companies' => (int) $plans->sum('companies_count'),
                    ],
                    'plans' => $plans,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تحميل الباقات',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $plan = DB::table('plans as p')
            ->leftJoin('subscriptions as s', 's.plan_id', '=', 'p.id')
            ->where('p.id', $id)
            ->select([
                'p.id',
                'p.plan_name',
                'p.plan_code',
                'p.monthly_price',
                'p.yearly_price',
                'p.max_branches',
                'p.max_users',
                'p.max_cars',
                'p.max_invoices',
                'p.is_active',
                'p.created_at',
                'p.updated_at',
            ])
            ->selectRaw('COUNT(DISTINCT s.company_id) AS companies_count')
            ->groupBy([
                'p.id',
                'p.plan_name',
                'p.plan_code',
                'p.monthly_price',
                'p.yearly_price',
                'p.max_branches',
                'p.max_users',
                'p.max_cars',
                'p.max_invoices',
                'p.is_active',
                'p.created_at',
                'p.updated_at',
            ])
            ->first();

        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'الباقة غير موجودة',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $plan,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePlan($request);

        DB::beginTransaction();

        try {
            $planId = DB::table('plans')->insertGetId([
                ...$validated,
                'plan_code' => strtoupper(trim($validated['plan_code'])),
                'yearly_price' => $validated['yearly_price'] ?? null,
                'is_active' => (int) ($validated['is_active'] ?? 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $this->logCreate(
                'Plans',
                $planId,
                'تم إنشاء باقة جديدة: ' . $validated['plan_name']
            );

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الباقة بنجاح',
                'data' => ['id' => $planId],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر إنشاء الباقة',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plan = DB::table('plans')->where('id', $id)->first();

        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'الباقة غير موجودة',
            ], 404);
        }

        $validated = $this->validatePlan($request, $id);

        DB::beginTransaction();

        try {
            DB::table('plans')
                ->where('id', $id)
                ->update([
                    ...$validated,
                    'plan_code' => strtoupper(trim($validated['plan_code'])),
                    'yearly_price' => $validated['yearly_price'] ?? $plan->yearly_price,
                    'is_active' => (int) ($validated['is_active'] ?? $plan->is_active),
                    'updated_at' => now(),
                ]);

            DB::commit();

            $this->logUpdate(
                'Plans',
                $id,
                'تم تعديل الباقة: ' . $validated['plan_name']
            );

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث الباقة بنجاح',
                'data' => [
                    'yearly_price' => $validated['yearly_price'] ?? $plan->yearly_price,
                    'plan_name' => $validated['plan_name'] ?? $plan->plan_name,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'تعذر تحديث الباقة',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function toggle(int $id): JsonResponse
    {
        $plan = DB::table('plans')->where('id', $id)->first();

        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'الباقة غير موجودة',
            ], 404);
        }

        $newStatus = (int) $plan->is_active === 1 ? 0 : 1;

        DB::table('plans')
            ->where('id', $id)
            ->update([
                'is_active' => $newStatus,
                'updated_at' => now(),
            ]);

        $this->logUpdate(
            'Plans',
            $id,
            $newStatus === 1
                ? 'تم تفعيل الباقة: ' . $plan->plan_name
                : 'تم إيقاف الباقة: ' . $plan->plan_name
        );

        return response()->json([
            'status' => true,
            'message' => $newStatus === 1
                ? 'تم تفعيل الباقة'
                : 'تم إيقاف الباقة',
            'data' => ['is_active' => $newStatus],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = DB::table('plans')->where('id', $id)->first();

        if (!$plan) {
            return response()->json([
                'status' => false,
                'message' => 'الباقة غير موجودة',
            ], 404);
        }

        $subscriptionsCount = DB::table('subscriptions')
            ->where('plan_id', $id)
            ->count();

        if ($subscriptionsCount > 0) {
            return response()->json([
                'status' => false,
                'message' => 'لا يمكن حذف الباقة لأنها مرتبطة باشتراكات. يمكنك إيقافها بدلًا من الحذف.',
            ], 422);
        }

        DB::table('plans')->where('id', $id)->delete();

        $this->logDelete(
            'Plans',
            $id,
            'تم حذف الباقة: ' . $plan->plan_name
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الباقة بنجاح',
        ]);
    }

    private function validatePlan(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'plan_name' => ['required', 'string', 'max:150'],
            'plan_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('plans', 'plan_code')->ignore($ignoreId),
            ],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_cars' => ['nullable', 'integer', 'min:1'],
            'max_invoices' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }
}
