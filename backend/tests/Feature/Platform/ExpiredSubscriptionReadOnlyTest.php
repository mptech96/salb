<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnsureSubscriptionAccess;
use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\Wave1SubscriptionTestCase;

class ExpiredSubscriptionReadOnlyTest extends Wave1SubscriptionTestCase
{
    public function test_expired_company_can_login_for_restricted_read_only_access(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$this->companyUserWithSubscription('EXPIRED');
        $this->postJson('/api/login',['username'=>'manager-EXPIRED-1','password'=>'password123'])->assertOk()->assertJsonPath('subscription.access_mode','RESTRICTED_READ_ONLY');
    }

    public function test_suspended_and_expired_company_data_remains_readable(): void
    {
        foreach(['SUSPENDED','EXPIRED'] as $status){$resolved=app(SubscriptionLifecycleService::class)->resolveFromRows([(object)['id'=>1,'status'=>$status,'start_date'=>'2026-01-01','end_date'=>'2026-12-31']],'2026-08-24');self::assertSame(SubscriptionAccessModeResolver::RESTRICTED_READ_ONLY,app(SubscriptionAccessModeResolver::class)->resolve($resolved));}
    }

    public function test_expired_company_cannot_create_modify_post_void_or_import(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$ids=$this->companyUserWithSubscription('EXPIRED');
        foreach(['POST','PUT','PATCH','DELETE'] as $method){$request=Request::create('/api/items',$method);$request->attributes->set('tenant_company_id',$ids['companyId']);$response=app(EnsureSubscriptionAccess::class)->handle($request,fn()=>response()->json(['unexpected'=>true]));self::assertSame(403,$response->getStatusCode());self::assertStringContainsString('SUBSCRIPTION_READ_ONLY',(string)$response->getContent());}
    }

    public function test_cancelled_subscription_is_blocked(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$ids=$this->companyUserWithSubscription('CANCELLED');$request=Request::create('/api/items','GET');$request->attributes->set('tenant_company_id',$ids['companyId']);$response=app(EnsureSubscriptionAccess::class)->handle($request,fn()=>response()->json(['unexpected'=>true]));self::assertSame(403,$response->getStatusCode());self::assertStringContainsString('SUBSCRIPTION_BLOCKED',(string)$response->getContent());
    }

    public function test_subscription_expiry_never_deletes_tenant_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24'));$ids=$this->companyUserWithSubscription('ACTIVE','2026-01-01','2026-08-23');DB::table('retained_documents')->insert(['company_id'=>$ids['companyId'],'document_number'=>'INV-001']);app(SubscriptionLifecycleService::class)->expireElapsedSubscriptions('2026-08-24');self::assertDatabaseHas('retained_documents',['company_id'=>$ids['companyId'],'document_number'=>'INV-001']);
    }
}
