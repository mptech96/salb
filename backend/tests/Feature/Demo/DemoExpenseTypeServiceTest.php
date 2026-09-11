<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Services\Demo\DemoExpenseTypeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class DemoExpenseTypeServiceTest extends TestCase
{
    private const GLOBAL_CODES = [
        'CAR_RENT', 'DRIVER_TRIP', 'WORKERS', 'BUY_COMMISSION',
        'SALE_COMMISSION', 'GENERAL', 'WEIGHT_DIFF', 'OTHER',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());

        foreach (['expense_types', 'accounting_settings', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('account_code');
            $table->string('account_name');
            $table->string('account_type');
            $table->boolean('is_group')->default(false);
            $table->boolean('allow_posting')->default(true);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('accounting_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('setting_key');
            $table->unsignedBigInteger('account_id');
            $table->unique(['company_id', 'setting_key']);
        });
        Schema::create('expense_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('type_name', 150);
            $table->string('type_code', 50)->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('default_scope', 50)->default('GENERAL');
            $table->boolean('affects_cost')->default(true);
            $table->string('usage_type')->default('GENERAL');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::GLOBAL_CODES as $code) {
            DB::table('expense_types')->insert([
                'company_id' => null,
                'type_name' => $code,
                'type_code' => $code,
                'account_id' => null,
                'default_scope' => 'GENERAL',
                'affects_cost' => 1,
                'usage_type' => 'GENERAL',
                'is_active' => 1,
            ]);
        }
    }

    public function test_it_creates_idempotent_company_overrides_without_mutating_the_global_dictionary_or_other_tenants(): void
    {
        $this->seedAccounting(10);
        $otherAccount = $this->account(20, '6900', 'EXPENSE');
        DB::table('expense_types')->insert([
            'company_id' => 20, 'type_name' => 'Existing tenant data', 'type_code' => 'TENANT_ONLY',
            'account_id' => $otherAccount, 'is_active' => 1,
        ]);
        $globalBefore = DB::table('expense_types')->whereNull('company_id')->orderBy('id')->get()->toArray();
        $otherBefore = DB::table('expense_types')->where('company_id', 20)->first();

        $service = app(DemoExpenseTypeService::class);
        $first = $service->ensure(10);
        $second = $service->ensure(10);

        self::assertCount(8, $first);
        self::assertSame($first, $second);
        self::assertSame(8, DB::table('expense_types')->where('company_id', 10)->count());
        self::assertEquals($globalBefore, DB::table('expense_types')->whereNull('company_id')->orderBy('id')->get()->toArray());
        self::assertEquals($otherBefore, DB::table('expense_types')->where('company_id', 20)->first());
        self::assertSame(0, DB::table('expense_types')->whereNull('company_id')->whereNotNull('account_id')->count());

        $invalid = DB::table('expense_types as t')
            ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
            ->where('t.company_id', 10)
            ->where(function ($query): void {
                $query->whereNull('a.id')->orWhere('a.company_id', '<>', 10)->orWhere('a.account_type', '<>', 'EXPENSE')
                    ->orWhere('a.is_active', '<>', 1)->orWhere('a.is_group', '<>', 0)->orWhere('a.allow_posting', '<>', 1);
            })->count();
        self::assertSame(0, $invalid);
    }

    public function test_a_cross_company_or_invalid_setting_aborts_without_partial_expense_type_writes(): void
    {
        $general = $this->account(10, '6900', 'EXPENSE');
        $foreign = $this->account(20, '6300', 'EXPENSE');
        $this->setting(10, 'GENERAL_EXPENSE_ACCOUNT', $general);
        $this->setting(10, 'TRANSPORT_EXPENSE_ACCOUNT', $foreign);

        try {
            app(DemoExpenseTypeService::class)->ensure(10);
            self::fail('The cross-company setting should have been rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not reference a valid postable company expense account', $exception->getMessage());
        }

        self::assertSame(0, DB::table('expense_types')->where('company_id', 10)->count());
        self::assertSame(8, DB::table('expense_types')->whereNull('company_id')->count());
    }

    private function seedAccounting(int $companyId): void
    {
        foreach ([
            'GENERAL_EXPENSE_ACCOUNT' => '6900',
            'TRANSPORT_EXPENSE_ACCOUNT' => '6300',
            'SALARY_EXPENSE_ACCOUNT' => '6100',
            'INVENTORY_ADJUSTMENT_ACCOUNT' => '5500',
        ] as $key => $code) {
            $this->setting($companyId, $key, $this->account($companyId, $code, 'EXPENSE'));
        }
    }

    private function account(int $companyId, string $code, string $type): int
    {
        return DB::table('accounts')->insertGetId([
            'company_id' => $companyId, 'account_code' => $code, 'account_name' => $code,
            'account_type' => $type, 'is_group' => 0, 'allow_posting' => 1, 'is_active' => 1,
        ]);
    }

    private function setting(int $companyId, string $key, int $accountId): void
    {
        DB::table('accounting_settings')->insert([
            'company_id' => $companyId, 'setting_key' => $key, 'account_id' => $accountId,
        ]);
    }
}
