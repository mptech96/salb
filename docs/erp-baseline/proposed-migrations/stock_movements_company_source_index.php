<?php
// REVIEW ONLY. Intentionally outside database/migrations. Never automatically loaded.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  $indexes=[];
  foreach(DB::select('SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',['stock_movements']) as $r)$indexes[$r->INDEX_NAME][]=[$r->COLUMN_NAME,$r->SUB_PART];
  $expected=[['company_id',null],['source_type',null],['source_id',null]];
  if(isset($indexes['idx_stock_company_source'])&&$indexes['idx_stock_company_source']!==$expected)throw new RuntimeException('Conflicting stock index definition; review required.');
  foreach($indexes as $columns)if(array_slice($columns,0,3)===$expected)return;
  Schema::table('stock_movements',fn(Blueprint $t)=>$t->index(['company_id','source_type','source_id'],'idx_stock_company_source'));
 }
 public function down():void {
  $rows=DB::select('SELECT COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',['stock_movements','idx_stock_company_source']);
  if(!$rows)return;
  if(array_map(fn($r)=>[$r->COLUMN_NAME,$r->SUB_PART],$rows)!==[['company_id',null],['source_type',null],['source_id',null]])throw new RuntimeException('Refusing to remove an incompatible index.');
  // Removes index only, no financial rows. Reintroduces broad scan/lock risk.
  Schema::table('stock_movements',fn(Blueprint $t)=>$t->dropIndex('idx_stock_company_source'));
 }
};
