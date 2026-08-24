<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Services\AuditService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Carbon\Carbon;

final class SubscriptionLifecycleService
{
    public const PENDING = 'PENDING';
    public const TRIAL = 'TRIAL';
    public const ACTIVE = 'ACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::PENDING,
        self::TRIAL,
        self::ACTIVE,
        self::SUSPENDED,
        self::EXPIRED,
        self::CANCELLED,
    ];

    private const TRANSITIONS = [
        self::PENDING => [self::TRIAL, self::ACTIVE, self::CANCELLED],
        self::TRIAL => [self::ACTIVE, self::SUSPENDED, self::EXPIRED, self::CANCELLED],
        self::ACTIVE => [self::ACTIVE, self::SUSPENDED, self::EXPIRED, self::CANCELLED],
        self::SUSPENDED => [self::ACTIVE, self::EXPIRED, self::CANCELLED],
        self::EXPIRED => [self::ACTIVE, self::CANCELLED],
        self::CANCELLED => [],
    ];

    public function normalizeStatus(?string $status): string
    {
        $normalized = strtoupper(trim((string) $status));
        if (!in_array($normalized, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported subscription status: {$normalized}");
        }

        return $normalized;
    }

    public function effectiveForCompany(int $companyId, DateTimeInterface|string|null $onDate = null): ?object
    {
        $rows = DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.company_id', $companyId)
            ->select(
                's.*',
                'p.plan_name',
                'p.plan_code',
                'p.max_branches',
                'p.max_users',
                'p.max_cars',
                'p.max_invoices',
            )
            ->get();

        return $this->resolveFromRows($rows, $onDate);
    }

    /**
     * Deterministic resolution independent of insertion order alone.
     */
    public function resolveFromRows(iterable $rows, DateTimeInterface|string|null $onDate = null): ?object
    {
        $date = $this->date($onDate);
        $ranked = collect($rows)->map(function (object|array $row) use ($date): object {
            $subscription = is_object($row) ? clone $row : (object) $row;
            $storedStatus = $this->normalizeStatus($subscription->status ?? null);
            $start = CarbonImmutable::parse((string) $subscription->start_date)->startOfDay();
            $end = CarbonImmutable::parse((string) $subscription->end_date)->startOfDay();
            $isFuture = $start->greaterThan($date);
            $isElapsed = $end->lessThan($date);

            $effectiveStatus = $storedStatus;
            if (in_array($storedStatus, [self::ACTIVE, self::TRIAL], true)) {
                if ($isElapsed) {
                    $effectiveStatus = self::EXPIRED;
                } elseif ($isFuture) {
                    $effectiveStatus = self::PENDING;
                }
            }

            $subscription->stored_status = $storedStatus;
            $subscription->effective_status = $effectiveStatus;
            $subscription->is_future = $isFuture;
            $subscription->is_elapsed = $isElapsed;
            $subscription->_lifecycle_rank = $this->rank($effectiveStatus, $isFuture);

            return $subscription;
        })->sort(function (object $left, object $right): int {
            foreach (['_lifecycle_rank', 'start_date', 'end_date', 'id'] as $field) {
                $a = $left->{$field} ?? null;
                $b = $right->{$field} ?? null;
                $comparison = $b <=> $a;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        $resolved = $ranked->first();
        if (!$resolved) {
            return null;
        }

        unset($resolved->_lifecycle_rank);
        return $resolved;
    }

    public function transition(int $subscriptionId, string $targetStatus, ?string $notes = null): object
    {
        $target = $this->normalizeStatus($targetStatus);

        return DB::transaction(function () use ($subscriptionId, $target, $notes): object {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            if (!$subscription) {
                throw new RuntimeException('الاشتراك غير موجود.');
            }

            $current = $this->normalizeStatus($subscription->status ?? null);
            if (!in_array($target, self::TRANSITIONS[$current], true)) {
                throw new RuntimeException("انتقال حالة الاشتراك غير مسموح: {$current} -> {$target}");
            }

            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => $target,
                'notes' => $notes ?? $subscription->notes,
                'updated_at' => now(),
            ]);

            AuditService::log(
                'Subscriptions',
                'STATUS_TRANSITION',
                $subscriptionId,
                "Subscription lifecycle transition: {$current} -> {$target}",
            );

            return DB::table('subscriptions')->where('id', $subscriptionId)->first();
        });
    }

    public function expireElapsedSubscriptions(DateTimeInterface|string|null $onDate = null): int
    {
        $date = $this->date($onDate)->toDateString();
        $ids = DB::table('subscriptions')
            ->whereIn('status', [self::ACTIVE, self::TRIAL])
            ->whereDate('end_date', '<', $date)
            ->orderBy('id')
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $this->transition((int) $id, self::EXPIRED, 'Automatic lifecycle expiry on ' . $date);
            $count++;
        }

        return $count;
    }

    public function extend(int $subscriptionId, int $days, ?string $notes = null): object
    {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('مدة التمديد غير صحيحة.');
        }

        return DB::transaction(function () use ($subscriptionId, $days, $notes): object {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            if (!$subscription) {
                throw new RuntimeException('الاشتراك غير موجود.');
            }

            $current = $this->normalizeStatus($subscription->status ?? null);
            if (!in_array(self::ACTIVE, self::TRANSITIONS[$current], true)) {
                throw new RuntimeException("لا يمكن تمديد اشتراك في الحالة {$current}.");
            }

            $currentEndDate = Carbon::parse($subscription->end_date);
            $baseDate = $currentEndDate->isPast() ? Carbon::today() : $currentEndDate;
            $newEndDate = $baseDate->copy()->addDays($days)->toDateString();

            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'end_date' => $newEndDate,
                'status' => self::ACTIVE,
                'notes' => $notes ?? $subscription->notes,
                'updated_at' => now(),
            ]);

            AuditService::log(
                'Subscriptions',
                'EXTEND',
                $subscriptionId,
                "Subscription extended and transitioned: {$current} -> ACTIVE, end_date={$newEndDate}",
            );

            return DB::table('subscriptions')->where('id', $subscriptionId)->first();
        });
    }

    private function rank(string $status, bool $future): int
    {
        if ($future) {
            return $status === self::PENDING ? 100 : 50;
        }

        return match ($status) {
            self::ACTIVE => 600,
            self::TRIAL => 500,
            self::CANCELLED => 450,
            self::SUSPENDED => 400,
            self::EXPIRED => 300,
            self::PENDING => 100,
            default => 0,
        };
    }

    private function date(DateTimeInterface|string|null $date): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->startOfDay();
        }

        return $date ? CarbonImmutable::parse($date)->startOfDay() : CarbonImmutable::today();
    }
}
