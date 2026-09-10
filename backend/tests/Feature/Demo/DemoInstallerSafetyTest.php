<?php
declare(strict_types=1);
namespace Tests\Feature\Demo;

use App\Console\Commands\SulbDemoInstallCommand;
use App\Services\Demo\DemoCompanyInstaller;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class DemoInstallerSafetyTest extends TestCase
{
    public function test_commands_are_manual_and_registered_but_not_seeded_or_scheduled(): void
    {
        self::assertArrayHasKey('sulb:demo-install',Artisan::all());self::assertArrayHasKey('sulb:demo-status',Artisan::all());
        $database=file_get_contents(database_path('seeders/DatabaseSeeder.php'));$system=file_get_contents(database_path('seeders/SystemBaselineSeeder.php'));$console=file_get_contents(base_path('routes/console.php'));
        self::assertStringNotContainsString('DemoCompanyInstaller',$database);self::assertStringNotContainsString('DemoCompanyInstaller',$system);self::assertStringNotContainsString("Schedule::command('sulb:demo-install",$console);
    }

    public function test_production_requires_the_exact_explicit_acknowledgement(): void
    {
        self::assertFalse(SulbDemoInstallCommand::allowsEnvironment('production',null));self::assertFalse(SulbDemoInstallCommand::allowsEnvironment('production','YES'));self::assertTrue(SulbDemoInstallCommand::allowsEnvironment('production','INSTALL_SULB_DEMO'));self::assertTrue(SulbDemoInstallCommand::allowsEnvironment('local',null));
    }

    public function test_installer_has_a_deterministic_marker_and_uses_canonical_workflows(): void
    {
        self::assertSame('SULB_DEMO_INSTALL_V1',DemoCompanyInstaller::MARKER);self::assertSame('demo',DemoCompanyInstaller::USERNAME);$source=file_get_contents(app_path('Services/Demo/DemoCompanyInstaller.php'));
        foreach(['CompanyProvisioningService','EnterpriseInvoiceService','CommercialDocumentService','ExpensePosting','VoucherPosting','JournalService','DemoIntegrityService','DB::transaction']as$dependency)self::assertStringContainsString($dependency,$source);
        foreach(['truncate(', 'migrate:fresh', 'SUPER_ADMIN']as$unsafe)self::assertStringNotContainsString($unsafe,$source);
        self::assertStringContainsString("where('idempotency_key',self::MARKER)",$source);self::assertStringContainsString('where(\'company_id\',$cid)',$source);
    }

    public function test_status_command_is_read_only(): void
    {
        $source=file_get_contents(app_path('Console/Commands/SulbDemoStatusCommand.php'));foreach(['insert(','insertGetId(','update(','delete(','truncate(']as$mutation)self::assertStringNotContainsString($mutation,$source);
    }
}
