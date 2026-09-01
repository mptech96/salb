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
        Schema::dropIfExists('company_settings');
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('company_id')->unique();
            foreach (['logo_path','signature_path','stamp_path','header_image_path','footer_image_path'] as $column) $table->string($column,500)->nullable();
            $table->json('print_header_texts')->nullable(); $table->json('print_footer_texts')->nullable(); $table->json('print_options')->nullable();
            $table->timestamps();
        });
        DB::table('company_settings')->insert([['company_id'=>1],['company_id'=>2]]);
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

    private function controller(): CompanySettingController { return app(CompanySettingController::class); }
    private function request(int $company): Request {$request=Request::create('/api/company-settings','GET');$request->attributes->set('tenant_company_id',$company);return $request;}
    private function uploadRequest(int $company,string $type,UploadedFile $file): Request {$request=Request::create('/api/company-settings/upload','POST',['type'=>$type]);$request->attributes->set('tenant_company_id',$company);$request->files->set('file',$file);return $request;}
    private function png(): UploadedFile {return UploadedFile::fake()->createWithContent('brand.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));}
}
