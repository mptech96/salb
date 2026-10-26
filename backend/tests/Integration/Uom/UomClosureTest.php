<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\Uom\ItemUnitService as U;
use App\Services\Uom\DocumentSnapshotService as S;
use App\Services\Uom\BaseCostContract as C;
require_once __DIR__.'/UomFoundationTest.php';
class UomClosureTest extends UomFoundationTest
{
 private function bootContracts():void{
  $this->test_real_migration_and_services_without_stock_activation();$m=require base_path('database/uom-migrations/2026_10_14_000002_uom_contracts.php');$m->up();DB::table('company_settings')->insert(['company_id'=>1,'base_currency_code'=>'SAR','currency_decimal_places'=>3]);config(['uom.contract_testing_enabled'=>true]);
 }
 private function failCall(callable $f,string $msg):void{try{$f();self::fail('Expected refusal');}catch(\RuntimeException|\InvalidArgumentException $e){self::assertStringContainsString($msg,$e->getMessage());}}
 private function source(int $item,int $unit,string $factor,string $quantity,string $buy,string $sell,string $key):object{
  $u=new U;$map=$u->reviseMapping(1,$item,$unit,$factor);$price=$u->revisePrice(1,$item,$map,'SAR',$buy,$sell);return (new S)->save(1,1,$item,$map,$price,$quantity,'PURCHASE',$key,'prepared-document-'.$key,'line-1');
 }
 public function test_durable_snapshot_independent_prices_and_scope():void{
  $this->bootContracts();$s=$this->source(1,2,'24','3','120','180','snapshot');$old=$s->payload;
  $u=new U;$p=$u->revisePrice(1,1,$s->mapping_id,'SAR','150','200');self::assertSame($old,(new S)->get(1,$s->id)->payload);self::assertSame('200.000000',$u->snapshot(1,1,$s->mapping_id,$p,'1','SALE')['unit_price']);
  $this->failCall(fn()=>(new S)->save(1,1,1,$s->mapping_id,$s->price_id,'4','PURCHASE','snapshot','prepared-document-snapshot','line-1'),'IDEMPOTENCY');
  $this->failCall(fn()=>DB::table('uom_document_snapshots')->where('id',$s->id)->update(['entered_quantity'=>'4']),'IMMUTABLE');
  config(['uom.contract_testing_enabled'=>false]);$this->failCall(fn()=>(new C)->create(1,$s->id,'360'),'NOT_ACTIVATED');config(['uom.contract_testing_enabled'=>true]);
  $this->failCall(fn()=>(new S)->get(2,$s->id),'AUTH_COMPANY');Auth::logout();$this->failCall(fn()=>(new S)->get(1,$s->id),'AUTH_COMPANY');
 }
 public function test_actual_sale_price_tax_contract_and_disallowed_currency():void{
  $this->bootContracts();$u=new U;$map=$u->reviseMapping(1,1,1,'1');$price=$u->revisePrice(1,1,$map,'SAR','5','8');$s=(new S)->save(1,1,1,$map,$price,'5','SALE','sale-price','prepared-sale','1');$payload=json_decode($s->payload,true);
  self::assertSame('8.000000',$payload['unit_price']);self::assertSame('5.000000',$u->snapshot(1,1,$map,$price,'1','PURCHASE')['unit_price']);
  $tax=DB::table('tax_codes')->insertGetId(['company_id'=>1,'tax_code'=>'TEST15','tax_name'=>'Synthetic15','tax_type'=>'VAT','rate'=>'15']);
  $quoted=(new \App\Services\Uom\UnitPriceQuote)->calculate(1,$payload,'2',$tax,'2026-10-14');self::assertSame('40.000',$quoted['gross']);self::assertSame('38.000',$quoted['taxable']);self::assertSame('5.700',$quoted['vat']);self::assertSame('43.700',$quoted['total']);
  $old=app(\App\Services\TaxEngineService::class)->line(1,40,2,$tax,'2026-10-14','SALES');self::assertEquals($old['line_total'],$quoted['total']);
  $this->failCall(fn()=>$u->revisePrice(1,1,$map,'USD','1','2'),'CURRENCY_SCOPE');$this->failCall(fn()=>$u->revisePrice(1,1,$map,'SAR','-1','2'),'INVALID');
  DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'Other']);$this->failCall(fn()=>(new S)->save(1,3,1,$map,$price,'1','SALE','foreign-branch','x','1'),'AUTH_BRANCH');
 }
 public function test_cost_conservation_pieces_meter_litre_and_residual():void{
  $this->bootContracts();$c=new C;$s=$this->source(1,2,'24','3','120','180','cost');$id=$c->create(1,$s->id,'360');$r=$c->issue(1,$id,'5','issue');self::assertSame('25.000',$r->value);self::assertSame('67.000000',$r->remaining_quantity);self::assertSame('335.000',$r->remaining_value);self::assertSame($r->id,$c->issue(1,$id,'5','issue')->id);$this->failCall(fn()=>$c->issue(1,$id,'6','issue'),'IDEMPOTENCY');
  $s2=$this->source(2,1,'1','10','7','9','second');$second=$c->create(1,$s2->id,'70');self::assertSame('7.000',$c->issue(1,$second,'1','second-out')->value);
  (new U)->registerNew(1,3,5);$m=$this->source(3,5,'1','12.5','8','9','meter');$meter=$c->create(1,$m->id,'100');self::assertSame('18.000',$c->issue(1,$meter,'2.25','meter-out')->value);
  (new U)->registerNew(1,4,6);$l=$this->source(4,6,'1','2','10','12','litre');$litre=$c->create(1,$l->id,'20');self::assertSame('2.500',$c->issue(1,$litre,'0.250000','litre-out')->value);
  $round=$this->source(2,1,'1','3','0.333333','1','round');$pool=$c->create(1,$round->id,'1');$values=[];foreach([1,2,3] as $i)$values[]=$c->issue(1,$pool,'1','round-'.$i)->value;self::assertSame(['0.333','0.333','0.334'],$values);self::assertSame('0.000',DB::table('uom_cost_contracts')->where('id',$pool)->value('remaining_value'));
  $this->failCall(fn()=>$c->issue(1,$pool,'1','excess'),'SHORTAGE');
  $this->failCall(fn()=>DB::table('uom_cost_contracts')->where('id',$id)->update(['remaining_value'=>'999']),'CONSERVATION');
  $before=DB::table('uom_cost_contracts')->where('id',$id)->first();
  try{DB::transaction(function()use($c,$id){$c->issue(1,$id,'1','fault');throw new \RuntimeException('injected fault');});}catch(\RuntimeException $e){self::assertSame('injected fault',$e->getMessage());}
  self::assertSame($before->remaining_value,DB::table('uom_cost_contracts')->where('id',$id)->value('remaining_value'));self::assertSame(0,DB::table('uom_cost_contract_issues')->where('operation_key','fault')->count());
 }
 public function test_read_only_and_permissions_and_rollback():void{
  $this->bootContracts();$s=$this->source(1,2,'24','3','120','180','auth');$id=Auth::id();$role=DB::table('roles')->insertGetId(['role_code'=>'READ_ONLY','role_name'=>'Read only']);DB::table('user_roles')->where('user_id',$id)->update(['role_id'=>$role]);$pid=DB::table('permissions')->where('permission_code','items.view')->value('id');DB::table('role_permissions')->insert(['company_id'=>1,'role_id'=>$role,'permission_id'=>$pid]);
  self::assertSame($s->id,(new S)->get(1,$s->id)->id);$this->failCall(fn()=>(new U)->revisePrice(1,1,$s->mapping_id,'SAR','99'),'READ_ONLY');
  DB::table('role_permissions')->delete();$this->failCall(fn()=>(new S)->get(1,$s->id),'PERMISSION');
 }
 public function test_two_actual_processes_serialize_cost_issue_and_cancel_waiter():void{
  $this->bootContracts();$s=$this->source(1,2,'24','3','120','180','race-source');$id=(new C)->create(1,$s->id,'360');
  DB::beginTransaction();DB::table('uom_cost_contracts')->where('id',$id)->lockForUpdate()->first();$workers=[];
  foreach(['a','b'] as $key){$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/uom_contract_race_worker.php'),'issue',(string)$id,'40','race-'.$key],base_path());$p->setTimeout(20);$p->start();$workers[]=$p;}
  try{
   foreach($workers as $p){$until=microtime(true)+8;while(!str_contains($p->getOutput(),'LOCK_ATTEMPT')&&microtime(true)<$until){usleep(10000);}self::assertStringContainsString('READY',$p->getOutput(),$p->getErrorOutput());self::assertStringContainsString('LOCK_ATTEMPT',$p->getOutput());}
   self::assertSame(0,DB::table('uom_cost_contract_issues')->count());
  }finally{DB::commit();}
  $success=0;$rejected=0;foreach($workers as $p){$p->wait();$success+=str_contains($p->getOutput(),'SUCCESS')?1:0;$rejected+=str_contains($p->getOutput(),'SHORTAGE')?1:0;}
  self::assertSame(1,$success);self::assertSame(1,$rejected);self::assertSame('32.000000',DB::table('uom_cost_contracts')->where('id',$id)->value('remaining_quantity'));self::assertSame('160.000',DB::table('uom_cost_contracts')->where('id',$id)->value('remaining_value'));
  DB::beginTransaction();DB::table('uom_cost_contracts')->where('id',$id)->lockForUpdate()->first();$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/uom_contract_race_worker.php'),'issue',(string)$id,'1','cancelled'],base_path());$p->start();
  try{$until=microtime(true)+8;while(!str_contains($p->getOutput(),'LOCK_ATTEMPT')&&microtime(true)<$until)usleep(10000);self::assertStringContainsString('LOCK_ATTEMPT',$p->getOutput());$p->stop(1);}finally{DB::rollBack();}
  self::assertSame(0,DB::table('uom_cost_contract_issues')->where('operation_key','cancelled')->count());
 }
 public function test_price_publish_and_first_movement_wait_for_definition_transaction():void{
  $this->bootContracts();$s=$this->source(1,2,'24','3','120','180','price-lock');
  DB::beginTransaction();DB::table('items')->where('id',1)->lockForUpdate()->first();$saved=(new S)->save(1,1,1,$s->mapping_id,$s->price_id,'3','PURCHASE','during-price','prepared-price','1');
  $p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/uom_contract_race_worker.php'),'price',(string)$s->mapping_id,'99','199'],base_path());$p->setTimeout(20);$p->start();
  try{$until=microtime(true)+8;while(!str_contains($p->getOutput(),'LOCK_ATTEMPT')&&microtime(true)<$until)usleep(10000);self::assertStringContainsString('LOCK_ATTEMPT',$p->getOutput());}finally{DB::commit();}
  $p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());self::assertSame('120.000000',json_decode((new S)->get(1,$saved->id)->payload,true)['unit_price']);
  DB::beginTransaction();DB::table('items')->where('id',5)->lockForUpdate()->first();$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/uom_contract_race_worker.php'),'legacy','5','unused','unused'],base_path());$p->setTimeout(20);$p->start();
  try{$until=microtime(true)+8;while(!str_contains($p->getOutput(),'LOCK_ATTEMPT')&&microtime(true)<$until)usleep(10000);self::assertStringContainsString('LOCK_ATTEMPT',$p->getOutput());(new U)->registerNew(1,5,5);}finally{DB::commit();}
  $p->wait();self::assertStringContainsString('NOT_APPROVED',$p->getOutput());self::assertSame(0,DB::table('stock_movements')->where('source_type','UOM_CLOSURE_RACE')->count());
 }
 public function test_foundation_preserves_preexisting_weight_rows():void{
  \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row){$t=array_values((array)$row)[0];DB::unprepared('DROP TABLE `'.$t.'`');}DB::statement('SET FOREIGN_KEY_CHECKS=1');DB::unprepared(file_get_contents(base_path('database/schema/mysql-schema.sql')));
  DB::table('companies')->insert(['id'=>1,'company_name'=>'Isolated']);DB::table('branches')->insert(['id'=>1,'company_id'=>1,'branch_name'=>'Test']);DB::table('items')->insert(['id'=>10,'company_id'=>1,'item_name'=>'Old weight','item_code'=>'OLD','base_unit_code'=>'KG']);
  $id=app(\App\Services\InventoryLotService::class)->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>10,'qty_kg'=>'100','base_cost'=>'200','received_at'=>'2026-01-01','source_type'=>'OPENING','lot_number'=>'OLD-100']);$before=(array)DB::table('inventory_lots')->where('id',$id)->first();$movement=(array)DB::table('inventory_lot_movements')->first();
  config(['uom.schema_install_approved'=>true]);$m=require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php');$m->up();self::assertSame($before,(array)DB::table('inventory_lots')->where('id',$id)->first());self::assertSame($movement,(array)DB::table('inventory_lot_movements')->first());
 }
}
