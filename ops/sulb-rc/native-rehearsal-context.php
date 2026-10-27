<?php
/** CLI layout: script,socket,datadir,capture,sha,proof[,native-context]. */
function privateSocketArguments(array $args):array {
    if(!in_array(count($args),[6,7],true))throw new RuntimeException('REHEARSAL_ARGUMENTS_REQUIRED');
    foreach($args as$value)if(!is_string($value)||$value===''||str_contains($value,"\0"))throw new RuntimeException('REHEARSAL_ARGUMENTS_REQUIRED');
    return [$args[0],$args[1],$args[2],$args[3],$args[4],$args[5],$args[6]??null];
}
/** No connection is made here. Native PID1 shares the private netns by design. */
function nativeRehearsalContext(array $c,string $socket,string $datadir,string $net,string $pid):void {
    if(($c['mode']??null)!=='NATIVE_SCHEMA_ONLY'||($c['production_connected']??null)!==false
      ||!preg_match('#^/(?:home/mysql|var/lib/mysql)/sulb-shipment-[a-f0-9]{32}$#D',$datadir)
      ||($c['datadir']??null)!==$datadir||($c['socket']??null)!==$socket
      ||!preg_match('/^net:\[\d+\]$/D',$net)||!preg_match('/^pid:\[\d+\]$/D',$pid)
      ||($c['private_net']??null)!==$net||($c['private_pid']??null)!==$pid
      ||($c['host_net']??$net)===$net||($c['host_pid']??$pid)===$pid
      ||($c['masking_complete']??null)!==true||($c['limits_enforced']??null)!==true
      ||!is_int($c['server_pid']??null)||$c['server_pid']<2)
        throw new RuntimeException('NATIVE_PRIVATE_CONTEXT_REQUIRED');
}
function verifyNativeRehearsalContext(string $file,string $socket,string $datadir):void {
    if(!is_file($file)||is_link($file)||fileowner($file)!==0||(fileperms($file)&0077)!==0)throw new RuntimeException('NATIVE_PRIVATE_CONTEXT_REQUIRED');
    $c=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    nativeRehearsalContext($c,$socket,$datadir,(string)readlink('/proc/self/ns/net'),(string)readlink('/proc/self/ns/pid'));
    if(readlink('/proc/'.$c['server_pid'].'/exe')!=='/usr/sbin/mysqld'
      ||readlink('/proc/'.$c['server_pid'].'/ns/net')!==$c['private_net']
      ||readlink('/proc/'.$c['server_pid'].'/ns/pid')!==$c['private_pid'])throw new RuntimeException('NATIVE_PRIVATE_CONTEXT_REQUIRED');
    $st=stat($socket);
    if([$st['dev'],$st['ino']]!==$c['socket_identity'])throw new RuntimeException('NATIVE_PRIVATE_CONTEXT_REQUIRED');
}
