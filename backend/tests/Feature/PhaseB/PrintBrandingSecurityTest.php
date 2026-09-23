<?php

namespace Tests\Feature\PhaseB;

use App\Http\Controllers\Api\CompanySettingController;
use App\Services\Accounting\AccountingContext;
use App\Http\Controllers\Api\AdvancedReportController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Services\Print\ChromiumPdfRenderer;
use Tests\TestCase;

class PrintBrandingSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['entity_addresses','company_currencies','currencies','companies'] as $table) Schema::dropIfExists($table);
        Schema::dropIfExists('company_settings');
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id')->unique();
            foreach (['logo_path','signature_path','stamp_path','header_image_path','footer_image_path','watermark_path'] as $column) $table->string($column,500)->nullable();
            $table->string('print_company_name')->nullable(); $table->string('currency_name')->nullable();
            $table->string('currency_code',10)->nullable(); $table->string('base_currency_code',10)->nullable();
            $table->unsignedTinyInteger('currency_decimal_places')->default(3);
            $table->string('primary_color')->nullable(); $table->string('secondary_color')->nullable();
            $table->json('print_header_texts')->nullable(); $table->json('print_footer_texts')->nullable(); $table->json('print_options')->nullable();
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table): void {$table->id();$table->string('company_name');});
        Schema::create('entity_addresses', function (Blueprint $table): void {
            $table->id();$table->unsignedBigInteger('company_id');$table->string('entity_type');$table->unsignedBigInteger('entity_id');
            $table->boolean('is_default')->default(true);$table->boolean('is_active')->default(true);
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();$table->string('currency_code',10)->unique();$table->string('currency_name');
            $table->string('symbol')->nullable();$table->unsignedTinyInteger('decimal_places')->default(2);$table->boolean('is_active')->default(true);$table->timestamps();
        });
        Schema::create('company_currencies', function (Blueprint $table): void {
            $table->id();$table->unsignedBigInteger('company_id');$table->string('currency_code',10);
            $table->boolean('is_base')->default(false);$table->boolean('is_active')->default(true);$table->timestamps();
        });
        DB::table('companies')->insert([['id'=>1,'company_name'=>'Company One'],['id'=>2,'company_name'=>'Company Two'],['id'=>3,'company_name'=>'Company Three']]);
        DB::table('company_settings')->insert([
            ['company_id'=>1,'print_company_name'=>'Company One','currency_name'=>'Saudi Riyal','currency_code'=>'SAR','base_currency_code'=>'SAR'],
            ['company_id'=>2,'print_company_name'=>'Company Two','currency_name'=>'Must Not Replace Master','currency_code'=>'SAR','base_currency_code'=>'SAR'],
        ]);
        DB::table('currencies')->insert(['currency_code'=>'SAR','currency_name'=>'Canonical SAR','decimal_places'=>2,'is_active'=>1]);
        DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR','is_base'=>1,'is_active'=>1]);
        Storage::fake('local'); Storage::fake('public');
    }

    public function test_routes_are_registered_including_authenticated_asset_delivery(): void
    {
        $routes=collect(Route::getRoutes())->map(fn($route)=>[$route->uri(),$route->methods()]);
        foreach ([['api/company-settings/assets/{type}','GET'],['api/company-settings/assets/{type}','DELETE'],['api/company-settings/upload','POST']] as [$uri,$method]) {
            self::assertTrue($routes->contains(fn($route)=>$route[0]===$uri&&in_array($method,$route[1],true)));
        }
    }

    public function test_every_branding_asset_persists_on_private_tenant_path_and_is_not_readable_by_another_company(): void
    {
        foreach (['logo','header_image','footer_image','signature','stamp','watermark'] as $type) {
            $response=$this->controller()->upload($this->uploadRequest(1,$type,$this->png()),app(AccountingContext::class));
            self::assertSame(200,$response->getStatusCode());
            $column=$type.'_path'; $path=(string)DB::table('company_settings')->where('company_id',1)->value($column);
            self::assertStringStartsWith('print-branding/1/',$path); self::assertTrue(Storage::disk('local')->exists($path));
            self::assertSame(200,$this->controller()->asset($this->request(1),$type,app(AccountingContext::class))->getStatusCode());
            try {$this->controller()->asset($this->request(2),$type,app(AccountingContext::class));self::fail('Cross-company asset exposed: '.$type);}
            catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {}
        }
    }

    public function test_client_supplied_company_id_cannot_redirect_an_upload_to_another_tenant(): void
    {
        $request=$this->uploadRequest(1,'signature',$this->png());
        $request->request->set('company_id',2);
        $this->controller()->upload($request,app(AccountingContext::class));
        self::assertStringStartsWith('print-branding/1/',(string)DB::table('company_settings')->where('company_id',1)->value('signature_path'));
        self::assertNull(DB::table('company_settings')->where('company_id',2)->value('signature_path'));
    }

    public function test_supported_raster_encodings_are_verified_by_content_and_static_gif_bmp_become_png(): void
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD is needed to create raster test images.');
        $image=imagecreatetruecolor(8,8);
        imagefilledrectangle($image,0,0,7,7,imagecolorallocate($image,25,70,120));
        try {
            foreach (['png'=>'png','jpeg'=>'jpg','webp'=>'webp','gif'=>'png','bmp'=>'png'] as $format=>$expectedExtension) {
                $encoder='image'.$format;
                if (!function_exists($encoder)) $this->markTestSkipped($format.' encoder is missing from local GD.');
                ob_start(); $encoder($image); $bytes=ob_get_clean();
                self::assertIsString($bytes);
                $file=UploadedFile::fake()->createWithContent('uat.'.$format,$bytes);
                $result=$this->controller()->upload($this->uploadRequest(1,'watermark',$file),app(AccountingContext::class));
                self::assertSame(200,$result->getStatusCode());
                $path=(string)DB::table('company_settings')->where('company_id',1)->value('watermark_path');
                self::assertStringEndsWith('.'.$expectedExtension,$path);
                self::assertSame('image/'.($expectedExtension === 'jpg' ? 'jpeg' : $expectedExtension),getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
            }
        } finally {imagedestroy($image);}
    }


    public function test_image_content_is_validated_and_invalid_replacement_does_not_change_branding(): void
    {
        $controller=$this->controller();$context=app(AccountingContext::class);
        $controller->upload($this->uploadRequest(1,'logo',$this->png()),$context);
        $old=DB::table('company_settings')->where('company_id',1)->value('logo_path');
        foreach ([UploadedFile::fake()->create('fake.png',1,'image/png'),UploadedFile::fake()->create('huge.png',2100,'image/png')] as $file) {
            try {$controller->upload($this->uploadRequest(1,'logo',$file),$context);self::fail('Invalid image accepted');}
            catch (ValidationException) {self::assertSame($old,DB::table('company_settings')->where('company_id',1)->value('logo_path'));}
        }
        self::assertTrue(Storage::disk('local')->exists($old));
    }

    public function test_replacement_is_atomic_and_invalid_replacement_preserves_current_asset(): void
    {
        $controller=$this->controller();$context=app(AccountingContext::class);
        $controller->upload($this->uploadRequest(1,'logo',$this->png()),$context);
        $old=(string)DB::table('company_settings')->where('company_id',1)->value('logo_path');
        try {$controller->upload($this->uploadRequest(1,'logo',UploadedFile::fake()->create('bad.png',2,'text/plain')),$context);self::fail('Invalid file accepted');} catch (ValidationException) {}
        self::assertSame($old,DB::table('company_settings')->where('company_id',1)->value('logo_path'));self::assertTrue(Storage::disk('local')->exists($old));
        $controller->upload($this->uploadRequest(1,'logo',$this->png()),$context);$new=(string)DB::table('company_settings')->where('company_id',1)->value('logo_path');
        self::assertNotSame($old,$new);self::assertFalse(Storage::disk('local')->exists($old));self::assertTrue(Storage::disk('local')->exists($new));
    }

    public function test_asset_access_and_removal_are_bound_to_context_company(): void
    {
        $controller=$this->controller();$context=app(AccountingContext::class);$controller->upload($this->uploadRequest(1,'logo',$this->png()),$context);
        self::assertSame(200,$controller->asset($this->request(1),'logo',$context)->getStatusCode());
        try {$controller->asset($this->request(2),'logo',$context);self::fail('Cross-company asset exposed');} catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {}
        $controller->removeAsset($this->request(1),'logo',$context);self::assertNull(DB::table('company_settings')->where('company_id',1)->value('logo_path'));
    }

    public function test_get_settings_is_read_only_for_currency_master_and_company_currency_state(): void
    {
        $currenciesBefore=DB::table('currencies')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $companyCurrenciesBefore=DB::table('company_currencies')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();

        $response=$this->controller()->show($this->request(2),app(AccountingContext::class),app(\App\Services\EntityAddressService::class));

        self::assertSame(200,$response->getStatusCode());
        self::assertSame('Company Two',$response->getData(true)['data']['print_company_name']);
        self::assertSame($currenciesBefore,DB::table('currencies')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
        self::assertSame($companyCurrenciesBefore,DB::table('company_currencies')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
        self::assertFalse(DB::table('company_currencies')->where('company_id',2)->exists());
        self::assertSame('Canonical SAR',DB::table('currencies')->where('currency_code','SAR')->value('currency_name'));
    }

    public function test_get_missing_settings_reports_missing_without_creating_configuration(): void
    {
        $response=$this->controller()->show($this->request(3),app(AccountingContext::class),app(\App\Services\EntityAddressService::class));
        self::assertSame(200,$response->getStatusCode());
        self::assertSame('MISSING',$response->getData(true)['data']['currency_configuration_status']);
        self::assertFalse(DB::table('company_settings')->where('company_id',3)->exists());
        self::assertFalse(DB::table('company_currencies')->where('company_id',3)->exists());
    }

    public function test_private_branding_asset_can_be_embedded_without_exposing_its_storage_path(): void
    {
        $path='print-branding/1/header-image.png';
        Storage::disk('local')->put($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        $method=new \ReflectionMethod(AdvancedReportController::class,'brandingDataUri');
        $uri=$method->invoke(app(AdvancedReportController::class),$path);
        self::assertIsString($uri);
        self::assertStringStartsWith('data:image/png;base64,',$uri);
        self::assertStringNotContainsString($path,$uri);
    }

    public function test_server_report_pdf_renders_test_branding_without_enabling_unselected_approval_assets(): void
    {
        $png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=';
        $profile=[
            'company_name'=>'شركة اختبار', 'branch_name'=>'فرع الاختبار', 'print_locale'=>'ar',
            'print_header_texts'=>['ar'=>'ترويسة اختبار'], 'print_footer_texts'=>['ar'=>'تذييل اختبار'], 'report_footer'=>'',
            'logo_data_uri'=>$png, 'header_image_data_uri'=>$png, 'footer_image_data_uri'=>$png,
            'signature_data_uri'=>$png, 'stamp_data_uri'=>$png, 'watermark_data_uri'=>$png,
            'print_options'=>['templates'=>['report'=>['selected'=>'CLASSIC','visibility'=>['signature'=>false,'stamp'=>false]]],
                'watermark'=>['enabled'=>true,'mode'=>'TEXT','text'=>'اختبار','opacity'=>.12,'pages'=>'ALL']],
        ];
        $data=['title'=>'تقرير محاسبي عربي — Accounting Report','generated_at'=>'2026-09-21','columns'=>[['key'=>'name','label'=>'البيان']],
            'rows'=>array_fill(0,45,['name'=>'فقرة عربية متصلة مع English INV-2026-001 والتاريخ 2026-09-21 والمبلغ 12,345.67 ريال']), 'summary'=>['total'=>'12,345.67']];
        $method=new \ReflectionMethod(AdvancedReportController::class,'reportHtml');
        $html=$method->invoke(app(AdvancedReportController::class),$data,$profile,[]);
        self::assertStringContainsString('اختبار',$html);
        self::assertSame(3,substr_count($html,$png)); // logo, header and footer; not the disabled approval assets
        $pdf=app(ChromiumPdfRenderer::class)->render($html,'portrait');
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000,strlen($pdf));
        if (getenv('SULB_PRINT_QA_PDF') === '1') {
            $directory=storage_path('framework/testing');
            if (!is_dir($directory)) mkdir($directory,0770,true);
            file_put_contents($directory.'/print-qa-report.pdf',$pdf);
        }
    }

    public function test_document_and_report_print_paths_use_the_shared_authenticated_branding_components(): void
    {
        $header=file_get_contents(base_path('../frontend/components/reports/PrintHeader.tsx'));
        $footer=file_get_contents(base_path('../frontend/components/reports/PrintFooter.tsx'));
        $document=file_get_contents(base_path('../frontend/app/print/[type]/[id]/page.tsx'));
        $reports=file_get_contents(base_path('../frontend/app/reports/page.tsx'));
        $salarySlip=file_get_contents(base_path('../frontend/app/payroll/[runId]/salary-slip/[workerId]/page.tsx'));
        $officialDocuments=file_get_contents(base_path('../frontend/app/official-documents/page.tsx'));
        $roadWaybill=file_get_contents(base_path('../frontend/app/road-waybills/page.tsx'));
        $approvalBoxes=file_get_contents(base_path('../frontend/components/print/PrintApprovalBoxes.tsx'));
        foreach (['header_image','logo','signature','stamp'] as $asset) self::assertStringContainsString("asset=\"{$asset}\"",$header);
        self::assertStringContainsString('print_header_texts',$header);
        self::assertStringContainsString('footer_image',$footer);
        self::assertStringContainsString('print_footer_texts',$footer);
        self::assertStringContainsString('printWhenReady',$document);
        self::assertStringContainsString('<PrintFooter profile={printProfile}', $document);
        self::assertStringContainsString('<PrintFooter profile={data.print_profile}', $reports);
        self::assertStringContainsString('<PrintHeader profile={printProfile}', $salarySlip);
        self::assertStringContainsString('<PrintFooter profile={printProfile}', $salarySlip);
        self::assertStringContainsString('printWhenReady', $salarySlip);
        self::assertStringContainsString('/company-settings/assets/${asset}', $officialDocuments);
        self::assertStringContainsString('waitForPrintWindow', $officialDocuments);
        self::assertStringContainsString('<PrintFooter profile={profile} family="road"', $roadWaybill);
        self::assertStringContainsString('break-inside-avoid', $approvalBoxes);
        self::assertStringContainsString('scopeKey={profile?.company_id}', $approvalBoxes);
    }

    private function controller(): CompanySettingController { return app(CompanySettingController::class); }
    private function request(int $company): Request {$request=Request::create('/api/company-settings','GET');$request->attributes->set('tenant_company_id',$company);return $request;}
    private function uploadRequest(int $company,string $type,UploadedFile $file): Request {$request=Request::create('/api/company-settings/upload','POST',['type'=>$type]);$request->attributes->set('tenant_company_id',$company);$request->files->set('file',$file);return $request;}
    private function png(): UploadedFile {return UploadedFile::fake()->createWithContent('brand.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));}
}
