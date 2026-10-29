<?php
require __DIR__.'/record-history-approvals.php';
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0){echo "ROOT_PRIVATE_AUDIT_TEST_REQUIRES_ISOLATED_LINUX_ROOT\n";exit(78);}
$dir=dirname(__DIR__,2).'/backend/storage/framework/testing/approval-audit-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('AUDIT_CHECK_'.($n+1));$n++;};$sha=str_repeat('a',64);$identity=str_repeat('b',64);$decision=['packet_sha256'=>$sha,'reviewer'=>'SYNTHETIC ONLY','review_reference'=>'Synthetic audit fixture'];
$r=recordPrivateHistoryDecision($dir,'cost',$decision,$identity);$ok(is_file($r['private_attestation']));$hash=$r['attestation_sha256'];$ok(recordPrivateHistoryDecision($dir,'cost',$decision,$identity)===$r);
foreach([[$decision+['tampered'=>true],$identity],[$decision,str_repeat('c',64)]]as[$d,$i]){$bad=false;try{recordPrivateHistoryDecision($dir,'cost',$d,$i);}catch(RuntimeException){$bad=true;}$ok($bad&&hash_file('sha256',$r['private_attestation'])===$hash);}
$bad=false;try{recordPrivateHistoryDecision($dir,'mapping',['packet_sha256'=>'../outside'],$identity);}catch(RuntimeException){$bad=true;}$ok($bad);$ok((fileperms($r['private_attestation'])&0077)===0);
echo 'PASS '.$n.' new exclusive audit checks; synthetic_only=true; actual_approval_recorded=false'.PHP_EOL;
