<?php

namespace Tests\Integration\Uom;

use App\Models\User;
use App\Services\Uom\ItemUnitService;
use Illuminate\Support\Facades\DB;

/** Real Laravel routes and middleware, actual 10.4 QA schema; no middleware bypass. */
final class ItemUnitHttpTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../Support/UomStageAProof.php';
        \Tests\Support\UomStageAProof::guard();
        self::assertStringStartsWith('10.4.32', DB::selectOne('SELECT VERSION() v')->v);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) DB::unprepared('DROP TABLE `'.array_values((array) $row)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::unprepared(file_get_contents(base_path('database/schema/mysql-schema.sql')));
        foreach ([1, 2] as $company) {
            DB::table('companies')->insert(['id'=>$company,'company_name'=>'Synthetic UOM '.$company]);
            DB::table('branches')->insert(['id'=>$company,'company_id'=>$company,'branch_name'=>'QA']);
        }
        DB::table('currencies')->insert(['currency_code'=>'SAR','currency_name'=>'SAR']);
        DB::table('company_currencies')->insert(['company_id'=>1,'currency_code'=>'SAR','is_active'=>1]);
        DB::table('plans')->insert(['id'=>1,'plan_name'=>'Synthetic','plan_code'=>'UOM-QA']);
        DB::table('subscriptions')->insert(['company_id'=>1,'plan_id'=>1,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach (['items.view','items.update'] as $permission) DB::table('permissions')->insert(['permission_code'=>$permission,'permission_name'=>$permission]);
        DB::table('roles')->insert(['id'=>1,'role_code'=>'MANAGER','role_name'=>'Synthetic manager']);
        DB::table('users')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'username'=>'uom-http','name'=>'Synthetic','password'=>'synthetic','is_active'=>1]);
        DB::table('user_roles')->insert(['user_id'=>1,'role_id'=>1,'company_id'=>1]);
        $this->actingAs(User::findOrFail(1), 'sanctum');
        foreach ([[1,1],[2,1],[3,2]] as [$id,$company]) DB::table('items')->insert(['id'=>$id,'company_id'=>$company,'item_code'=>'QA-'.$id,'item_name'=>'Synthetic '.$id]);
        if ($this->name() === 'test_legacy_schema_reads_without_migration_and_writes_refuse') return;
        config(['uom.schema_install_approved'=>true]);
        (require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php'))->up();
        foreach ([[1,'PCS','COUNT','1',0,'STANDARD'],[2,'CARTON','COUNT',null,1,'ITEM_PACKAGING'],[3,'KG','MASS','1',1,'STANDARD']] as [$id,$code,$dimension,$factor,$fraction,$kind]) {
            DB::table('uom_units')->insert(['id'=>$id,'code'=>$code,'name_ar'=>$code,'dimension'=>$dimension,'standard_factor'=>$factor,'allow_fractional'=>$fraction,'kind'=>$kind]);
        }
        app(ItemUnitService::class)->registerNew(1,1,1);
    }

    public function test_http_revision_round_trip_and_decimal_strings(): void
    {
        $this->getJson('/api/items/1/units')->assertOk()->assertJsonPath('data.mode','BASE_ONLY');
        $id=$this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'12.000001'])->assertCreated()->json('data.mapping_id');
        $this->postJson('/api/items/1/units/prices',['mapping_id'=>$id,'currency_code'=>'SAR','purchase_price'=>'25.000001','sale_price'=>'30.000002'])->assertCreated();
        $response=$this->getJson('/api/items/1/units')->assertOk();
        $response->assertJsonPath('data.mappings.0.factor_to_base','12.000001')->assertJsonPath('data.prices.0.sale_price','30.000002');
        $new=$this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'24'])->assertCreated()->json('data.mapping_id');
        self::assertNotSame($id,$new);
        self::assertSame('12.000001',DB::table('item_uom_mappings')->where('id',$id)->value('factor_to_base'));
        $this->postJson('/api/items/1/units/prices',['mapping_id'=>$id,'currency_code'=>'SAR','purchase_price'=>'1','sale_price'=>'1'])->assertUnprocessable();
        self::assertSame(0,DB::table('inventory_lots')->count());
        self::assertSame(0,DB::table('journal_entries')->count());
    }

    public function test_http_rejects_foreign_scope_dimension_precision_and_client_scope(): void
    {
        $this->getJson('/api/items/3/units', ['X-Company-ID'=>'2'])->assertNotFound();
        foreach ([['unit_id'=>3,'factor'=>'1'],['unit_id'=>2,'factor'=>'0'],['unit_id'=>2,'factor'=>'1.0000001'],['unit_id'=>2,'factor'=>1.5],['unit_id'=>2,'factor'=>'1','base_unit_id'=>2]] as $input) {
            $this->postJson('/api/items/1/units/mappings',$input)->assertUnprocessable();
        }
        $this->postJson('/api/items/2/units/mappings',['unit_id'=>2,'factor'=>'2'])->assertUnprocessable();
        self::assertSame(0,DB::table('item_uom_mappings')->count());
        $mapping=$this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'2','company_id'=>2])->assertCreated()->json('data.mapping_id');
        self::assertSame(1,(int)DB::table('item_uom_mappings')->where('id',$mapping)->value('company_id'));
    }

    public function test_http_permission_denial_keeps_definition_unchanged(): void
    {
        DB::table('roles')->where('id',1)->update(['role_code'=>'READ_ONLY']);
        $view=DB::table('permissions')->where('permission_code','items.view')->value('id');
        DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>$view,'company_id'=>1]);
        $this->getJson('/api/items/1/units')->assertOk();
        $this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'2'])->assertForbidden();
        self::assertSame(0,DB::table('item_uom_mappings')->count());
    }

    public function test_legacy_schema_reads_without_migration_and_writes_refuse(): void
    {
        $this->getJson('/api/items/1/units')->assertOk()->assertJsonPath('data.schema_ready',false);
        $this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'2'])->assertConflict();
        self::assertSame(3,DB::table('items')->count());
        self::assertFalse(DB::getSchemaBuilder()->hasTable('item_uom_mappings'));
    }

    public function test_non_manager_permissions_and_failed_revision_are_atomic(): void
    {
        DB::table('roles')->where('id',1)->update(['role_code'=>'UOM_CLERK']);
        foreach(DB::table('permissions')->pluck('id') as $permission) DB::table('role_permissions')->insert(['role_id'=>1,'permission_id'=>$permission,'company_id'=>1]);
        $this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'12'])->assertCreated();
        $before=DB::table('item_uom_heads')->first();
        DB::unprepared("CREATE TRIGGER uom_qa_reject BEFORE INSERT ON item_uom_mappings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic SQL detail must remain private'");
        $response=$this->postJson('/api/items/1/units/mappings',['unit_id'=>2,'factor'=>'24'])->assertConflict();
        self::assertStringNotContainsString('SQLSTATE',$response->getContent());
        self::assertStringNotContainsString('synthetic SQL detail',$response->getContent());
        self::assertEquals($before,DB::table('item_uom_heads')->first());
        self::assertSame(1,DB::table('item_uom_mappings')->count());
        self::assertSame(0,DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
