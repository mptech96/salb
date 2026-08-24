<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

abstract class Wave1SubscriptionTestCase extends PlatformControlPlaneTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['support_sessions','company_entitlement_overrides','subscription_entitlement_snapshots','plan_features','feature_catalog','official_documents','sales_invoices','purchase_invoices','cars','stores','audit_logs','personal_access_tokens','user_permission_overrides','role_permissions','permissions','user_roles','roles','users','branches','subscriptions','plans','companies','retained_documents'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('companies', function (Blueprint $table): void {$table->id();$table->string('company_name');$table->boolean('is_active')->default(true);$table->timestamps();});
        Schema::create('plans', function (Blueprint $table): void {$table->id();$table->string('plan_name');$table->string('plan_code');$table->integer('max_branches')->nullable();$table->integer('max_users')->nullable();$table->integer('max_cars')->nullable();$table->integer('max_invoices')->nullable();});
        Schema::create('subscriptions', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->foreignId('plan_id');$table->date('start_date');$table->date('end_date');$table->string('status');$table->text('notes')->nullable();$table->timestamps();});
        Schema::create('branches', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->string('branch_name');$table->boolean('is_active')->default(true);});
        Schema::create('users', function (Blueprint $table): void {$table->id();$table->foreignId('company_id')->nullable();$table->foreignId('branch_id')->nullable();$table->string('name');$table->string('username')->unique();$table->string('email')->nullable();$table->string('phone')->nullable();$table->string('password');$table->boolean('is_active')->default(true);$table->rememberToken();$table->timestamps();});
        Schema::create('roles', function (Blueprint $table): void {$table->id();$table->string('role_name');$table->string('role_code');$table->boolean('is_active')->default(true);});
        Schema::create('user_roles', function (Blueprint $table): void {$table->id();$table->foreignId('user_id');$table->foreignId('role_id');$table->foreignId('company_id')->nullable();$table->boolean('is_active')->default(true);});
        Schema::create('permissions', function (Blueprint $table): void {$table->id();$table->string('permission_code');$table->string('permission_scope')->default('COMPANY');});
        Schema::create('role_permissions', function (Blueprint $table): void {$table->id();$table->foreignId('role_id');$table->foreignId('permission_id');$table->foreignId('company_id')->nullable();$table->boolean('is_active')->default(true);});
        Schema::create('user_permission_overrides', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->foreignId('user_id');$table->foreignId('permission_id');$table->string('effect');});
        Schema::create('personal_access_tokens', function (Blueprint $table): void {$table->id();$table->string('tokenable_type');$table->unsignedBigInteger('tokenable_id');$table->string('name');$table->string('token',64)->unique();$table->text('abilities')->nullable();$table->timestamp('last_used_at')->nullable();$table->timestamp('expires_at')->nullable();$table->timestamps();$table->index(['tokenable_type','tokenable_id']);});
        Schema::create('audit_logs', function (Blueprint $table): void {$table->id();$table->foreignId('company_id')->nullable();$table->foreignId('branch_id')->nullable();$table->foreignId('user_id')->nullable();$table->string('actor_type')->nullable();$table->string('actor_role_code')->nullable();$table->uuid('support_session_id')->nullable();$table->string('ticket_reference')->nullable();$table->text('reason')->nullable();$table->json('scope_json')->nullable();$table->json('before_json')->nullable();$table->json('after_json')->nullable();$table->string('result')->nullable();$table->uuid('request_id')->nullable();$table->string('module_name');$table->string('action_type');$table->unsignedBigInteger('record_id')->nullable();$table->text('description')->nullable();$table->string('ip_address')->nullable();$table->text('user_agent')->nullable();$table->timestamps();});
        Schema::create('support_sessions',function(Blueprint $t):void{$t->id();$t->uuid('support_session_id')->unique();$t->foreignId('platform_user_id');$t->foreignId('company_id');$t->foreignId('branch_id')->nullable();$t->foreignId('personal_access_token_id')->nullable();$t->string('access_level')->default('READ_ONLY');$t->json('capabilities_json')->nullable();$t->text('reason');$t->string('ticket_reference');$t->string('status')->default('ACTIVE');$t->timestamp('started_at');$t->timestamp('expires_at');$t->timestamp('ended_at')->nullable();$t->timestamp('revoked_at')->nullable();$t->timestamps();});
        Schema::create('retained_documents', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->string('document_number');});
        Schema::create('cars', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->boolean('is_active')->default(true);});
        Schema::create('stores', function (Blueprint $table): void {$table->id();$table->foreignId('company_id');$table->boolean('is_active')->default(true);});
        foreach(['purchase_invoices','sales_invoices','official_documents'] as $table)Schema::create($table,function(Blueprint $t):void{$t->id();$t->foreignId('company_id');});
        Schema::create('feature_catalog',function(Blueprint $t):void{$t->id();$t->string('feature_code')->unique();$t->string('feature_name')->nullable();$t->string('feature_type')->default('BOOLEAN');$t->string('module_name')->nullable();$t->boolean('is_active')->default(true);});
        Schema::create('plan_features',function(Blueprint $t):void{$t->id();$t->foreignId('plan_id');$t->string('feature_code');$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();});
        Schema::create('subscription_entitlement_snapshots',function(Blueprint $t):void{$t->id();$t->foreignId('subscription_id');$t->foreignId('company_id');$t->foreignId('plan_id');$t->string('feature_code');$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();$t->dateTime('effective_from');$t->dateTime('effective_to')->nullable();$t->string('source')->default('PLAN');});
        Schema::create('company_entitlement_overrides',function(Blueprint $t):void{$t->id();$t->foreignId('company_id');$t->string('feature_code');$t->boolean('is_enabled')->nullable();$t->unsignedBigInteger('limit_value')->nullable();$t->dateTime('effective_from');$t->dateTime('effective_to')->nullable();});
    }

    protected function companyUserWithSubscription(string $status, string $startDate = '2026-01-01', string $endDate = '2026-12-31'): array
    {
        $companyId=DB::table('companies')->insertGetId(['company_name'=>'Tenant A','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $planId=DB::table('plans')->insertGetId(['plan_name'=>'Core','plan_code'=>'CORE']);
        $branchId=DB::table('branches')->insertGetId(['company_id'=>$companyId,'branch_name'=>'Main','is_active'=>1]);
        $roleId=DB::table('roles')->insertGetId(['role_name'=>'Company Manager','role_code'=>'MANAGER','is_active'=>1]);
        $userId=DB::table('users')->insertGetId(['company_id'=>$companyId,'branch_id'=>$branchId,'name'=>'Manager','username'=>'manager-'.$status.'-'.$companyId,'password'=>Hash::make('password123'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('user_roles')->insert(['user_id'=>$userId,'role_id'=>$roleId,'company_id'=>$companyId,'is_active'=>1]);
        $subscriptionId=DB::table('subscriptions')->insertGetId(['company_id'=>$companyId,'plan_id'=>$planId,'start_date'=>$startDate,'end_date'=>$endDate,'status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
        return compact('companyId','planId','branchId','roleId','userId','subscriptionId');
    }
}
