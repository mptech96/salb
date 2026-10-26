<?php
namespace Tests\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UomReadonlyAuditProofTest extends \Tests\TestCase
{
    public function test_readonly_audit_and_synthetic_historical_classification():void
    {
        self::assertSame(':memory:',DB::connection()->getDatabaseName());
        $sql=file_get_contents(base_path('../docs/erp-baseline/uom-historical-readonly-audit.sql'));
        $sql=preg_replace('/--[^\r\n]*/','',$sql);$statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
        foreach($statements as $s){self::assertMatchesRegularExpression('/^SELECT\s/i',$s);self::assertDoesNotMatchRegularExpression('/\b(INTO\s+OUTFILE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE|CALL)\b/i',$s);}
        Schema::create('items',function($t){$t->id();$t->integer('company_id')->nullable();$t->string('item_type');$t->string('base_unit_code');$t->string('commercial_unit_code');$t->decimal('commercial_to_base_factor',18,6);});
        Schema::create('inventory_lots',function($t){$t->id();$t->integer('company_id');$t->integer('item_id');});
        Schema::create('inventory_lot_movements',function($t){$t->id();$t->integer('company_id');$t->integer('item_id');});
        foreach([[1,'KG',1],[2,'TON',1000],[3,'KG',1000]] as [$id,$unit,$factor])DB::table('items')->insert(['id'=>$id,'company_id'=>1,'item_type'=>'STOCK','base_unit_code'=>'KG','commercial_unit_code'=>$unit,'commercial_to_base_factor'=>$factor]);
        DB::table('inventory_lots')->insert(['company_id'=>1,'item_id'=>3]);DB::table('inventory_lot_movements')->insert(['company_id'=>1,'item_id'=>3]);
        $rows=DB::select($statements[2]);self::assertSame('VALID_WEIGHT_DEFINITION',$rows[0]->definition_class);self::assertSame('VALID_WEIGHT_DEFINITION',$rows[1]->definition_class);self::assertSame('REVIEW_KG_FACTOR_1000',$rows[2]->definition_class);self::assertSame(1,(int)$rows[2]->lot_count);
    }
}
