<?php

namespace Tests\Feature\PhaseB;

use App\Http\Controllers\Api\CompanySettingController;
use App\Services\Accounting\AccountingContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
            foreach (['logo_path','signature_path','stamp_path','header_image_path','footer_image_path'] as $column) $table->string($column,500)->nullable();
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

    public function test_logo_header_and_footer_persist_on_private_tenant_path(): void
    {
        foreach (['logo','header_image','footer_image'] as $type) {
            $response=$this->controller()->upload($this->uploadRequest(1,$type,$this->png()),app(AccountingContext::class));
            self::assertSame(200,$response->getStatusCode());
            $column=$type.'_path'; $path=(string)DB::table('company_settings')->where('company_id',1)->value($column);
            self::assertStringStartsWith('print-branding/1/',$path); self::assertTrue(Storage::disk('local')->exists($path));
        }
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

    private function controller(): CompanySettingController { return app(CompanySettingController::class); }
    private function request(int $company): Request {$request=Request::create('/api/company-settings','GET');$request->attributes->set('tenant_company_id',$company);return $request;}
    private function uploadRequest(int $company,string $type,UploadedFile $file): Request {$request=Request::create('/api/company-settings/upload','POST',['type'=>$type]);$request->attributes->set('tenant_company_id',$company);$request->files->set('file',$file);return $request;}
    private function png(): UploadedFile {return UploadedFile::fake()->createWithContent('brand.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));}
}
