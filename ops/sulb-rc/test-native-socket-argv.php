<?php
require_once __DIR__.'/native-rehearsal-context.php';
$checks=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('SOCKET_ARGV_REGRESSION_FAILED');$checks++;};
if(($argv[1]??'')==='--python-argv'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $parsed=privateSocketArguments($input);
    $assert(count($input)===7&&$parsed[6]!==null&&$parsed[6]===$input[6]);
    $assert(array_slice($parsed,0,6)===array_slice($input,0,6));
    echo json_encode(['native_context_received'=>true,'argv_count'=>count($input),'checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$socket='/run/mysqld/mysqld.sock';$data='/home/mysql/sulb-shipment-'.str_repeat('a',32);
$input=['run-private-socket-rehearsal.php',$socket,$data,'/owned/schema-private.json',str_repeat('b',64),'/owned/result.json','/owned/native-context.json'];
$parsed=privateSocketArguments($input);$assert($parsed===$input);
$legacy=privateSocketArguments(array_slice($input,0,6));$assert($legacy[6]===null&&array_slice($legacy,0,6)===array_slice($input,0,6));
foreach([array_slice($input,0,5),array_merge($input,['unexpected']),array_replace($input,[6=>'']),array_replace($input,[1=>"/run/socket\0injection"])]as$bad){
    $rejected=false;try{privateSocketArguments($bad);}catch(RuntimeException $e){$rejected=$e->getMessage()==='REHEARSAL_ARGUMENTS_REQUIRED';}$assert($rejected);
}
$c=['mode'=>'NATIVE_SCHEMA_ONLY','production_connected'=>false,'datadir'=>$data,'socket'=>$socket,'private_net'=>'net:[2]','private_pid'=>'pid:[2]','host_net'=>'net:[1]','host_pid'=>'pid:[1]','masking_complete'=>true,'limits_enforced'=>true,'server_pid'=>2];
nativeRehearsalContext($c,$parsed[1],$parsed[2],'net:[2]','pid:[2]');$assert(true);
foreach([['host_net'=>'net:[2]'],['host_pid'=>'pid:[2]'],['production_connected'=>true],['socket'=>'/other/production.sock'],['datadir'=>'/home/mysql']]as$change){
    $bad=array_replace($c,$change);$rejected=false;
    try{nativeRehearsalContext($bad,$parsed[1],$parsed[2],'net:[2]','pid:[2]');}catch(RuntimeException $e){$rejected=true;}$assert($rejected);
}
echo "PASS $checks socket argv/production-rejection checks; database_connections=0\n";
