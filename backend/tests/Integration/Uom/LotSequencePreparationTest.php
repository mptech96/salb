<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Tests\Support\UomOperationalLockFixture as F;
use Tests\Support\LotSequencePreparationProof as P;
class LotSequencePreparationTest extends \Tests\TestCase
{
 protected function setUp():void{
  parent::setUp();require_once __DIR__.'/../../Support/UomStageAProof.php';require_once __DIR__.'/../../Support/UomOperationalLockFixture.php';require_once __DIR__.'/../../Support/LotSequencePreparationProof.php';\Tests\Support\UomStageAProof::guard();
  DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row){$t=array_values((array)$row)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
 }
 private function history(int $company,int $year,int $n):void{
  DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>$company,'branch_id'=>$company,'item_id'=>$company===1?1:3,'qty_kg'=>10,'base_cost'=>100,'received_at'=>$year.'-01-01','lot_number'=>'LOT-'.$year.'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT)]));
 }
 public function test_missing_correct_advanced_lagging_repeat_and_first_post():void{
  $this->history(1,2026,5);$this->history(1,2026,9);$before=DB::table('inventory_lots')->orderBy('id')->get()->toJson();
  DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->delete();
  $pid=F::purchase(1,1);try{app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$pid,1,1);self::fail('Unprepared posting');}catch(\RuntimeException $e){self::assertStringContainsString('تهيئة',$e->getMessage());}
  self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(10,P::prepare(1,2026,true));$row=DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT')->first();
  self::assertSame(10,P::prepare(1,2026,true));self::assertEquals($row,DB::table('sulb_document_sequences')->where('id',$row->id)->first());
  DB::table('sulb_document_sequences')->where('id',$row->id)->update(['next_number'=>30]);self::assertSame(30,P::prepare(1,2026,true));
  DB::table('sulb_document_sequences')->where('id',$row->id)->update(['next_number'=>2]);self::assertSame(10,P::prepare(1,2026,true));self::assertSame($before,DB::table('inventory_lots')->orderBy('id')->get()->toJson());
  app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$pid,1,1);self::assertSame('LOT-2026-000010',DB::table('inventory_lots')->orderByDesc('id')->value('lot_number'));self::assertSame(1,DB::table('journal_entries')->count());
 }
 public function test_companies_years_and_unmaterialized_opening_reference():void{
  $this->history(1,2025,80);$this->history(2,2026,12);$oid=F::opening(1,1);DB::table('opening_inventory_lines')->where('batch_id',$oid)->update(['lot_number'=>'LOT-2026-000100']);
  self::assertSame(81,P::prepare(1,2025,true));self::assertSame(101,P::prepare(1,2026,true));self::assertSame(13,P::prepare(2,2026,true));self::assertSame(1,P::prepare(2,2025,true));
 }
 public function test_conflicts_and_unpaused_writes_are_not_repaired():void{
  $this->history(1,2026,7);$before=DB::table('sulb_document_sequences')->get()->toJson();
  try{P::prepare(1,2026,false);self::fail('Paused writers required');}catch(\RuntimeException $e){self::assertSame('WRITERS_NOT_PAUSED',$e->getMessage());}
  DB::table('inventory_lots')->where('company_id',1)->update(['lot_number'=>'OLD-CUSTOM']);
  try{P::prepare(1,2026,true);self::fail('Unknown history');}catch(\RuntimeException $e){self::assertSame('UNREVIEWED_CUSTOM_NUMBER',$e->getMessage());}self::assertSame($before,DB::table('sulb_document_sequences')->get()->toJson());
  DB::table('inventory_lots')->where('company_id',1)->update(['lot_number'=>'LOT-2025-000007']);
  try{P::prepare(1,2026,true);self::fail('Mismatched year');}catch(\RuntimeException $e){self::assertSame('NAMESPACE_DATE_MISMATCH',$e->getMessage());}
  // Simulate a pre-constraint legacy database, ONLY inside this disposable schema.
  DB::statement('ALTER TABLE inventory_lots DROP INDEX uq_inventory_lot_number');DB::table('inventory_lots')->where('company_id',1)->update(['lot_number'=>'LOT-2026-000007']);$this->history(1,2026,7);
  try{P::prepare(1,2026,true);self::fail('Duplicated history');}catch(\RuntimeException $e){self::assertSame('DUPLICATE_HISTORY',$e->getMessage());}self::assertSame(2,DB::table('inventory_lots')->count());
 }
 public function test_proposed_parameterized_sql_executes_and_never_lowers_counter():void{
  $this->history(1,2026,15);$text=file_get_contents(base_path('../docs/erp-baseline/proposed-migrations/lot-sequence-preparation-template.sql'));preg_match('/INSERT INTO[\s\S]+?;/',$text,$m);self::assertNotEmpty($m);
  $execute=function()use($m){DB::transaction(function()use($m){$s=DB::connection()->getPdo()->prepare($m[0]);preg_match_all('/:([a-z_]+)/',$m[0],$params);foreach(array_unique($params[1]) as $key)$s->bindValue(':'.$key,str_contains($key,'year')?2026:1,\PDO::PARAM_INT);$s->execute();});};
  $execute();$q=fn()=>DB::table('sulb_document_sequences')->where('company_id',1)->where('document_type','INVENTORY_LOT');self::assertEquals(16,$q()->value('next_number'));
  $q()->update(['next_number'=>25]);$before=$q()->first();$execute();self::assertEquals($before,$q()->first());
 }
}
