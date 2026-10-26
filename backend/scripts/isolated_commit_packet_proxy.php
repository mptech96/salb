<?php
// Test-only, single connection loopback fault injector. Never loaded by application.
require __DIR__.'/../vendor/autoload.php';
$dir=getenv('SULB_SHIPMENT_TEST_DIR');
if(getenv('APP_ENV')!=='testing'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',getenv('DB_DATABASE')?:'')||!$dir)exit(2);
$guard=new PDO('mysql:host=127.0.0.1;port=3339','root','');
if(rtrim(str_replace('\\','/',$guard->query('SELECT @@datadir')->fetchColumn()),'/')!==rtrim(str_replace('\\','/',$dir),'/'))exit(2);
$mode=$argv[1]??'';if(!in_array($mode,['pass','drop-before','drop-after'],true))exit(2);
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$listener)throw new RuntimeException($error);
fwrite(STDOUT,'PROXY_PORT '.substr(strrchr(stream_socket_get_name($listener,false),':'),1)."\n");fflush(STDOUT);
$client=stream_socket_accept($listener,20);if(!$client)exit(3);
$server=stream_socket_client('tcp://127.0.0.1:3339',$errno,$error,5);if(!$server)throw new RuntimeException($error);
foreach([$client,$server]as$s)stream_set_timeout($s,20);
function packet($s):?string{
 $header='';while(strlen($header)<4){$b=fread($s,4-strlen($header));if($b===false||$b==='')return null;$header.=$b;}
 $n=ord($header[0])+(ord($header[1])<<8)+(ord($header[2])<<16);$body='';
 while(strlen($body)<$n){$b=fread($s,$n-strlen($body));if($b===false||$b==='')return null;$body.=$b;}
 return $header.$body;
}
function sendPacket($s,string $p):void{while($p!==''){$n=fwrite($s,$p);if(!$n)throw new RuntimeException('Proxy write failed');$p=substr($p,$n);}}
$waitingCommit=false;$end=microtime(true)+25;
try{
 while(microtime(true)<$end){
  $read=[$client,$server];$write=$except=null;if(!stream_select($read,$write,$except,2))continue;
  foreach($read as$s){$p=packet($s);if($p===null)break 2;
   if($s===$client){
    $commit=strtoupper(trim(substr($p,5)))==='COMMIT'&&($p[4]??'')==="\x03";
    if($commit){fwrite(STDOUT,"COMMIT_REQUEST_SEEN\n");fflush(STDOUT);if($mode==='drop-before'){fwrite(STDOUT,"COMMIT_NOT_FORWARDED\n");break 2;}$waitingCommit=true;}
    sendPacket($server,$p);
   }else{
    if($waitingCommit){$waitingCommit=false;
     if($mode==='drop-after'){
      if(($p[4]??'')!=="\x00")throw new RuntimeException('Server COMMIT did not return OK');
      fwrite(STDOUT,"SERVER_COMMIT_OK_RESPONSE_DROPPED\n");fflush(STDOUT);break 2;
     }
     fwrite(STDOUT,"COMMIT_OK_DELIVERED\n");fflush(STDOUT);
    }
    sendPacket($client,$p);
   }
  }
 }
}finally{fclose($client);fclose($server);fclose($listener);}
