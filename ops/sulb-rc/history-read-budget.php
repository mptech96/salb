<?php
/** Bounded source reads in one RR snapshot. Never grants privileges or writes source. */
function historyReadKeys(array $table):array {
    if(!preg_match('/PRIMARY KEY \(([^\n]+)\)/',$table['ddl'],$m))return [];
    if(!preg_match('/^`[A-Za-z0-9_]+`(?:,`[A-Za-z0-9_]+`)*$/D',$m[1]))return [];
    preg_match_all('/`([^`]+)`/',$m[1],$names);return $names[1];
}
function historyReadParameter(array $table,string $name):string {
    if(!preg_match('/^\s*`'.preg_quote($name,'/').'`\s+([^\n]+)/mi',$table['ddl'],$m))throw new RuntimeException('HISTORY_PRIMARY_KEY_DEFINITION_REQUIRED');
    $def=strtolower($m[1]);
    if(preg_match('/^(tinyint|smallint|mediumint|int|bigint|bit)\b/',$def))return 'CAST(CAST(? AS CHAR) AS '.(str_contains($def,'unsigned')||str_starts_with($def,'bit')?'UNSIGNED':'SIGNED').')';
    if(preg_match('/^(decimal\(\d+,\d+\))/',$def,$d))return 'CAST(CAST(? AS CHAR) AS '.$d[1].')';
    if(preg_match('/^(float|double)\b/',$def))return 'CAST(CAST(? AS CHAR) AS DOUBLE)';
    return '?';
}
function historyReadPage(string $name,array $table,?array $last,int $size):array {
    if($size<1||$size>128)throw new RuntimeException('HISTORY_READ_PAGE_LIMIT');
    $keys=historyReadKeys($table);$sql='SELECT '.historySelectColumns($table['columns']).' FROM '.historyId($name);$params=[];
    if(!$keys){if(($table['estimated_bytes']??0)>8388608)throw new RuntimeException('HISTORY_UNKEYED_TABLE_READ_BUDGET');return [$sql,[]];}
    if($last!==null){$terms=[];foreach($keys as$i=>$key){$term=[];foreach(array_slice($keys,0,$i)as$prior){$term[]=historyId($prior).'='.historyReadParameter($table,$prior);$params[]=$last[$prior];}$term[]=historyId($key).'>'.historyReadParameter($table,$key);$params[]=$last[$key];$terms[]='('.implode(' AND ',$term).')';}$sql.=' WHERE '.implode(' OR ',$terms);}
    $sql.=' ORDER BY '.implode(',',array_map(fn($k)=>historyId($k).' ASC',$keys)).' LIMIT '.$size;
    return [$sql,$params];
}
function historySourceThrottle(float $start,int $bytes,int $rate):void {
    if($rate<1)throw new RuntimeException('HISTORY_READ_RATE_INVALID');
    $delay=$bytes/$rate-(microtime(true)-$start);if($delay>0)usleep((int)min($delay*1000000,20000000));
}
