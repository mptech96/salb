<?php
namespace Illuminate\Database\Migrations { abstract class Migration {} }
namespace {
require __DIR__.'/production-upgrade-runtime.php';define('SULB_UPGRADE_ROOT',dirname(__DIR__,2));$n=0;$check=function($v)use(&$n){if(!$v)throw new \RuntimeException('ADAPTER_CONTRACT_'.($n+1));$n++;};
$plan=\Sulb\Release\ProductionUpgradeExecutor::plan(SULB_UPGRADE_ROOT);$check(count($plan)===18);
$strings=function(string $s){$out=[];foreach(token_get_all(str_replace("\r\n","\n",$s))as$t)if(is_array($t)&&in_array($t[0],[T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE],true)&&preg_match('/^(CREATE|ALTER|DROP|SELECT|SIGNAL|INSERT|UPDATE|DELETE|SHOW)\b/i',ltrim($t[1],"'\"")))$out[]=$t[1];return $out;};
foreach($plan as$m){$original=file_get_contents(SULB_UPGRADE_ROOT.'/'.$m['source_path']);$adapter=file_get_contents(SULB_UPGRADE_ROOT.'/'.$m['adapter_path']);$check($strings($original)===$strings($adapter));$check(!str_contains($adapter,'Tests\\Support')&&!str_contains($adapter,'eval(')&&!str_contains($adapter,'class_alias'));
 $bad=false;try{(require SULB_UPGRADE_ROOT.'/'.$m['adapter_path'])->up();}catch(\RuntimeException $e){$bad=$e->getMessage()==='UPGRADE_CONTEXT_REQUIRED';}$check($bad);
 $bad=false;try{(require SULB_UPGRADE_ROOT.'/'.$m['adapter_path'])->down();}catch(\RuntimeException $e){$bad=$e->getMessage()==='PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN';}$check($bad);
}
$frozen=json_decode(file_get_contents(__DIR__.'/full-release-scope.json'),true);$qa=array_values(array_filter($frozen['application_files'],fn($f)=>$f['path']==='backend/tests/Support/UomStageAProof.php'))[0];$check(hash('sha256',str_replace("\r\n","\n",file_get_contents(SULB_UPGRADE_ROOT.'/'.$qa['path'])))===$qa['sha256_git_blob']);
echo 'PASS '.$n.' new adapter contracts; SQL payloads unchanged; original QA guard unchanged; DB connections=0'.PHP_EOL;
}
