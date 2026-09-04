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
        Schema::table('roles',fn(Blueprint $t)=>$t->timestamps());
        Schema::table('plans',fn(Blueprint $t)=>$t->decimal('monthly_price',15,3)->default(0));
        Schema::table('plans',fn(Blueprint $t)=>$t->decimal('yearly_price',15,3)->nullable());
        Schema::table('plans',fn(Blueprint $t)=>$t->boolean('is_active')->default(true));
        Schema::table('user_roles',fn(Blueprint $t)=>$t->timestamps());
        Schema::table('subscription_entitlement_snapshots',fn(Blueprint $t)=>$t->timestamps());
        Schema::create('company_provisioning_requests',function(Blueprint $t){$t->id();$t->string('idempotency_key')->unique();$t->char('request_hash',64);$t->string('channel');$t->string('status');$t->unsignedBigInteger('company_id')->nullable();$t->json('result_json')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();});
        Schema::create('subscription_invoices',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('subscription_id');$t->unsignedBigInteger('plan_id');$t->string('invoice_number')->unique();$t->date('invoice_date');$t->date('due_date')->nullable();foreach(['subtotal','discount_amount','tax_rate','tax_amount','total_amount','paid_amount','remaining_amount'] as$c)$t->decimal($c,15,3)->default(0);$t->string('currency_code');$t->string('status');$t->string('billing_period');$t->date('period_start');$t->date('period_end');$t->text('notes')->nullable();$t->unsignedBigInteger('created_by')->nullable();$t->timestamps();});
        Schema::create('company_settings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id')->unique();foreach(['print_company_name','print_phone','print_email','print_city','print_address','currency_name','currency_code','base_currency_code','primary_color','secondary_color'] as$c)$t->string($c)->nullable();$t->timestamps();});
        DB::table('roles')->insert(['role_name'=>'Platform','role_code'=>'SUPER_ADMIN','is_active'=>1]);
        $this->ownerBaselineMigration()->up();
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
        self::assertSame($result['company_id'],(int)DB::table('user_roles')->where('user_id',$result['owner_id'])->value('company_id'));
        self::assertSame(0,DB::table('role_permissions')->where('role_id',DB::table('roles')->where('role_code','COMPANY_OWNER')->value('id'))->count());
    }

    public function test_company_owner_baseline_migration_is_idempotent_and_never_assigns_permissions(): void
    {
        $before=(array)DB::table('roles')->where('role_code','COMPANY_OWNER')->first();
        $this->ownerBaselineMigration()->up();
        $after=(array)DB::table('roles')->where('role_code','COMPANY_OWNER')->first();

        self::assertSame($before,$after);
        self::assertSame(1,DB::table('roles')->where('role_code','COMPANY_OWNER')->count());
        self::assertSame(0,DB::table('role_permissions')->where('role_id',$before['id'])->count());
    }

    public function test_existing_active_company_owner_definition_is_never_modified(): void
    {
        DB::table('roles')->where('role_code','COMPANY_OWNER')->update(['role_name'=>'Existing company owner label']);
        $before=(array)DB::table('roles')->where('role_code','COMPANY_OWNER')->first();

        $this->ownerBaselineMigration()->up();

        self::assertSame($before,(array)DB::table('roles')->where('role_code','COMPANY_OWNER')->first());
    }

    public function test_inactive_company_owner_fails_without_being_modified(): void
    {
        DB::table('roles')->where('role_code','COMPANY_OWNER')->update(['is_active'=>0]);

        try {
            $this->ownerBaselineMigration()->up();
            self::fail('Expected the inactive company owner baseline to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('COMPANY_OWNER exists but is inactive',$exception->getMessage());
        }

        self::assertSame(0,(int)DB::table('roles')->where('role_code','COMPANY_OWNER')->value('is_active'));
    }

    public function test_missing_owner_and_admin_roles_fail_cleanly_without_partial_tenant_creation(): void
    {
        DB::table('roles')->where('role_code','COMPANY_OWNER')->delete();

        try {
            $this->service()->provision($this->payload('owner-missing','PLATFORM_ADMIN'));
            self::fail('Expected missing safe company owner roles to block provisioning.');
        } catch (RuntimeException $exception) {
            self::assertSame('A safe Company Owner role is not configured.',$exception->getMessage());
        }

        self::assertSame(0,DB::table('companies')->count());
        self::assertSame(0,DB::table('users')->count());
        self::assertSame(0,DB::table('company_provisioning_requests')->count());
    }

    public function test_company_admin_is_supported_only_as_existing_owner_fallback(): void
    {
        DB::table('roles')->where('role_code','COMPANY_OWNER')->delete();
        DB::table('roles')->insert(['role_name'=>'Company administrator','role_code'=>'COMPANY_ADMIN','is_active'=>1]);

        $result=$this->service()->provision($this->payload('owner-fallback','PLATFORM_ADMIN'));
        $role=DB::table('user_roles as ur')->join('roles as r','r.id','=','ur.role_id')->where('ur.user_id',$result['owner_id'])->value('r.role_code');

        self::assertSame('COMPANY_ADMIN',$role);
        self::assertSame($result['company_id'],(int)DB::table('user_roles')->where('user_id',$result['owner_id'])->value('company_id'));
    }

    public function test_company_owner_is_preferred_when_company_admin_also_exists(): void
    {
        DB::table('roles')->insert(['role_name'=>'Company administrator','role_code'=>'COMPANY_ADMIN','is_active'=>1]);

        $result=$this->service()->provision($this->payload('owner-preferred','PLATFORM_ADMIN'));
        $role=DB::table('user_roles as ur')->join('roles as r','r.id','=','ur.role_id')->where('ur.user_id',$result['owner_id'])->value('r.role_code');

        self::assertSame('COMPANY_OWNER',$role);
    }

    public function test_baseline_rollback_never_removes_an_assigned_owner_role(): void
    {
        $result=$this->service()->provision($this->payload('owner-rollback-protected','PLATFORM_ADMIN'));

        $this->ownerBaselineMigration()->down();

        self::assertDatabaseHas('roles',['role_code'=>'COMPANY_OWNER']);
        self::assertSame(1,DB::table('user_roles')->where('user_id',$result['owner_id'])->count());
    }

    public function test_baseline_rollback_removes_only_the_unassigned_canonical_role(): void
    {
        $this->ownerBaselineMigration()->down();

        self::assertDatabaseMissing('roles',['role_code'=>'COMPANY_OWNER']);
        self::assertDatabaseHas('roles',['role_code'=>'SUPER_ADMIN']);
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

    public function test_real_accounting_bootstrap_uses_sar_without_mutating_global_currency_rows(): void
    {
        $this->useRealAccountingBootstrap();
        $before=$this->currencyMasterSnapshot();
        $payload=$this->payload('currency-sar','PLATFORM_ADMIN');

        $result=$this->service()->provision($payload);
        $retry=$this->service()->provision($payload);
        $companyId=$result['company_id'];

        self::assertSame('SAR',DB::table('company_settings')->where('company_id',$companyId)->value('currency_code'));
        self::assertSame('SAR',DB::table('financial_accounts')->where('company_id',$companyId)->value('currency_code'));
        self::assertDatabaseHas('company_currencies',['company_id'=>$companyId,'currency_code'=>'SAR']);
        self::assertDatabaseMissing('company_currencies',['company_id'=>$companyId,'currency_code'=>'USD']);
        self::assertSame($before,$this->currencyMasterSnapshot());
        self::assertSame(2,DB::table('currencies')->count());
        self::assertSame(1,DB::table('financial_accounts')->where('company_id',$companyId)->count());
        self::assertTrue($retry['idempotent_replay']);
    }

    public function test_explicit_supported_non_sar_currency_remains_consistent(): void
    {
        $this->useRealAccountingBootstrap();
        $before=$this->currencyMasterSnapshot();
        $payload=$this->payload('currency-usd','PUBLIC_REGISTRATION');
        $payload['currency_code']='usd';

        $result=$this->service()->provision($payload);
        $companyId=$result['company_id'];

        self::assertSame('USD',DB::table('company_settings')->where('company_id',$companyId)->value('currency_code'));
        self::assertSame('USD',DB::table('financial_accounts')->where('company_id',$companyId)->value('currency_code'));
        self::assertSame('USD',DB::table('subscription_invoices')->where('company_id',$companyId)->value('currency_code'));
        self::assertSame($before,$this->currencyMasterSnapshot());
    }

    public function test_provisioning_defaults_to_sar_when_currency_is_not_provided(): void
    {
        $this->useRealAccountingBootstrap();
        $payload=$this->payload('currency-default','PLATFORM_ADMIN');
        unset($payload['currency_code']);

        $result=$this->service()->provision($payload);

        self::assertSame('SAR',DB::table('company_settings')->where('company_id',$result['company_id'])->value('currency_code'));
        self::assertSame('SAR',DB::table('financial_accounts')->where('company_id',$result['company_id'])->value('currency_code'));
    }

    public function test_missing_required_currency_fails_cleanly_and_rolls_back_all_tenant_records(): void
    {
        $this->useRealAccountingBootstrap();
        $before=$this->currencyMasterSnapshot();
        $payload=$this->payload('currency-missing','PLATFORM_ADMIN');
        $payload['currency_code']='EUR';

        try {
            $this->service()->provision($payload);
            self::fail('Expected an unavailable currency to prevent company provisioning.');
        } catch(RuntimeException $exception) {
            self::assertSame('The required active company currency is not configured: EUR',$exception->getMessage());
        }

        foreach(['company_provisioning_requests','companies','users','branches','company_settings','financial_years','accounts','accounting_settings','financial_accounts','company_currencies','subscriptions'] as$table){
            self::assertSame(0,DB::table($table)->count(),$table);
        }
        self::assertSame($before,$this->currencyMasterSnapshot());
    }

    public function test_platform_and_public_registration_share_real_currency_consistent_bootstrap(): void
    {
        $this->useRealAccountingBootstrap();

        foreach(['PLATFORM_ADMIN','PUBLIC_REGISTRATION'] as$channel){
            $result=$this->service()->provision($this->payload('currency-'.strtolower($channel),$channel));
            $companyId=$result['company_id'];

            self::assertSame('SAR',DB::table('company_settings')->where('company_id',$companyId)->value('currency_code'));
            self::assertSame('SAR',DB::table('financial_accounts')->where('company_id',$companyId)->value('currency_code'));
        }
    }

    public function test_p1_real_provisioning_readiness_and_bootstrap_retry(): void
    {
        $this->useRealAccountingBootstrap();
        Schema::create('tax_codes',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->boolean('is_active')->default(true);});
        $payload=$this->payload('p1-readiness','PLATFORM_ADMIN');
        $result=$this->service()->provision($payload);$cid=$result['company_id'];
        $branch=(int)DB::table('branches')->where('company_id',$cid)->value('id');
        self::assertSame(84,DB::table('accounts')->where('company_id',$cid)->count());
        self::assertSame(1,DB::table('company_currencies')->where('company_id',$cid)->where('is_base',1)->where('is_active',1)->count());
        self::assertSame(1,DB::table('financial_years')->where('company_id',$cid)->where('is_closed',0)->count());
        self::assertSame(2,DB::table('cost_centers')->where('company_id',$cid)->count());
        self::assertSame(1,DB::table('financial_accounts')->where('company_id',$cid)->where('account_type','CASH')->count());
        $tables=['accounts','accounting_settings','company_currencies','financial_years','cost_centers','financial_accounts','branch_financial_settings'];
        $before=[];foreach($tables as$table)$before[$table]=DB::table($table)->where('company_id',$cid)->count();
        app(AccountingBootstrapService::class)->bootstrapCompany($cid,$branch);
        foreach($tables as$table)self::assertSame($before[$table],DB::table($table)->where('company_id',$cid)->count(),$table);
        self::assertTrue($this->service()->provision($payload)['idempotent_replay']);
        $method=new \ReflectionMethod(\App\Http\Controllers\Api\FinancialSetupController::class,'readiness');
        $readiness=$method->invoke(app(\App\Http\Controllers\Api\FinancialSetupController::class),$cid);
        self::assertSame('READY',$readiness['status']);
        self::assertSame('NOT_CONFIGURED',$readiness['tax_status']);
        self::assertSame(0,DB::table('tax_codes')->count());
    }

    private function useRealAccountingBootstrap(): void
    {
        Schema::create('financial_years',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('year_name');$t->date('start_date');$t->date('end_date');$t->boolean('is_closed');$t->timestamps();});
        Schema::create('cost_centers',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('parent_id')->nullable();$t->string('cost_center_code');$t->string('cost_center_name');$t->boolean('is_group');$t->boolean('is_active');$t->timestamps();});
        Schema::create('accounts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->string('normal_side');$t->integer('account_level');$t->boolean('is_group');$t->boolean('allow_posting');$t->boolean('allow_cost_center');$t->boolean('is_active');$t->text('notes')->nullable();$t->timestamps();});
        Schema::create('accounting_settings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('setting_key');$t->unsignedBigInteger('account_id');$t->timestamps();});
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('currency_code')->unique();$t->string('currency_name');$t->string('symbol')->nullable();$t->unsignedTinyInteger('decimal_places');$t->boolean('is_active');$t->timestamps();});
        Schema::create('company_currencies',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('currency_code');$t->boolean('is_base')->default(false);$t->boolean('is_active');$t->timestamps();});
        Schema::create('financial_accounts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->unsignedBigInteger('gl_account_id');$t->string('currency_code');$t->boolean('is_default_receipt');$t->boolean('is_default_payment');$t->boolean('is_active');$t->timestamps();});
        Schema::create('branch_financial_settings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('default_cash_financial_account_id');$t->unsignedBigInteger('default_cost_center_id');$t->timestamps();});

        DB::table('currencies')->insert([
            ['currency_code'=>'USD','currency_name'=>'United States dollar','symbol'=>'$','decimal_places'=>3,'is_active'=>1,'created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-01-02 11:00:00'],
            ['currency_code'=>'SAR','currency_name'=>'Saudi riyal','symbol'=>'SAR','decimal_places'=>2,'is_active'=>1,'created_at'=>'2026-02-01 12:00:00','updated_at'=>'2026-02-02 13:00:00'],
        ]);

        app()->instance(AccountingBootstrapService::class,new AccountingBootstrapService());
        app()->forgetInstance(CompanyProvisioningService::class);
    }

    private function currencyMasterSnapshot(): array
    {
        return DB::table('currencies')->orderBy('currency_code')->get()->map(static fn(object$row):array=>(array)$row)->all();
    }

    private function payload(string $key,string $channel): array
    {
        $plan=DB::table('plans')->insertGetId(['plan_name'=>'Paid','plan_code'=>'PAID-'.$key,'monthly_price'=>100,'yearly_price'=>1000,'is_active'=>1]);
        DB::table('plan_features')->insert(['plan_id'=>$plan,'feature_code'=>'sales','is_enabled'=>1]);
        return ['idempotency_key'=>$key,'channel'=>$channel,'company_name'=>'Tenant '.$key,'owner_name'=>'Owner','phone'=>'0500000000','username'=>'owner-'.$key,'password'=>'secret123','plan_id'=>$plan,'billing_period'=>'YEARLY','start_date'=>'2026-08-24','end_date'=>'2027-08-23','subscription_mode'=>'PAID','trial_allowed'=>false,'company_is_active'=>false,'currency_code'=>'SAR'];
    }

    private function service(): CompanyProvisioningService {return app(CompanyProvisioningService::class);}
    private function ownerBaselineMigration(): object {return require database_path('migrations/2026_08_25_000024_ensure_company_owner_role_baseline.php');}
    private function mockAccounting(): void {$mock=$this->mock(AccountingBootstrapService::class);$mock->shouldReceive('bootstrapCompany')->andReturn(['financial_year_id'=>1,'company_cost_center_id'=>1,'branch_cost_center_id'=>1,'accounts'=>[]]);}
}
