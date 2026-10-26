<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
use App\Services\Uom\ItemUnitService;

/** Synthetic QA only. Guard precedes all schema/data writes. */
final class OperationalUomFixture
{
    public static function install():void
    {
        foreach(['UomStageAProof','UomOperationalLockFixture'] as $name)require_once __DIR__.'/'.$name.'.php';
        UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');UomOperationalLockFixture::install();
        config(['uom.schema_install_approved'=>true,'uom.operational_enabled'=>true]);
        (require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php'))->up();
        (require base_path('database/uom-migrations/2026_10_24_000003_uom_operational.php'))->up();
        foreach([[1,'PCS','COUNT','1',0,'STANDARD'],[2,'CARTON','COUNT',null,1,'ITEM_PACKAGING'],[3,'M','LENGTH','1',1,'STANDARD']] as [$id,$code,$dimension,$factor,$fraction,$kind])DB::table('uom_units')->insert(['id'=>$id,'code'=>$code,'name_ar'=>$code,'dimension'=>$dimension,'standard_factor'=>$factor,'allow_fractional'=>$fraction,'kind'=>$kind]);
        foreach(['items.view','items.update'] as $code)DB::table('permissions')->insert(['permission_code'=>$code,'permission_name'=>$code]);
        DB::table('roles')->insert(['id'=>1,'role_code'=>'MANAGER','role_name'=>'QA']);
        DB::table('user_roles')->insert(['user_id'=>1,'role_id'=>1,'company_id'=>1]);
        \Illuminate\Support\Facades\Auth::login(\App\Models\User::findOrFail(1));
        $units=app(ItemUnitService::class);$units->registerNew(1,1,1);$units->reviseMapping(1,1,1,'1');$units->reviseMapping(1,1,2,'12');
        foreach([[31,'SALES','REVENUE','CREDIT'],[32,'COGS','EXPENSE','DEBIT'],[33,'AR','ASSET','DEBIT']] as [$id,$code,$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>$code,'account_name'=>'QA '.$code,'account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('items')->where('id',1)->update(['sales_account_id'=>31,'cogs_account_id'=>32,'sales_return_account_id'=>31,'purchase_return_account_id'=>11]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'QA customer','ledger_account_id'=>33,'scope_all_branches'=>1]);
    }
}
