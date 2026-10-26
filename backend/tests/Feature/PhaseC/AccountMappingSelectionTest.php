<?php

namespace Tests\Feature\PhaseC;

use App\Http\Controllers\Api\FinancialSetupController;
use App\Http\Controllers\Api\ItemController;
use App\Services\Accounting\AccountingContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountMappingSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite',DB::connection()->getDriverName());
        self::assertSame(':memory:',DB::connection()->getDatabaseName());
        Schema::create('accounts',function(Blueprint $t){$t->id();$t->integer('company_id');$t->boolean('is_active')->default(true);$t->boolean('is_group')->default(false);$t->boolean('allow_posting')->default(true);});
        DB::table('accounts')->insert([['id'=>101,'company_id'=>1],['id'=>102,'company_id'=>1],['id'=>201,'company_id'=>2]]);
        foreach(['items','item_groups','item_categories']as$table)Schema::create($table,function(Blueprint $t)use($table){
            $t->id();$t->integer('company_id');
            foreach(['inventory_account_id','sales_account_id','cogs_account_id','purchase_expense_account_id','sales_return_account_id','purchase_return_account_id','group_id','category_id','parent_id','track_inventory','allow_negative_stock','can_purchase','can_sell','is_waste_item','is_byproduct','is_active']as$c)$t->integer($c)->nullable();
            foreach(['item_code','item_name','item_grade','item_type','base_unit_code','commercial_unit_code','costing_method','unit_name','color_notes','notes','group_code','group_name','category_code','category_name']as$c)$t->string($c)->nullable();
            foreach(['commercial_to_base_factor','default_buy_price','default_sell_price','min_sell_price']as$c)$t->decimal($c,18,3)->nullable();
            $t->timestamps();
        });
        Schema::create('tax_codes',function(Blueprint $t){$t->id();$t->integer('company_id');foreach(['tax_code','tax_name','tax_type']as$c)$t->string($c);$t->decimal('rate',8,4);$t->date('valid_from')->nullable();$t->date('valid_to')->nullable();foreach(['sales_tax_account_id','purchase_tax_account_id']as$c)$t->integer($c)->nullable();foreach(['is_zero_rated','is_exempt','is_out_of_scope','is_default_sales','is_default_purchase','is_active']as$c)$t->boolean($c)->default(false);$t->timestamps();});
    }

    private function request(array $values): Request
    {
        $r=Request::create('/api/test','POST',$values);
        $r->attributes->set('tenant_company_id',1);
        $r->attributes->set('effective_role_code','COMPANY_OWNER');
        app()->instance('request',$r);
        return $r;
    }

    public function test_item_gl_selection_saves_updates_and_restores_raw_gl_identity(): void
    {
        $controller=app(ItemController::class);$context=app(AccountingContext::class);
        $payload=['item_name'=>'TEST service','item_type'=>'SERVICE','sales_account_id'=>101];
        $created=$controller->store($this->request($payload),$context);
        self::assertSame(201,$created->getStatusCode());$id=$created->getData(true)['id'];
        self::assertSame(101,$controller->show($this->request([]),$id,$context)->getData(true)['data']['sales_account_id']);
        $payload['sales_account_id']=102;
        self::assertSame(200,$controller->update($this->request($payload),$id,$context)->getStatusCode());
        self::assertSame(102,$controller->show($this->request([]),$id,$context)->getData(true)['data']['sales_account_id']);
        $foreign=$this->request([]);$foreign->attributes->set('tenant_company_id',2);
        self::assertSame(404,$controller->show($foreign,$id,$context)->getStatusCode());
    }

    public function test_items_groups_categories_reject_foreign_inactive_group_and_nonposting_accounts(): void
    {
        $controller=app(ItemController::class);$context=app(AccountingContext::class);
        foreach([['company_id'=>2],['is_active'=>0],['is_group'=>1],['allow_posting'=>0]]as$invalid){
            DB::table('accounts')->where('id',101)->update(['company_id'=>1,'is_active'=>1,'is_group'=>0,'allow_posting'=>1,...$invalid]);
            foreach(['store','storeGroup','storeCategory']as$method){
                try{$controller->$method($this->request(['item_name'=>'TEST','item_type'=>'SERVICE','group_name'=>'TEST','category_name'=>'TEST','sales_account_id'=>101]),$context);self::fail('Invalid GL accepted');}
                catch(ValidationException $e){self::assertArrayHasKey('sales_account_id',$e->errors());}
            }
        }
        self::assertSame(0,DB::table('items')->count());self::assertSame(0,DB::table('item_groups')->count());self::assertSame(0,DB::table('item_categories')->count());
    }

    public function test_tax_gl_mapping_save_update_and_invalid_account_rejection(): void
    {
        $controller=app(FinancialSetupController::class);$context=app(AccountingContext::class);
        $payload=['tax_code'=>'TEST','tax_name'=>'TEST tax','tax_type'=>'VAT','rate'=>15,'sales_tax_account_id'=>101,'purchase_tax_account_id'=>102];
        $created=$controller->taxCode($this->request($payload),$context);
        self::assertSame(200,$created->getStatusCode());$payload['id']=$created->getData(true)['id'];
        self::assertSame(101,(int)DB::table('tax_codes')->value('sales_tax_account_id'));
        $payload['sales_tax_account_id']=102;
        self::assertSame(200,$controller->taxCode($this->request($payload),$context)->getStatusCode());
        self::assertSame(102,(int)DB::table('tax_codes')->value('sales_tax_account_id'));
        $payload['sales_tax_account_id']=201;
        self::assertSame(422,$controller->taxCode($this->request($payload),$context)->getStatusCode());
        DB::table('accounts')->where('id',102)->update(['is_active'=>0]);$payload['sales_tax_account_id']=102;
        self::assertSame(422,$controller->taxCode($this->request($payload),$context)->getStatusCode());
        self::assertSame(1,DB::table('tax_codes')->count());
    }

    public function test_inventory_cost_selection_rejects_other_branch_and_allows_central_account(): void
    {
        Schema::create('financial_accounts',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();$t->boolean('is_active')->default(true);$t->string('currency_code')->default('SAR');});
        Schema::create('inventory_operation_costs',function(Blueprint $t){$t->id();foreach(['company_id','branch_id','operation_id','financial_account_id','created_by']as$c)$t->integer($c)->nullable();foreach(['cost_type','currency_code','payment_status','notes']as$c)$t->string($c)->nullable();foreach(['amount','base_amount','exchange_rate']as$c)$t->decimal($c,18,3);$t->timestamps();});
        DB::table('financial_accounts')->insert([['id'=>31,'company_id'=>1,'branch_id'=>2],['id'=>32,'company_id'=>1,'branch_id'=>null],['id'=>33,'company_id'=>1,'branch_id'=>1],['id'=>34,'company_id'=>2,'branch_id'=>1]]);
        $money=\Mockery::mock(\App\Services\FinancialAccountService::class);$money->shouldReceive('baseCurrency')->andReturn('SAR');
        $service=new \App\Services\InventoryOperationService(app(\App\Services\InventoryLotService::class),app(\App\Domain\Accounting\Services\JournalService::class),app(\App\Services\Accounting\PostingSupport::class),$money,app(\App\Services\Accounting\ItemAccountingResolver::class),app(\App\Services\SulbDocumentSequenceService::class));
        $method=new \ReflectionMethod($service,'insertOperationCost');
        foreach([31,34]as$id){try{$method->invoke($service,1,1,99,'2026-03-05',['cost_type'=>'TEST','amount'=>10,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>$id],1);self::fail('Out-of-scope financial account accepted');}catch(\RuntimeException $e){self::assertSame('الحساب المالي لتكلفة التشغيل غير صالح.',$e->getMessage());}}
        self::assertSame(0,DB::table('inventory_operation_costs')->count());
        foreach([32,33]as$id)$method->invoke($service,1,1,99,'2026-03-05',['cost_type'=>'TEST','amount'=>10,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>$id],1);
        self::assertSame([32,33],DB::table('inventory_operation_costs')->orderBy('id')->pluck('financial_account_id')->all());
    }

    public function test_new_option_routes_keep_auth_tenant_permission_and_entitlement_middleware(): void
    {
        foreach(['financial-setup/gl-options','financial-accounts/gl-options','items/gl-options','inventory-operations/account-options','shipments/account-options']as$path){
            $route=app('router')->getRoutes()->match(Request::create('/api/'.$path,'GET'));
            $middleware=app('router')->gatherRouteMiddleware($route);
            foreach([\App\Http\Middleware\EnforceTenantScope::class,\App\Http\Middleware\EnsureRoutePermission::class,\App\Http\Middleware\EnsureFeatureEntitlement::class,\App\Http\Middleware\EnsureSupportAccess::class]as$guard)self::assertContains($guard,$middleware);
            self::assertStringStartsWith('App\\Http\\Controllers\\Api\\',$route->getActionName());
            $this->getJson('/api/'.$path)->assertUnauthorized();
        }
    }

    public function test_metadata_opt_out_preserves_legacy_defaults_and_response_arrays(): void
    {
        Schema::table('accounts',function(Blueprint $t){$t->string('account_code')->nullable();$t->string('account_name')->nullable();$t->string('account_type')->default('ASSET');});
        DB::table('accounts')->where('id',101)->update(['account_code'=>'1110','account_name'=>'Default cash']);
        DB::table('accounts')->where('id',102)->update(['account_code'=>'1150','account_name'=>'Custody']);
        $items=app(ItemController::class);$context=app(AccountingContext::class);
        $legacy=$items->meta($this->request([]),$context)->getData(true)['data'];
        self::assertCount(2,$legacy['posting_accounts']);
        self::assertSame([],$items->meta($this->request(['include_accounts'=>false]),$context)->getData(true)['data']['posting_accounts']);
        Schema::create('branches',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->boolean('is_active')->default(true),$t->string('branch_name')]);
        Schema::create('currencies',fn(Blueprint $t)=>[$t->string('currency_code')->primary(),$t->string('currency_name')]);
        Schema::create('company_currencies',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->string('currency_code'),$t->boolean('is_active')->default(true),$t->boolean('is_base')->default(true)]);
        $financial=app(\App\Http\Controllers\Api\FinancialAccountController::class);
        self::assertCount(2,$financial->meta($this->request([]),$context)->getData(true)['data']['accounts']);
        $defaults=$financial->meta($this->request(['include_accounts'=>false]),$context)->getData(true)['data']['accounts'];
        self::assertCount(1,$defaults);self::assertSame(101,$defaults[0]['id']);
    }

    public function test_operational_account_save_restores_gl_and_syncs_cash_bank_wallet_defaults(): void
    {
        Schema::table('accounts',function(Blueprint $t){$t->string('account_type')->default('ASSET');$t->string('account_code')->default('GL');$t->string('account_name')->default('TEST GL');});
        Schema::create('branches',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->string('branch_name'),$t->boolean('is_active')->default(true)]);
        DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'TEST']);
        Schema::create('currencies',fn(Blueprint $t)=>[$t->string('currency_code')->primary(),$t->boolean('is_active')->default(true)]);
        Schema::create('company_currencies',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->string('currency_code'),$t->boolean('is_active')->default(true)]);
        DB::table('currencies')->insert(['currency_code'=>'SAR']);DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR']);
        Schema::create('financial_accounts',function(Blueprint $t){$t->id();foreach(['company_id','branch_id','gl_account_id','is_default_receipt','is_default_payment','is_active']as$c)$t->integer($c)->nullable();foreach(['account_code','account_name','account_type','currency_code','bank_name','account_number','iban','wallet_provider','notes']as$c)$t->string($c)->nullable();$t->timestamps();});
        Schema::create('branch_financial_settings',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id');foreach(['default_cash_financial_account_id','default_bank_financial_account_id','default_wallet_financial_account_id']as$c)$t->integer($c)->nullable();$t->timestamps();});
        Schema::create('journal_entry_lines',fn(Blueprint $t)=>[$t->id(),$t->integer('company_id'),$t->integer('financial_account_id')]);
        $service=app(\App\Services\FinancialAccountService::class);
        foreach(['CASH'=>'cash','BANK'=>'bank','WALLET'=>'wallet']as$type=>$key){
            $payload=['branch_id'=>1,'account_code'=>'TEST-'.$type,'account_name'=>'TEST '.$type,'account_type'=>$type,'gl_account_id'=>101,'currency_code'=>'SAR','is_default_payment'=>true];
            $id=$service->save(1,$payload);
            self::assertSame($id,(int)DB::table('branch_financial_settings')->value('default_'.$key.'_financial_account_id'));
            self::assertSame(101,(int)DB::table('financial_accounts')->where('id',$id)->value('gl_account_id'));
            $payload['gl_account_id']=102;$service->save(1,$payload,$id);
            $selected=$service->selectionPage(1,1,['selected_id'=>$id])->items()[0];
            self::assertSame($id,(int)$selected->id);self::assertSame(102,(int)$selected->gl_account_id);
            DB::table('journal_entry_lines')->insert(['company_id'=>1,'financial_account_id'=>$id]);$payload['gl_account_id']=101;
            try{$service->save(1,$payload,$id);self::fail('Historical GL link changed');}catch(\RuntimeException $e){self::assertStringContainsString('حركات تاريخية',$e->getMessage());}
            self::assertSame(102,(int)DB::table('financial_accounts')->where('id',$id)->value('gl_account_id'));
        }
        self::assertSame(3,DB::table('financial_accounts')->count());
    }
}
