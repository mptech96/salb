<?php

namespace Tests\Feature\Platform;

use App\Domain\Accounting\Services\AccountingBootstrapService;
use App\Services\Provisioning\CompanyProvisioningService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Wave1SubscriptionTestCase;

class CompanyOnboardingTest extends Wave1SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('companies',fn(Blueprint $t)=>$t->string('owner_name')->nullable());
        Schema::table('companies',fn(Blueprint $t)=>$t->string('phone')->nullable());
        Schema::table('companies',fn(Blueprint $t)=>$t->string('email')->nullable());
        Schema::table('companies',fn(Blueprint $t)=>$t->string('city')->nullable());
        Schema::table('companies',fn(Blueprint $t)=>$t->text('address')->nullable());
        Schema::table('branches',fn(Blueprint $t)=>$t->string('branch_code')->nullable());
        Schema::table('branches',fn(Blueprint $t)=>$t->string('phone')->nullable());
        Schema::table('branches',fn(Blueprint $t)=>$t->string('city')->nullable());
        Schema::table('branches',fn(Blueprint $t)=>$t->text('address')->nullable());
        Schema::table('branches',fn(Blueprint $t)=>$t->timestamps());
        Schema::table('plans',fn(Blueprint $t)=>$t->decimal('monthly_price',15,3)->default(0));
        Schema::table('plans',fn(Blueprint $t)=>$t->decimal('yearly_price',15,3)->nullable());
        Schema::table('plans',fn(Blueprint $t)=>$t->boolean('is_active')->default(true));
        Schema::table('user_roles',fn(Blueprint $t)=>$t->timestamps());
        Schema::table('subscription_entitlement_snapshots',fn(Blueprint $t)=>$t->timestamps());
        Schema::create('company_provisioning_requests',function(Blueprint $t){$t->id();$t->string('idempotency_key')->unique();$t->char('request_hash',64);$t->string('channel');$t->string('status');$t->unsignedBigInteger('company_id')->nullable();$t->json('result_json')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();});
        Schema::create('subscription_invoices',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('subscription_id');$t->unsignedBigInteger('plan_id');$t->string('invoice_number')->unique();$t->date('invoice_date');$t->date('due_date')->nullable();foreach(['subtotal','discount_amount','tax_rate','tax_amount','total_amount','paid_amount','remaining_amount'] as$c)$t->decimal($c,15,3)->default(0);$t->string('currency_code');$t->string('status');$t->string('billing_period');$t->date('period_start');$t->date('period_end');$t->text('notes')->nullable();$t->unsignedBigInteger('created_by')->nullable();$t->timestamps();});
        Schema::create('company_settings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id')->unique();foreach(['print_company_name','print_phone','print_email','print_city','print_address','currency_name','currency_code','primary_color','secondary_color'] as$c)$t->string($c)->nullable();$t->timestamps();});
        DB::table('roles')->insert([['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1],['role_name'=>'Owner','role_code'=>'COMPANY_OWNER','is_active'=>1]]);
        $this->mockAccounting();
    }

    public function test_platform_and_public_entry_points_use_the_same_provisioning_service(): void
    {
        foreach(['app/Http/Controllers/Api/CompanyController.php','app/Http/Controllers/Api/PublicRegistrationController.php'] as$file)self::assertStringContainsString('CompanyProvisioningService',$this->productionSource($file));
    }

    public function test_platform_onboarding_creates_exactly_one_tenant_owner_branch_accounting_subscription_and_snapshot(): void
    {
        $result=$this->service()->provision($this->payload('platform-1','PLATFORM_ADMIN'));
        self::assertSame(1,DB::table('companies')->count());self::assertSame(1,DB::table('users')->where('company_id',$result['company_id'])->count());
        self::assertSame(1,DB::table('branches')->where('company_id',$result['company_id'])->count());self::assertSame(1,DB::table('subscriptions')->where('company_id',$result['company_id'])->count());
        self::assertSame(1,DB::table('subscription_entitlement_snapshots')->where('subscription_id',$result['subscription_id'])->count());
        $role=DB::table('user_roles as ur')->join('roles as r','r.id','=','ur.role_id')->where('ur.user_id',$result['owner_id'])->value('r.role_code');
        self::assertSame('COMPANY_OWNER',$role);self::assertNotSame('SUPER_ADMIN',$role);self::assertSame($result['company_id'],(int)DB::table('users')->where('id',$result['owner_id'])->value('company_id'));
    }

    public function test_public_registration_creates_the_same_baseline_and_keeps_paid_subscription_pending(): void
    {
        $result=$this->service()->provision($this->payload('public-1','PUBLIC_REGISTRATION'));
        self::assertSame('PENDING',$result['subscription_status']);self::assertNotNull($result['invoice_id']);self::assertSame(0,(int)DB::table('companies')->where('id',$result['company_id'])->value('is_active'));
    }

    public function test_retry_is_idempotent_and_does_not_duplicate_any_baseline_record(): void
    {
        $payload=$this->payload('retry-1','PLATFORM_ADMIN');$a=$this->service()->provision($payload);$b=$this->service()->provision($payload);
        self::assertSame($a['company_id'],$b['company_id']);self::assertTrue($b['idempotent_replay']);
        app(AccountingBootstrapService::class)->shouldHaveReceived('bootstrapCompany')->once();
        foreach(['companies','users','branches','subscriptions','subscription_entitlement_snapshots','subscription_invoices'] as$table)self::assertSame(1,DB::table($table)->count(),$table);
        self::assertSame(1,DB::table('company_provisioning_requests')->count());
    }

    public function test_trial_is_created_only_when_explicitly_allowed(): void
    {
        $denied=$this->payload('trial-denied','PUBLIC_REGISTRATION');$denied['subscription_mode']='TRIAL';$denied['trial_allowed']=false;
        self::assertSame('PENDING',$this->service()->provision($denied)['subscription_status']);
        $allowed=$this->payload('trial-allowed','PLATFORM_ADMIN');$allowed['subscription_mode']='TRIAL';$allowed['trial_allowed']=true;
        $result=$this->service()->provision($allowed);self::assertSame('TRIAL',$result['subscription_status']);self::assertNull($result['invoice_id']);
    }

    public function test_same_key_with_changed_payload_is_rejected(): void
    {
        $payload=$this->payload('conflict','PLATFORM_ADMIN');$this->service()->provision($payload);$payload['company_name']='Different';$this->expectException(RuntimeException::class);$this->service()->provision($payload);
    }

    public function test_failure_halfway_rolls_back_everything(): void
    {
        $mock=$this->mock(AccountingBootstrapService::class);$mock->shouldReceive('bootstrapCompany')->once()->andThrow(new RuntimeException('bootstrap failed'));app()->forgetInstance(CompanyProvisioningService::class);
        try{$this->service()->provision($this->payload('rollback','PLATFORM_ADMIN'));self::fail('Expected failure');}catch(RuntimeException $e){self::assertSame('bootstrap failed',$e->getMessage());}
        foreach(['company_provisioning_requests','companies','users','branches','subscriptions','subscription_entitlement_snapshots'] as$table)self::assertSame(0,DB::table($table)->count(),$table);
    }

    private function payload(string $key,string $channel): array
    {
        $plan=DB::table('plans')->insertGetId(['plan_name'=>'Paid','plan_code'=>'PAID-'.$key,'monthly_price'=>100,'yearly_price'=>1000,'is_active'=>1]);
        DB::table('plan_features')->insert(['plan_id'=>$plan,'feature_code'=>'sales','is_enabled'=>1]);
        return ['idempotency_key'=>$key,'channel'=>$channel,'company_name'=>'Tenant '.$key,'owner_name'=>'Owner','phone'=>'0500000000','username'=>'owner-'.$key,'password'=>'secret123','plan_id'=>$plan,'billing_period'=>'YEARLY','start_date'=>'2026-08-24','end_date'=>'2027-08-23','subscription_mode'=>'PAID','trial_allowed'=>false,'company_is_active'=>false,'currency_code'=>'SAR'];
    }

    private function service(): CompanyProvisioningService {return app(CompanyProvisioningService::class);}
    private function mockAccounting(): void {$mock=$this->mock(AccountingBootstrapService::class);$mock->shouldReceive('bootstrapCompany')->andReturn(['financial_year_id'=>1,'company_cost_center_id'=>1,'branch_cost_center_id'=>1,'accounts'=>[]]);}
}
