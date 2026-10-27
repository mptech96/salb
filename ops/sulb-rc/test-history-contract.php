<?php
require_once __DIR__.'/history-capture.php';
require_once __DIR__.'/history-native-context.php';
$checks=0;$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('HISTORY_CONTRACT_FAILED');$checks++;};
$assert(!historyPolicyAllowsCapture([]));$assert(!historyPolicyAllowsCapture(['history_capture'=>['production_extraction_authorized'=>false]]));
$budget=historyBudget(1048576,10737418240,2147483648);$assert($budget['required_free_bytes']===4294967296&&$budget['max_archive_bytes']>0);
foreach([[1,1024,2147483648,'HISTORY_DISK_HEADROOM_REQUIRED'],[1,10737418240,1024,'HISTORY_MEMORY_HEADROOM_REQUIRED']]as[$size,$free,$mem,$code]){$yes=false;try{historyBudget($size,$free,$mem);}catch(RuntimeException $e){$yes=$e->getMessage()===$code;}$assert($yes);}
$a=historyStatsInit();$b=historyStatsInit();historyStatsAdd($a,['AA==',null]);historyStatsAdd($a,['AQ==']);historyStatsAdd($b,['AQ==']);historyStatsAdd($b,['AA==',null]);$assert(historyStatsFinish($a)===historyStatsFinish($b));historyStatsAdd($b,['AQ==']);$assert(historyStatsFinish($a)!==historyStatsFinish($b));
$yes=false;try{historyRow([0.25]);}catch(RuntimeException $e){$yes=$e->getMessage()==='HISTORY_LOSSY_FLOAT_FORBIDDEN';}$assert($yes);
$yes=false;try{historyId('table`; DROP DATABASE sulbprod');}catch(RuntimeException $e){$yes=true;}$assert($yes);
$ddl="CREATE TABLE `t` (\n`x` varchar(30) DEFAULT ' AUTO_INCREMENT=1'\n) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4";
$shape=historyDdlShape($ddl);$assert(str_contains($shape,"DEFAULT ' AUTO_INCREMENT=1'")&&!str_contains($shape,'AUTO_INCREMENT=123'));
$dir=dirname(__DIR__,2).'/backend/storage/framework/testing/history-contract-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);historyPrivateJson($dir.'/proof.json',['x'=>true]);$assert(json_decode(file_get_contents($dir.'/proof.json'),true)['x']===true);
$yes=false;try{historyPrivateJson($dir.'/proof.json',['x'=>false]);}catch(RuntimeException $e){$yes=true;}$assert($yes);$assert(json_decode(file_get_contents($dir.'/proof.json'),true)['x']===true);
$yes=false;try{historyRestore(new class extends PDO{public function __construct(){}},'sulbprod','/home/mysql','unused','unused',[]);}catch(RuntimeException $e){$yes=$e->getMessage()==='HISTORY_OWNED_RESTORE_DATABASE_REQUIRED';}$assert($yes);
$proc=proc_open([PHP_BINARY,__DIR__.'/history-capture.php','--capture-approved','unused'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);$data=json_decode($out,true,512,JSON_THROW_ON_ERROR);$assert($exit===78&&$data['reason']==='HISTORY_EXTRACTION_NOT_AUTHORIZED'&&$err==='');
$socket='/run/mysqld/mysqld.sock';$data='/home/mysql/sulb-shipment-'.str_repeat('a',32);
$c=['mode'=>'NATIVE_HISTORY_RESTORE','production_connected'=>false,'datadir'=>$data,'socket'=>$socket,'private_net'=>'net:[2]','private_pid'=>'pid:[2]','host_net'=>'net:[1]','host_pid'=>'pid:[1]','masking_complete'=>true,'limits_enforced'=>true,'server_pid'=>2];
historyNativeScope($c,$socket,$data,'net:[2]','pid:[2]');$assert(true);
foreach([['mode'=>'NATIVE_SCHEMA_ONLY'],['host_net'=>'net:[2]'],['host_pid'=>'pid:[2]'],['socket'=>'/production.sock'],['datadir'=>'/home/mysql']]as$change){$yes=false;try{historyNativeScope(array_replace($c,$change),$socket,$data,'net:[2]','pid:[2]');}catch(RuntimeException $e){$yes=true;}$assert($yes);}
$yes=false;try{nativeRehearsalContext($c,$socket,$data,'net:[2]','pid:[2]');}catch(RuntimeException $e){$yes=true;}$assert($yes);
echo "PASS $checks history contracts; database_connections=0; production_extraction_authorized=false\n";
