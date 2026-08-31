<?php

namespace Tests\Feature\PhaseA;

use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\ExpenseTypeController;
use App\Services\Accounting\AccountingContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseTypesAndVehicleBranchIsolationTest extends TestCase
{
    private AccountingContext $context;
    private int $companyA;
    private int $companyB;
    private int $branchA1;
    private int $branchA2;
    private int $branchB;
    private int $expenseAccountA;
    private int $expenseAccountB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['inventory_lot_movements','journal_entries','vouchers','shipment_costs','expenses','weighbridge_cards','shipments','cars','drivers','customers','suppliers','expense_types','accounts','branches','companies'] as $table) Schema::dropIfExists($table);

        Schema::create('companies', fn (Blueprint $t) => [$t->id(), $t->string('company_name')]);
        Schema::create('branches', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id'), $t->string('branch_name'), $t->boolean('is_active')->default(true)]);
        Schema::create('accounts', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->string('account_code');$t->string('account_name');$t->string('account_type');$t->boolean('is_active')->default(true);$t->boolean('is_group')->default(false);$t->boolean('allow_posting')->default(true);});
        Schema::create('expense_types', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id')->nullable();$t->string('type_name',150);$t->string('type_code',50)->nullable();$t->unsignedBigInteger('account_id')->nullable();$t->string('default_scope',50)->default('GENERAL');$t->boolean('affects_cost')->default(true);$t->string('usage_type')->default('GENERAL');$t->string('description',255)->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('suppliers', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->string('supplier_name');$t->boolean('is_active')->default(true);});
        Schema::create('customers', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->string('customer_name');$t->boolean('is_active')->default(true);});
        Schema::create('drivers', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->string('driver_name');$t->string('phone')->nullable();$t->string('id_number')->nullable();$t->string('license_number')->nullable();$t->string('affiliation_type')->default('INDEPENDENT');$t->unsignedBigInteger('affiliation_id')->nullable();$t->text('notes')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('cars', function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('supplier_id')->nullable();$t->unsignedBigInteger('driver_id')->nullable();$t->string('car_number')->nullable();$t->string('plate_number');$t->string('normalized_plate_number');$t->string('ownership_type');$t->string('owner_party_type')->nullable();$t->unsignedBigInteger('owner_party_id')->nullable();$t->string('owner_name')->nullable();$t->string('vehicle_type')->nullable();$t->string('make_name')->nullable();$t->string('model_name')->nullable();$t->integer('model_year')->nullable();$t->text('notes')->nullable();$t->boolean('is_active')->default(true);$t->decimal('gross_weight',15,3)->default(0);$t->decimal('deduction_weight',15,3)->default(0);$t->decimal('net_weight',15,3)->default(0);$t->decimal('transport_cost',15,3)->default(0);$t->decimal('extra_cost',15,3)->default(0);$t->string('car_status')->default('MASTER');$t->timestamps();});
        foreach (['shipments','weighbridge_cards'] as $table) Schema::create($table, function (Blueprint $t): void {$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger('car_id')->nullable();$t->unsignedBigInteger('driver_id')->nullable();});
        Schema::create('expenses', fn (Blueprint $t) => [$t->id(),$t->unsignedBigInteger('company_id'),$t->unsignedBigInteger('expense_type_id')]);
        Schema::create('shipment_costs', fn (Blueprint $t) => [$t->id(),$t->unsignedBigInteger('company_id'),$t->unsignedBigInteger('expense_type_id')]);
        foreach (['vouchers','journal_entries','inventory_lot_movements'] as $table) Schema::create($table, fn (Blueprint $t) => $t->id());

        $this->companyA=DB::table('companies')->insertGetId(['company_name'=>'A']);$this->companyB=DB::table('companies')->insertGetId(['company_name'=>'B']);
        $this->branchA1=$this->branch($this->companyA,'A1');$this->branchA2=$this->branch($this->companyA,'A2');$this->branchB=$this->branch($this->companyB,'B1');
        $this->expenseAccountA=$this->account($this->companyA,'6100');$this->expenseAccountB=$this->account($this->companyB,'6100');
        $this->context=app(AccountingContext::class);
    }

    public function test_expense_type_routes_are_registered_and_permission_compatible(): void
    {
        $routes=collect(Route::getRoutes())->map(fn($r)=>[$r->methods(),$r->uri()]);
        foreach([['GET','api/expense-types'],['GET','api/expense-types/accounts'],['POST','api/expense-types'],['PUT','api/expense-types/{id}'],['DELETE','api/expense-types/{id}']]as[$method,$uri])self::assertTrue($routes->contains(fn($r)=>$r[1]===$uri&&in_array($method,$r[0],true)),"Missing {$method} {$uri}");
    }

    public function test_global_and_private_expense_types_are_isolated_and_global_is_read_only(): void
    {
        $global=$this->expenseType(null,'GLOBAL');$own=$this->expenseType($this->companyA,'OWN');$foreign=$this->expenseType($this->companyB,'FOREIGN');
        $controller=app(ExpenseTypeController::class);$list=$controller->index($this->request('GET',$this->companyA),$this->context)->getData(true)['data'];
        self::assertEqualsCanonicalizing([$global,$own],array_column($list,'id'));self::assertNotContains($foreign,array_column($list,'id'));
        self::assertSame(403,$controller->update($this->request('PUT',$this->companyA,$this->typePayload($this->expenseAccountA)),$global,$this->context)->getStatusCode());
        self::assertSame(403,$controller->destroy($this->request('DELETE',$this->companyA),$global,$this->context)->getStatusCode());
        self::assertSame(404,$controller->update($this->request('PUT',$this->companyA,$this->typePayload($this->expenseAccountA)),$foreign,$this->context)->getStatusCode());
    }

    public function test_tenant_creation_requires_own_expense_account_and_has_zero_financial_side_effects(): void
    {
        $controller=app(ExpenseTypeController::class);
        self::assertSame(422,$controller->store($this->request('POST',$this->companyA,$this->typePayload($this->expenseAccountB)),$this->context)->getStatusCode());
        $response=$controller->store($this->request('POST',$this->companyA,$this->typePayload($this->expenseAccountA)),$this->context);
        self::assertSame(201,$response->getStatusCode());$id=$response->getData(true)['id'];
        self::assertSame($this->companyA,(int)DB::table('expense_types')->where('id',$id)->value('company_id'));
        $accounts=$controller->accounts($this->request('GET',$this->companyA),$this->context)->getData(true)['data'];self::assertSame([$this->expenseAccountA],array_column($accounts,'id'));
        foreach(['expenses','vouchers','journal_entries','inventory_lot_movements']as$table)self::assertSame(0,DB::table($table)->count(),$table);
    }

    public function test_used_and_unused_private_types_are_safely_deactivated_and_can_remain_inactive(): void
    {
        $controller=app(ExpenseTypeController::class);$used=$this->expenseType($this->companyA,'USED');$unused=$this->expenseType($this->companyA,'UNUSED');
        DB::table('expenses')->insert(['company_id'=>$this->companyA,'expense_type_id'=>$used]);
        $usedResponse=$controller->destroy($this->request('DELETE',$this->companyA),$used,$this->context)->getData(true);
        $controller->destroy($this->request('DELETE',$this->companyA),$unused,$this->context);
        self::assertTrue($usedResponse['data']['was_used']);self::assertSame(0,(int)DB::table('expense_types')->where('id',$used)->value('is_active'));
        self::assertSame(0,(int)DB::table('expense_types')->where('id',$unused)->value('is_active'));self::assertSame(2,DB::table('expense_types')->count());
    }

    public function test_car_company_and_branch_isolation_with_company_wide_read_only_visibility(): void
    {
        $controller=app(CarController::class);$same=$this->car($this->companyA,$this->branchA1,'A1');$sameDelete=$this->car($this->companyA,$this->branchA1,'A1D');$other=$this->car($this->companyA,$this->branchA2,'A2');$wide=$this->car($this->companyA,null,'ALL');$foreign=$this->car($this->companyB,$this->branchB,'B');
        self::assertSame(200,$controller->show($this->request('GET',$this->companyA,[],$this->branchA1),$same,$this->context)->getStatusCode());
        self::assertSame(200,$controller->show($this->request('GET',$this->companyA,[],$this->branchA1),$wide,$this->context)->getStatusCode());
        foreach([$other,$foreign]as$id)self::assertSame(404,$controller->show($this->request('GET',$this->companyA,[],$this->branchA1),$id,$this->context)->getStatusCode());
        self::assertSame(200,$controller->update($this->request('PUT',$this->companyA,$this->carPayload('A1-X'),$this->branchA1),$same,$this->context)->getStatusCode());
        foreach([$other,$wide,$foreign]as$id)self::assertSame(404,$controller->update($this->request('PUT',$this->companyA,$this->carPayload('NO'),$this->branchA1),$id,$this->context)->getStatusCode());
        self::assertSame(200,$controller->destroy($this->request('DELETE',$this->companyA,[],$this->branchA1),$sameDelete,$this->context)->getStatusCode());foreach([$other,$wide,$foreign]as$id)self::assertSame(404,$controller->destroy($this->request('DELETE',$this->companyA,[],$this->branchA1),$id,$this->context)->getStatusCode());
    }

    public function test_driver_scope_allows_same_branch_and_company_wide_read_but_denies_other_mutations(): void
    {
        $controller=app(DriverController::class);$same=$this->driver($this->companyA,$this->branchA1,'A1');$sameDelete=$this->driver($this->companyA,$this->branchA1,'A1D');$other=$this->driver($this->companyA,$this->branchA2,'A2');$wide=$this->driver($this->companyA,null,'ALL');$foreign=$this->driver($this->companyB,$this->branchB,'B');$request=$this->request('GET',$this->companyA,[],$this->branchA1);
        $ids=array_column($controller->index($request,$this->context)->getData(true)['data'],'id');self::assertEqualsCanonicalizing([$same,$sameDelete,$wide],$ids);
        self::assertSame(200,$controller->show($request,$wide,$this->context)->getStatusCode());foreach([$other,$foreign]as$id)self::assertSame(404,$controller->show($request,$id,$this->context)->getStatusCode());
        self::assertSame(200,$controller->update($this->request('PUT',$this->companyA,$this->driverPayload($this->branchA1),$this->branchA1),$same,$this->context)->getStatusCode());
        foreach([$other,$wide,$foreign]as$id)self::assertSame(404,$controller->update($this->request('PUT',$this->companyA,$this->driverPayload($this->branchA1),$this->branchA1),$id,$this->context)->getStatusCode());
        self::assertSame(200,$controller->destroy($this->request('DELETE',$this->companyA,[],$this->branchA1),$sameDelete,$this->context)->getStatusCode());foreach([$other,$wide,$foreign]as$id)self::assertSame(404,$controller->destroy($this->request('DELETE',$this->companyA,[],$this->branchA1),$id,$this->context)->getStatusCode());
        self::assertSame(422,$controller->store($this->request('POST',$this->companyA,$this->driverPayload($this->branchA2),$this->branchA1),$this->context)->getStatusCode());
        $created=$controller->store($this->request('POST',$this->companyA,$this->driverPayload(null),$this->branchA1),$this->context)->getData(true)['id'];self::assertSame($this->branchA1,(int)DB::table('drivers')->where('id',$created)->value('branch_id'));
    }

    private function request(string$method,int$company,array$data=[],?int$branch=null):Request{$r=Request::create('/api/test',$method,$data);$r->attributes->set('tenant_company_id',$company);$r->attributes->set('tenant_branch_id',$branch);$r->attributes->set('effective_role_code',$branch?'BRANCH_MANAGER':'MANAGER');return$r;}
    private function branch(int$c,string$n):int{return DB::table('branches')->insertGetId(['company_id'=>$c,'branch_name'=>$n,'is_active'=>1]);}
    private function account(int$c,string$code):int{return DB::table('accounts')->insertGetId(['company_id'=>$c,'account_code'=>$code,'account_name'=>'Expense','account_type'=>'EXPENSE','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);}
    private function expenseType(?int$c,string$code):int{return DB::table('expense_types')->insertGetId(['company_id'=>$c,'type_name'=>$code,'type_code'=>$code,'default_scope'=>'GENERAL','affects_cost'=>1,'usage_type'=>'GENERAL','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);}
    private function typePayload(int$a):array{return['type_name'=>'Fuel','type_code'=>'FUEL'.random_int(1,999999),'account_id'=>$a,'default_scope'=>'GENERAL','affects_cost'=>1,'is_active'=>1];}
    private function car(int$c,?int$b,string$plate):int{return DB::table('cars')->insertGetId(['company_id'=>$c,'branch_id'=>$b,'plate_number'=>$plate,'normalized_plate_number'=>$plate,'ownership_type'=>'COMPANY','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);}
    private function carPayload(string$plate):array{return['branch_id'=>$this->branchA1,'plate_number'=>$plate,'ownership_type'=>'COMPANY','is_active'=>1];}
    private function driver(int$c,?int$b,string$name):int{return DB::table('drivers')->insertGetId(['company_id'=>$c,'branch_id'=>$b,'driver_name'=>$name,'affiliation_type'=>'INDEPENDENT','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);}
    private function driverPayload(?int$b):array{return['branch_id'=>$b,'driver_name'=>'Updated','affiliation_type'=>'INDEPENDENT','is_active'=>1];}
}
