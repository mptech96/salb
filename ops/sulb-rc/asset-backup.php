<?php
require_once __DIR__.'/history-archive.php';
function captureReleaseAssets(string $source,string $file,string $key,string $log):void {
    $f=fopen($file,'xb');if(!$f)throw new RuntimeException('ASSET_NEW_ARCHIVE_REQUIRED');chmod($file,0600);[$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);$pipes=[];$proc=null;
    try{historyWriteAll($f,'SULBHST1'.$header);historyFrameWrite($f,$state,['kind'=>'asset_header','format'=>'SULB_ASSETS_V1']);$proc=proc_open(['tar','--one-file-system','--exclude=./storage/logs','--exclude=./storage/framework/cache','--exclude=./.next/cache','-czf','-','-C',$source,'.'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$log,'a']],$pipes);if(!is_resource($proc))throw new RuntimeException('ASSET_CAPTURE_COMMAND_FAILED');$hash=hash_init('sha256');$bytes=0;while(!feof($pipes[1])){$chunk=fread($pipes[1],65536);if($chunk===false)throw new RuntimeException('ASSET_CAPTURE_READ_FAILED');if($chunk==='')continue;$bytes+=strlen($chunk);hash_update($hash,$chunk);historyFrameWrite($f,$state,['kind'=>'asset_chunk','bytes'=>base64_encode($chunk)]);}fclose($pipes[1]);if(proc_close($proc)!==0)throw new RuntimeException('ASSET_CAPTURE_COMMAND_FAILED');$proc=null;historyFrameWrite($f,$state,['kind'=>'end','format'=>'SULB_ASSETS_V1','bytes'=>$bytes,'sha256'=>hash_final($hash)],true);if(!fflush($f)||!fsync($f))throw new RuntimeException('ASSET_SYNC_FAILED');}finally{if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}fclose($f);}
}
function streamReleaseAssets(string $file,string $key,$destination):void {
    $header=false;$hash=hash_init('sha256');$bytes=0;$end=false;
    foreach(historyRecords($file,$key)as$r){if(!$header){if(($r['kind']??'')!=='asset_header'||($r['format']??'')!=='SULB_ASSETS_V1')throw new RuntimeException('ASSET_FORMAT_REQUIRED');$header=true;continue;}
        if($r['kind']==='asset_chunk'){$chunk=base64_decode($r['bytes'],true);if($chunk===false)throw new RuntimeException('ASSET_CHUNK_INVALID');historyWriteAll($destination,$chunk);$bytes+=strlen($chunk);hash_update($hash,$chunk);}
        elseif($r['kind']==='end'){if(($r['format']??'')!=='SULB_ASSETS_V1'||$r['bytes']!==$bytes||!hash_equals($r['sha256'],hash_final($hash)))throw new RuntimeException('ASSET_STREAM_DIGEST_MISMATCH');$end=true;}
        else throw new RuntimeException('ASSET_RECORD_INVALID');
    }if(!$end)throw new RuntimeException('ASSET_END_REQUIRED');
}
function verifyReleaseAssets(string $file,string $key,string $log):void {
    $pipes=[];$p=proc_open(['tar','-tzf','-'],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$log,'a']],$pipes);if(!is_resource($p))throw new RuntimeException('ASSET_VERIFY_COMMAND_FAILED');$error=null;try{streamReleaseAssets($file,$key,$pipes[0]);}catch(Throwable $e){$error=$e;}finally{fclose($pipes[0]);$code=proc_close($p);}if($error)throw$error;if($code!==0)throw new RuntimeException('ASSET_TAR_INTEGRITY_FAILED');
}
