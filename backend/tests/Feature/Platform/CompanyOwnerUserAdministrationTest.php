<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Wave1SubscriptionTestCase;

class CompanyOwnerUserAdministrationTest extends Wave1SubscriptionTestCase
{
    private array $owner;

    private int $employeeRoleId;

    private int $branchManagerRoleId;

    private int $superAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('user_roles', function (Blueprint $table): void {
            $table->timestamps();
        });

        Schema::table('user_permission_overrides', function (Blueprint $table): void {
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
        });

        $this->owner = $this->companyUserWithSubscription('ACTIVE');

        DB::table('roles')->where('id', $this->owner['roleId'])->update([
            'role_name' => 'Company owner',
            'role_code' => 'COMPANY_OWNER',
        ]);

        $this->employeeRoleId = $this->createRole('EMPLOYEE');
        $this->branchManagerRoleId = $this->createRole('BRANCH_MANAGER');
        $this->superAdminRoleId = $this->createRole('SUPER_ADMIN');

        Sanctum::actingAs(User::findOrFail($this->owner['userId']), ['session']);
    }

    public function test_company_owner_can_list_only_users_in_its_company(): void
    {
        $foreign = $this->createForeignUser();

        $response = $this->getJson('/api/users')->assertOk();
        $userIds = array_map('intval', array_column($response->json('data'), 'id'));

        self::assertContains($this->owner['userId'], $userIds);
        self::assertNotContains($foreign['userId'], $userIds);
    }

    public function test_company_owner_cannot_switch_user_listing_to_another_company(): void
    {
        $foreign = $this->createForeignUser();

        $response = $this->withHeader('X-Company-ID', (string) $foreign['companyId'])
            ->getJson('/api/users')
            ->assertOk();

        foreach ($response->json('data') as $user) {
            self::assertSame($this->owner['companyId'], (int) $user['company_id']);
        }
    }

    public function test_company_owner_can_create_and_edit_a_company_user(): void
    {
        $created = $this->postJson('/api/users', $this->payload('owned-user'))
            ->assertCreated();

        $userId = (int) $created->json('id');

        self::assertDatabaseHas('users', [
            'id' => $userId,
            'company_id' => $this->owner['companyId'],
            'branch_id' => $this->owner['branchId'],
        ]);

        $this->putJson('/api/users/'.$userId, [
            ...$this->payload('owned-user-edited'),
            'name' => 'Edited Employee',
        ])->assertOk();

        self::assertDatabaseHas('users', [
            'id' => $userId,
            'company_id' => $this->owner['companyId'],
            'username' => 'owned-user-edited',
        ]);
    }

    public function test_company_owner_cannot_create_or_assign_super_admin(): void
    {
        $this->postJson('/api/users', [
            ...$this->payload('forbidden-platform-user'),
            'role_id' => $this->superAdminRoleId,
        ])->assertForbidden();

        self::assertDatabaseMissing('users', ['username' => 'forbidden-platform-user']);
    }

    public function test_company_owner_cannot_assign_platform_permissions(): void
    {
        $companyPermissionId = DB::table('permissions')->insertGetId([
            'permission_code' => 'users.view',
            'permission_scope' => 'COMPANY',
        ]);

        $platformPermissionId = DB::table('permissions')->insertGetId([
            'permission_code' => 'platform.audit.read',
            'permission_scope' => 'PLATFORM',
        ]);

        $userId = (int) $this->postJson('/api/users', $this->payload('permission-target'))
            ->assertCreated()
            ->json('id');

        $this->putJson('/api/permission-matrix/users/'.$userId, [
            'overrides' => [
                'users.view' => 'ALLOW',
                'platform.audit.read' => 'ALLOW',
            ],
        ])->assertOk();

        self::assertDatabaseHas('user_permission_overrides', [
            'company_id' => $this->owner['companyId'],
            'user_id' => $userId,
            'permission_id' => $companyPermissionId,
        ]);

        self::assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $userId,
            'permission_id' => $platformPermissionId,
        ]);
    }

    public function test_company_owner_can_assign_branch_manager_to_a_valid_company_branch(): void
    {
        $response = $this->postJson('/api/users', [
            ...$this->payload('branch-manager-user'),
            'role_id' => $this->branchManagerRoleId,
        ])->assertCreated();

        self::assertDatabaseHas('user_roles', [
            'company_id' => $this->owner['companyId'],
            'user_id' => (int) $response->json('id'),
            'role_id' => $this->branchManagerRoleId,
            'is_active' => 1,
        ]);
    }

    public function test_company_owner_cannot_assign_a_branch_from_another_company(): void
    {
        $foreign = $this->createForeignUser();

        $this->postJson('/api/users', [
            ...$this->payload('foreign-branch-user'),
            'branch_id' => $foreign['branchId'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

        self::assertDatabaseMissing('users', ['username' => 'foreign-branch-user']);
    }

    public function test_client_supplied_company_id_cannot_widen_owner_tenant_scope(): void
    {
        $foreign = $this->createForeignUser();

        $response = $this->postJson('/api/users', [
            ...$this->payload('server-scoped-user'),
            'company_id' => $foreign['companyId'],
        ])->assertCreated();

        self::assertDatabaseHas('users', [
            'id' => (int) $response->json('id'),
            'company_id' => $this->owner['companyId'],
            'branch_id' => $this->owner['branchId'],
        ]);
    }

    public function test_company_owner_cannot_edit_a_user_from_another_company(): void
    {
        $foreign = $this->createForeignUser();

        $this->putJson('/api/users/'.$foreign['userId'], [
            ...$this->payload('foreign-user-takeover'),
            'company_id' => $foreign['companyId'],
        ])->assertNotFound();

        self::assertDatabaseHas('users', [
            'id' => $foreign['userId'],
            'company_id' => $foreign['companyId'],
            'username' => 'foreign-manager',
        ]);
    }

    public function test_existing_company_admin_and_manager_user_management_is_preserved(): void
    {
        foreach (['COMPANY_ADMIN', 'MANAGER'] as $roleCode) {
            DB::table('roles')->where('id', $this->owner['roleId'])->update([
                'role_code' => $roleCode,
            ]);

            $this->getJson('/api/users')->assertOk();
        }
    }

    public function test_company_owner_cannot_access_platform_admin_routes(): void
    {
        $this->getJson('/api/system-admin/features')->assertForbidden();
    }

    private function payload(string $username): array
    {
        return [
            'company_id' => $this->owner['companyId'],
            'branch_id' => $this->owner['branchId'],
            'role_id' => $this->employeeRoleId,
            'name' => 'Company Employee',
            'username' => $username,
            'password' => 'test-password',
            'is_active' => 1,
        ];
    }

    private function createRole(string $roleCode): int
    {
        return DB::table('roles')->insertGetId([
            'role_name' => $roleCode,
            'role_code' => $roleCode,
            'is_active' => 1,
        ]);
    }

    private function createForeignUser(): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'company_name' => 'Foreign tenant',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'company_id' => $companyId,
            'branch_name' => 'Foreign branch',
            'is_active' => 1,
        ]);

        $userId = DB::table('users')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => 'Foreign Manager',
            'username' => 'foreign-manager',
            'password' => Hash::make('test-password'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('companyId', 'branchId', 'userId');
    }
}
