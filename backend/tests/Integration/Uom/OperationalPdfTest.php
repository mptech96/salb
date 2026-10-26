<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;

/** Real Chromium export; no mocked renderer, no business database. */
final class OperationalPdfTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();
        (require base_path('database/migrations/2026_08_31_000026_add_print_branding_profile_to_company_settings.php'))->up();
        (require base_path('database/migrations/2026_09_21_000027_create_central_print_documents.php'))->up();
    }
    protected function tearDown():void
    {DB::disconnect();parent::tearDown();gc_collect_cycles();}

    public function test_report_failures_do_not_expose_sql_or_connection_details():void
    {
        DB::unprepared('RENAME TABLE inventory_lots TO qa_missing_inventory_lots');
        $controller=app(\App\Http\Controllers\Api\AdvancedReportController::class);
        $request=\Illuminate\Http\Request::create('/api/reports/export/inventory','GET',['format'=>'pdf']);
        $request->attributes->set('tenant_company_id',1);$request->attributes->set('tenant_branch_id',1);
        foreach(['run','export'] as $method){
            $response=$controller->$method($request,'inventory',app(\App\Services\ReportCenterService::class),app(\App\Services\Accounting\AccountingContext::class));
            self::assertSame(422,$response->getStatusCode());self::assertStringNotContainsString('SQLSTATE',$response->getContent());
            self::assertStringNotContainsString(DB::connection()->getDatabaseName(),$response->getContent());
            self::assertStringNotContainsString('select ',$response->getContent());
        }
    }

    public function test_actual_inventory_export_returns_pdf_bytes_and_saved_file_is_identical():void
    {
        $service=app(EnterpriseInvoiceService::class);
        $id=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'CARTON','quantity'=>'1','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
        $service->post('PURCHASE',1,$id,1,1);
        $request=\Illuminate\Http\Request::create('/api/advanced-reports/inventory/export','GET',['format'=>'pdf']);
        $request->attributes->set('tenant_company_id',1);$request->attributes->set('tenant_branch_id',1);
        $response=app(\App\Http\Controllers\Api\AdvancedReportController::class)->export($request,'inventory',app(\App\Services\ReportCenterService::class),app(\App\Services\Accounting\AccountingContext::class));
        self::assertSame(200,$response->getStatusCode(),$response->getContent());
        self::assertSame('application/pdf',$response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;',$response->headers->get('Content-Disposition'));
        $bytes=$response->getContent();self::assertStringStartsWith('%PDF-',$bytes);self::assertGreaterThan(1000,strlen($bytes));
        $path=storage_path('framework/testing/resume-uom-inventory.pdf');
        self::assertSame(strlen($bytes),file_put_contents($path,$bytes,LOCK_EX));
        self::assertSame(hash('sha256',$bytes),hash_file('sha256',$path));
        self::assertSame(0,DB::transactionLevel());
    }
}
