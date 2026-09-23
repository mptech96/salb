<?php

namespace Tests\Feature\Print;

use App\Http\Controllers\Api\RoadWaybillController;
use App\Services\Accounting\AccountingContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class RoadWaybillScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['road_waybills','branches','drivers','cars','shipments'] as $name) Schema::dropIfExists($name);
        Schema::create('branches', function (Blueprint $t) {$t->id();$t->unsignedBigInteger('company_id');$t->string('branch_name');$t->boolean('is_active')->default(true);});
        foreach (['drivers','cars','shipments'] as $name) Schema::create($name, function (Blueprint $t) use ($name) {
            $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();
            $t->string(match($name){'drivers'=>'driver_name','cars'=>'plate_number',default=>'shipment_number'});
        });
        Schema::create('road_waybills', function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id');$t->unsignedBigInteger('created_by')->nullable();
            $t->string('document_number')->nullable();$t->date('document_date');$t->date('valid_until')->nullable();$t->string('status');
            foreach (['driver_id','car_id','shipment_id'] as $field) $t->unsignedBigInteger($field)->nullable();
            foreach (['driver_name','nationality','identity_number','vehicle_type','plate_number','material_owner','origin_city','destination_city','phone','notes','template_key','lines_json','visibility_json'] as $field) $t->text($field)->nullable();
            $t->timestamps();
        });
        DB::table('branches')->insert([['id'=>1,'company_id'=>1,'branch_name'=>'A'],['id'=>2,'company_id'=>1,'branch_name'=>'B'],['id'=>3,'company_id'=>2,'branch_name'=>'C']]);
        DB::table('drivers')->insert([['id'=>1,'company_id'=>1,'branch_id'=>1,'driver_name'=>'A'],['id'=>2,'company_id'=>1,'branch_id'=>2,'driver_name'=>'B'],['id'=>3,'company_id'=>2,'branch_id'=>3,'driver_name'=>'C']]);
    }

    private function request(int $company, int $branch, array $payload = []): Request
    {
        $request=Request::create('/api/road-waybills','POST',$payload);
        $request->attributes->set('tenant_company_id',$company);
        $request->attributes->set('tenant_branch_id',$branch);
        $request->attributes->set('effective_role_code','BRANCH_MANAGER');
        return $request;
    }

    private function payload(int $branch = 1): array
    {
        return ['branch_id'=>$branch,'document_date'=>'2026-09-21','driver_name'=>'Test Driver','material_owner'=>'TEST Owner',
            'origin_city'=>'Riyadh','destination_city'=>'Jeddah','lines'=>[['item_name'=>'TEST metal','quantity'=>2,'unit'=>'KG']]];
    }

    public function test_waybill_is_a_draft_snapshot_without_financial_rows(): void
    {
        $result=app(RoadWaybillController::class)->store($this->request(1,1,$this->payload()),app(AccountingContext::class))->getData(true)['data'];
        self::assertSame('DRAFT',$result['status']);
        self::assertSame(1,$result['company_id']);
        self::assertSame(1,$result['branch_id']);
        self::assertCount(1,$result['lines']);
        self::assertSame(1,DB::table('road_waybills')->count());
    }

    public function test_other_branch_and_other_company_cannot_read_the_waybill(): void
    {
        $id=app(RoadWaybillController::class)->store($this->request(1,1,$this->payload()),app(AccountingContext::class))->getData(true)['data']['id'];
        foreach ([[1,2],[2,3]] as [$company,$branch]) {
            try {app(RoadWaybillController::class)->show($this->request($company,$branch),$id,app(AccountingContext::class));self::fail('Out of scope waybill exposed');}
            catch (NotFoundHttpException) {}
        }
        self::assertSame(1, DB::table('road_waybills')->count());
    }

    public function test_other_company_cannot_update_a_waybill_even_with_its_id(): void
    {
        $controller=app(RoadWaybillController::class);
        $id=$controller->store($this->request(1,1,$this->payload()),app(AccountingContext::class))->getData(true)['data']['id'];
        try {$controller->update($this->request(2,3,$this->payload(3)),$id,app(AccountingContext::class));self::fail('Cross-company update accepted');}
        catch (NotFoundHttpException) {}
        self::assertSame('Test Driver',DB::table('road_waybills')->where('id',$id)->value('driver_name'));
    }

    public function test_foreign_branch_and_driver_are_rejected_without_a_write(): void
    {
        foreach ([$this->payload(2),$this->payload(1)+['driver_id'=>2]] as $payload) {
            try {app(RoadWaybillController::class)->store($this->request(1,1,$payload),app(AccountingContext::class));self::fail('Out of scope reference accepted');}
            catch (ValidationException) {}
        }
        self::assertSame(0,DB::table('road_waybills')->count());
    }
}
