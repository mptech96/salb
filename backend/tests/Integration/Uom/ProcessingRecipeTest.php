<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\Uom\ProcessingRecipeService;
final class ProcessingRecipeTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();(require base_path('database/uom-migrations/2026_10_25_000008_processing_recipes.php'))->up();
        foreach(['inventory.process','inventory.process.post'] as $permission)DB::table('permissions')->insert(['permission_code'=>$permission,'permission_name'=>$permission]);
        DB::table('uom_units')->insert(['id'=>4,'code'=>'KG','name_ar'=>'KG','dimension'=>'MASS','standard_factor'=>'1','allow_fractional'=>1,'kind'=>'STANDARD']);
        app(\App\Services\Uom\ItemUnitService::class)->registerNew(1,2,4);app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,2,4,'1');
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function input():array {return ['recipe_code'=>'QA-EXPLICIT-ONLY','operation_type'=>'DISASSEMBLY','yield_notes'=>'Synthetic declared output yield; no implicit PCS to KG conversion','from'=>[['item_id'=>1,'unit_code'=>'CARTON','quantity'=>'0.25']],'to'=>[['item_id'=>2,'unit_code'=>'KG','quantity'=>'1.5']]];}
    public function test_legacy_recipe_units_precision_and_foreign_items_are_rejected_without_saved_recipe():void
    {
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'LEGACY','item_name'=>'QA legacy KG','inventory_account_id'=>11]);$s=app(ProcessingRecipeService::class);
        foreach([[4,'PCS','1','UOM_RECIPE_LEGACY_WEIGHT_UNIT_REQUIRED'],[4,'KG','0.000001','UOM_RECIPE_LEGACY_KG_PRECISION'],[3,'KG','1','الصنف غير موجود ضمن الشركة المحددة.']] as [$item,$unit,$quantity,$error]){
            $input=$this->input();$input['from']=[['item_id'=>$item,'unit_code'=>$unit,'quantity'=>$quantity]];
            try{$s->create(1,1,$input);self::fail('Invalid legacy input accepted');}catch(\RuntimeException $e){self::assertStringContainsString($error,$e->getMessage());}self::assertSame(0,DB::table('uom_processing_recipes')->count());
        }
        $input=$this->input();$input['from']=[['item_id'=>4,'unit_code'=>'TON','quantity'=>'0.000001']];$id=$s->create(1,1,$input);$s->approve(1,1,$id);$line=DB::transaction(fn()=>$s->requiredLines(1,1,$id,'1'))['from'][0];self::assertSame('0.001',$line['qty_kg']);self::assertNull($line['base_quantity']);self::assertSame('LEGACY_KG',$line['uom_version']);
        try{DB::transaction(fn()=>$s->requiredLines(2,2,$id,'1'));self::fail('Other company recipe disclosed');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_APPROVED_RECIPE_REQUIRED',$e->getMessage());}
    }
    public function test_only_approved_explicit_recipe_can_provide_cross_dimension_quantities_and_history_is_immutable():void
    {
        $s=app(ProcessingRecipeService::class);$id=$s->create(1,1,$this->input());
        try{DB::transaction(fn()=>$s->requiredLines(1,1,$id,'2'));self::fail('Draft recipe used');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_APPROVED_RECIPE_REQUIRED',$e->getMessage());}
        $s->approve(1,1,$id);$s->approve(1,1,$id);app(\App\Services\Uom\ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $lines=DB::transaction(fn()=>$s->requiredLines(1,1,$id,'2'));self::assertSame('6.000000',$lines['from'][0]['base_quantity']);self::assertSame('3.000000',$lines['to'][0]['base_quantity']);self::assertSame('12.000000',$lines['from'][0]['uom_factor_snapshot']);
        foreach(['update','delete'] as $op){try{if($op==='update')DB::table('uom_processing_recipes')->where('id',$id)->update(['payload'=>'{}']);else DB::table('uom_processing_recipes')->where('id',$id)->delete();self::fail('Approved recipe history changed');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('UOM_',$e->getMessage());}}
        self::assertSame(0,DB::table('inventory_lots')->count());self::assertSame(0,DB::table('journal_entries')->count());
    }
    public function test_recipe_requires_company_branch_permission_and_exact_divisible_quantities():void
    {
        $s=app(ProcessingRecipeService::class);foreach([[2,2],[1,2]] as [$company,$branch]){try{$s->create($company,$branch,$this->input());self::fail('Foreign recipe created');}catch(\RuntimeException $e){self::assertSame('UOM_RECIPE_ACTOR_SCOPE',$e->getMessage());}}
        $id=$s->create(1,1,$this->input());$s->approve(1,1,$id);
        try{DB::transaction(fn()=>$s->requiredLines(2,2,$id,'1'));self::fail('Foreign recipe consumed');}catch(\RuntimeException $e){self::assertStringContainsString('UOM_APPROVED_RECIPE_REQUIRED',$e->getMessage());}
        try{DB::transaction(fn()=>$s->requiredLines(1,1,$id,'0.1'));self::fail('Fractional PCS invented');}catch(\InvalidArgumentException $e){self::assertSame('UOM_INDIVISIBLE_BASE',$e->getMessage());}
        DB::table('roles')->where('id',1)->update(['role_code'=>'VIEWER']);app()->forgetInstance(\App\Services\Auth\SessionContextService::class);
        try{$s->create(1,1,$this->input());self::fail('Viewer authored recipe');}catch(\RuntimeException $e){self::assertSame('UOM_RECIPE_PERMISSION',$e->getMessage());}
    }
}
