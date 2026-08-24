<?php

declare(strict_types=1);

namespace App\Services\Subscription;

final class SubscriptionAccessModeResolver
{
    public const FULL = 'FULL';
    public const RESTRICTED_READ_ONLY = 'RESTRICTED_READ_ONLY';
    public const BLOCKED = 'BLOCKED';

    public function resolve(?object $subscription): string
    {
        if (!$subscription) {
            return self::BLOCKED;
        }

        $status = strtoupper((string) ($subscription->effective_status ?? $subscription->status ?? ''));

        return match ($status) {
            SubscriptionLifecycleService::ACTIVE,
            SubscriptionLifecycleService::TRIAL => self::FULL,
            SubscriptionLifecycleService::SUSPENDED,
            SubscriptionLifecycleService::EXPIRED => self::RESTRICTED_READ_ONLY,
            SubscriptionLifecycleService::PENDING,
            SubscriptionLifecycleService::CANCELLED => self::BLOCKED,
            default => self::BLOCKED,
        };
    }
}
