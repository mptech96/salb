<?php
require_once __DIR__.'/native-rehearsal-context.php';
require_once __DIR__.'/private-rehearsal-plan.php';
$checks=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('NATIVE_CONTRACT_TEST_FAILED');$checks++;};
$socket='/run/mysqld/mysqld.sock';$data='/home/mysql/sulb-shipment-'.str_repeat('a',32);
$c=['mode'=>'NATIVE_SCHEMA_ONLY','production_connected'=>false,'datadir'=>$data,'socket'=>$socket,'private_net'=>'net:[2]','private_pid'=>'pid:[2]','host_net'=>'net:[1]','host_pid'=>'pid:[1]','masking_complete'=>true,'limits_enforced'=>true,'server_pid'=>2];
nativeRehearsalContext($c,$socket,$data,'net:[2]','pid:[2]');$assert(true);
foreach(['host_net'=>'net:[2]','host_pid'=>'pid:[2]','masking_complete'=>false,'limits_enforced'=>false,'production_connected'=>true,'server_pid'=>1,'socket'=>'/production.sock','datadir'=>'/home/mysql','private_net'=>'net:[other]']as$key=>$value){
    $bad=$c;$bad[$key]=$value;$rejected=false;
    try{nativeRehearsalContext($bad,$socket,$data,'net:[2]','pid:[2]');}catch(RuntimeException $e){$rejected=true;}
    $assert($rejected);
}
$root=dirname(__DIR__,2);$plan=privateRehearsalPlan($root);
$assert(count($plan)===18);
$assert(array_column(array_slice($plan,0,9),'sequence')===range(1,9));
$assert(array_column(array_slice($plan,13),'sequence')===range(10,14));
$assert(array_map('basename',array_column(array_slice($plan,9,4),'path'))===['stock_movements_company_source_index.php','shipment_cost_parent_fk.php','shipment_cost_allocation_receipts.php','inventory_lot_value_balances.php']);
$assert(!in_array(true,array_column($plan,'production_approved'),true));
$fixture=$root.'/backend/storage/framework/testing/native-plan-tamper-'.bin2hex(random_bytes(8));
mkdir($fixture.'/ops/sulb-rc',0700,true);
copy($root.'/ops/sulb-rc/migration-catalog.json',$fixture.'/ops/sulb-rc/migration-catalog.json');
foreach($plan as$m){$p=$fixture.'/'.$m['path'];if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);copy($root.'/'.$m['path'],$p);}
file_put_contents($fixture.'/'.$plan[9]['path'],"\n// tampered dependency\n",FILE_APPEND);
$rejected=false;try{privateRehearsalPlan($fixture);}catch(RuntimeException $e){$rejected=$e->getMessage()==='REHEARSAL_MIGRATION_HASH_MISMATCH';}
$assert($rejected);
echo "PASS $checks native context/schedule checks; database_connections=0\n";
