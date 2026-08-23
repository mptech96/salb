<?php

namespace Tests\Feature\Platform;

use Tests\Support\PlatformControlPlaneTestCase;

class UsageLimitsTest extends PlatformControlPlaneTestCase
{
    public function test_user_limit_is_enforced_transactionally(): void
    {
        $this->pendingDefect('DEF-LIMIT-001', 'Creating a user at max_users must be denied by the backend without a race condition.');
    }

    public function test_branch_car_and_document_limits_are_enforced_transactionally(): void
    {
        $this->pendingDefect('DEF-LIMIT-001', 'Branch, car, warehouse, and document limits must be company-scoped and concurrency-safe.');
    }

    public function test_inactive_or_archived_resource_counting_is_defined_by_metric(): void
    {
        $this->pendingDefect('DEF-LIMIT-002', 'Every limit metric must define whether inactive and archived resources consume capacity.');
    }
}

