<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class CompanyOnboardingTest extends PlatformControlPlaneTestCase
{
    public function test_platform_onboarding_currently_uses_a_transaction_and_accounting_bootstrap(): void
    {
        $source = $this->productionSource('app/Http/Controllers/Api/CompanyController.php');
        self::assertStringContainsString('DB::beginTransaction()', $source);
        self::assertStringContainsString('$bootstrap->bootstrapCompany(', $source);
        self::assertStringContainsString('DB::commit()', $source);
    }

    public function test_platform_onboarding_creates_a_primary_company_owner_account(): void
    {
        $this->pendingDefect('DEF-ONB-001', 'Platform onboarding must create and company-scope the primary Company Owner user.');
    }

    public function test_public_and_platform_onboarding_share_one_idempotent_provisioning_service(): void
    {
        $this->pendingDefect('DEF-ONB-002', 'Both onboarding entry points must call one idempotent provisioning orchestration service.');
    }

    public function test_onboarding_does_not_activate_a_paid_subscription_before_payment_confirmation(): void
    {
        $this->pendingDefect('DEF-ONB-003', 'Paid plans must remain PENDING until confirmed payment or explicit audited activation.');
    }
}

