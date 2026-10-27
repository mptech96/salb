<?php
require_once __DIR__.'/native-rehearsal-context.php';
/** History approval is a different scope; never accept Schema-only context. */
function historyNativeScope(array $c,string $socket,string $datadir,string $net,string $pid):void {
    if(($c['mode']??null)!=='NATIVE_HISTORY_RESTORE')throw new RuntimeException('HISTORY_NATIVE_SCOPE_REQUIRED');
    $geometry=$c;$geometry['mode']='NATIVE_SCHEMA_ONLY';
    nativeRehearsalContext($geometry,$socket,$datadir,$net,$pid);
}
function verifyHistoryNativeContext(string $file,string $socket,string $datadir):void {
    if(!is_file($file)||is_link($file)||fileowner($file)!==0||(fileperms($file)&0077)!==0)throw new RuntimeException('HISTORY_NATIVE_PRIVATE_CONTEXT_REQUIRED');
    $c=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    historyNativeScope($c,$socket,$datadir,(string)readlink('/proc/self/ns/net'),(string)readlink('/proc/self/ns/pid'));
    if(readlink('/proc/'.$c['server_pid'].'/exe')!=='/usr/sbin/mysqld'||readlink('/proc/'.$c['server_pid'].'/ns/net')!==$c['private_net']||readlink('/proc/'.$c['server_pid'].'/ns/pid')!==$c['private_pid'])throw new RuntimeException('HISTORY_NATIVE_SERVER_IDENTITY_REQUIRED');
    $st=stat($socket);if([$st['dev'],$st['ino']]!==$c['socket_identity'])throw new RuntimeException('HISTORY_NATIVE_SOCKET_IDENTITY_REQUIRED');
}
