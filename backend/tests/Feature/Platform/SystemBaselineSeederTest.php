<?php

namespace Tests\Feature\Platform;

use Database\Seeders\SystemBaselineSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemBaselineSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('role_name', 100);
            $table->string('role_code', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('permission_name', 150);
            $table->string('permission_code', 150)->unique();
            $table->string('module_name', 100)->nullable();
            $table->string('permission_scope', 20)->default('COMPANY');
            $table->timestamp('created_at')->nullable();
            $table->index(['permission_scope', 'permission_code']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('feature_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_code', 100)->unique();
            $table->string('feature_name', 150);
            $table->string('feature_type', 20)->default('BOOLEAN');
            $table->string('module_name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_name', 150);
            $table->string('plan_code', 50)->unique();
            $table->decimal('monthly_price', 15, 3)->default(0);
            $table->decimal('yearly_price', 15, 3)->nullable();
            $table->integer('max_branches')->default(1);
            $table->integer('max_users')->default(2);
            $table->integer('max_cars')->nullable();
            $table->integer('max_invoices')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->string('feature_code', 100);
            $table->boolean('is_enabled')->nullable();
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'feature_code']);
            $table->index('feature_code');
        });
    }

    public function test_system_baseline_is_complete_idempotent_and_contains_no_tenant_data(): void
    {
        $this->seed(SystemBaselineSeeder::class);
        $this->seed(SystemBaselineSeeder::class);

        self::assertSame(8, DB::table('roles')->count());
        self::assertSame(109, DB::table('permissions')->count());
        self::assertSame(231, DB::table('role_permissions')->count());
        self::assertSame(231, DB::table('role_permissions')->whereNull('company_id')->count());
        self::assertSame(0, DB::table('role_permissions')->whereNotNull('company_id')->count());
        self::assertSame(19, DB::table('feature_catalog')->count());
        self::assertSame(3, DB::table('plans')->count());
        self::assertSame(54, DB::table('plan_features')->count());
        self::assertSame(0, DB::table('plan_features as pf')
            ->leftJoin('plans as p', 'p.id', '=', 'pf.plan_id')
            ->whereNull('p.id')
            ->count());
        self::assertSame(0, DB::table('plan_features as pf')
            ->leftJoin('feature_catalog as fc', 'fc.feature_code', '=', 'pf.feature_code')
            ->whereNull('fc.id')
            ->count());

        self::assertFalse(Schema::hasTable('companies'));
        self::assertFalse(Schema::hasTable('users'));
        self::assertFalse(Schema::hasTable('user_roles'));
        self::assertFalse(Schema::hasTable('user_permission_overrides'));
    }

    public function test_plan_limits_match_the_approved_system_baseline(): void
    {
        $this->seed(SystemBaselineSeeder::class);

        $plans = DB::table('plans')->get()->keyBy('plan_code');

        self::assertSame(99.0, (float) $plans['STARTER']->monthly_price);
        self::assertSame(1, (int) $plans['STARTER']->max_branches);
        self::assertSame(2, (int) $plans['STARTER']->max_users);
        self::assertSame(100, (int) $plans['STARTER']->max_cars);
        self::assertSame(300, (int) $plans['STARTER']->max_invoices);

        self::assertSame(299.0, (float) $plans['PRO']->monthly_price);
        self::assertSame(2990.0, (float) $plans['PRO']->yearly_price);
        self::assertSame(3, (int) $plans['PRO']->max_branches);
        self::assertSame(10, (int) $plans['PRO']->max_users);
        self::assertSame(1000, (int) $plans['PRO']->max_cars);
        self::assertSame(3000, (int) $plans['PRO']->max_invoices);

        self::assertSame(799.0, (float) $plans['ENTERPRISE']->monthly_price);
        self::assertSame(999, (int) $plans['ENTERPRISE']->max_branches);
        self::assertSame(999, (int) $plans['ENTERPRISE']->max_users);
        self::assertNull($plans['ENTERPRISE']->max_cars);
        self::assertNull($plans['ENTERPRISE']->max_invoices);
    }
}
