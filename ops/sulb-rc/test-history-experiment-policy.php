<?php
require_once __DIR__.'/history-archive.php';require_once __DIR__.'/history-fifo-audit.php';
$n=0;$assert=function($v)use(&$n){if(!$v)throw new RuntimeException('HISTORY_POLICY_ASSERTION');$n++;};
$lots=[['id'=>'1','company_id'=>'1','branch_id'=>'1','item_id'=>'1','received_at'=>'2026-01-01 00:00:00','qty_remaining_kg'=>'6'],['id'=>'2','company_id'=>'1','branch_id'=>'1','item_id'=>'1','received_at'=>'2026-01-02 00:00:00','qty_remaining_kg'=>'10']];
$in=fn($id)=>['inventory_lot_id'=>(string)$id,'company_id'=>'1','branch_id'=>'1','item_id'=>'1','movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>(string)$id,'qty_kg'=>'10'];
$out=fn($id,$q,$s)=>array_replace($in($id),['movement_type'=>'OUT','source_type'=>'SALE','source_id'=>(string)$s,'qty_kg'=>$q]);
$assert(historyFifoEvidence($lots,[$in(1),$in(2),$out(1,'4',1)])['ready']);
$wrong=$lots;$wrong[0]['qty_remaining_kg']='10';$wrong[1]['qty_remaining_kg']='6';$r=historyFifoEvidence($wrong,[$in(1),$in(2),$out(2,'4',1)]);$assert(!$r['ready']&&in_array('FIFO_ALLOCATION_MISMATCH',array_column($r['exceptions'],'reason'),true));
$split=$lots;$split[0]['qty_remaining_kg']='0';$split[1]['qty_remaining_kg']='8';$assert(historyFifoEvidence($split,[$in(1),$in(2),$out(2,'2',1),$out(1,'10',1)])['ready']);
foreach([['company_id'=>'2'],['branch_id'=>'2'],['item_id'=>'2']]as$change){$r=historyFifoEvidence($lots,[$in(1),$in(2),array_replace($out(1,'4',1),$change)]);$assert(!$r['ready']&&in_array('HISTORY_FIFO_SCOPE_MISMATCH',array_column($r['exceptions'],'reason'),true));}
$base=$lots;foreach($base as&$l){$l['uom_version']='BASE_V1';$l['base_uom_id_snapshot']='9';$l['remaining_base_qty']=$l['qty_remaining_kg'];$l['qty_remaining_kg']='999';}unset($l);
$moves=[];foreach([$in(1),$in(2),$out(1,'4',1)]as$m)$moves[]=array_replace($m,['uom_version'=>'BASE_V1','base_uom_id_snapshot'=>'9','base_quantity'=>$m['qty_kg'],'qty_kg'=>'999']);
$assert(historyFifoEvidence($base,$moves)['ready']);$moves[2]['base_uom_id_snapshot']='10';$assert(!historyFifoEvidence($base,$moves)['ready']);
$missing=$lots;$missing[0]['received_at']=null;$assert(!historyFifoEvidence($missing,[$in(1),$out(1,'4',1)])['ready']);
$r=historyFifoEvidence($lots,[$in(99)]);$assert(in_array('HISTORY_FIFO_LOT_REQUIRED',array_column($r['exceptions'],'reason'),true));
$interleave=$lots;$interleave[0]['qty_remaining_kg']='7';$r=historyFifoEvidence($interleave,[$in(1),$in(2),$out(1,'1',1),$out(1,'1',2),$out(1,'1',1)]);$assert(in_array('FIFO_INTERLEAVED_EVENT_REVIEW',array_column($r['exceptions'],'reason'),true));
$table=['ddl'=>"CREATE TABLE `t` (\n `id` bigint unsigned NOT NULL,\n PRIMARY KEY (`id`)\n) ENGINE=InnoDB",'columns'=>[['COLUMN_NAME'=>'id','DATA_TYPE'=>'bigint']],'estimated_bytes'=>1];
[$sql,$args]=historyReadPage('t',$table,['id'=>'18446744073709551610'],1);$assert(str_contains($sql,'UNSIGNED')&&!str_contains($sql,'OFFSET')&&$args===['18446744073709551610']);
$composite=['ddl'=>"CREATE TABLE `t` (\n `a` bigint NOT NULL,\n `id` bigint unsigned NOT NULL,\n PRIMARY KEY (`a`,`id`)\n) ENGINE=InnoDB",'columns'=>[['COLUMN_NAME'=>'a','DATA_TYPE'=>'bigint'],['COLUMN_NAME'=>'id','DATA_TYPE'=>'bigint']],'estimated_bytes'=>1];
[$sql,$args]=historyReadPage('t',$composite,['a'=>'2','id'=>'4'],128);$assert(str_contains($sql,' OR ')&&$args===['2','2','4']);
$bad=$table;$bad['ddl']="CREATE TABLE `t` (`id` bigint) ENGINE=InnoDB";$bad['estimated_bytes']=9000000;$caught=false;try{historyReadPage('t',$bad,null,128);}catch(RuntimeException $e){$caught=$e->getMessage()==='HISTORY_UNKEYED_TABLE_READ_BUDGET';}$assert($caught);
$caught=false;try{historyReadPage('t',$table,null,129);}catch(RuntimeException $e){$caught=true;}$assert($caught);
echo "PASS $n new FIFO/paging policy checks; production_connections=0\n";
