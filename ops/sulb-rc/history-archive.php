<?php
/** Streaming encrypted history archive. No credentials, CLI or production writes. */
function historyId(string $s):string {if(!preg_match('/^[A-Za-z0-9_]+$/D',$s))throw new RuntimeException('HISTORY_IDENTIFIER_INVALID');return '`'.$s.'`';}
function historyDdlShape(string $s):string{return preg_replace('/(\n\) ENGINE=InnoDB(?: [A-Z_]+=[A-Za-z0-9_]+)*) AUTO_INCREMENT=\d+\b/','$1',str_replace("\r\n","\n",$s));}
function historyPrivateJson(string $path,array $value):void {
    $temp=$path.'.partial';$f=fopen($temp,'xb');if(!$f)throw new RuntimeException('HISTORY_NEW_MANIFEST_REQUIRED');
    try{chmod($temp,0600);historyWriteAll($f,json_encode($value,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));if(!fflush($f)||!fsync($f))throw new RuntimeException('HISTORY_FILE_SYNC_FAILED');}finally{fclose($f);}
    if(file_exists($path)||!rename($temp,$path))throw new RuntimeException('HISTORY_MANIFEST_PUBLISH_FAILED');
}
function historyInventory(PDO $db):array {
    $tables=$db->query("SELECT TABLE_NAME,ENGINE,TABLE_TYPE,COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) estimated_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $out=[];
    foreach($tables as$t){
        if($t['TABLE_TYPE']!=='BASE TABLE'||$t['ENGINE']!=='InnoDB')throw new RuntimeException('HISTORY_INNODB_TABLES_ONLY');
        $name=$t['TABLE_NAME'];$row=$db->query('SHOW CREATE TABLE '.historyId($name))->fetch(PDO::FETCH_ASSOC);$ddl=$row['Create Table']??'';
        if(!preg_match('/^CREATE TABLE `'.preg_quote($name,'/').'`\s*\(/D',$ddl)
          ||preg_match('/\bREFERENCES\s+`?\w+`?\s*\.\s*`?\w+/i',$ddl)||preg_match('/\b(DATA DIRECTORY|INDEX DIRECTORY|CONNECTION)\s*=/i',$ddl))throw new RuntimeException('HISTORY_DDL_REQUIRES_REVIEW');
        $q=$db->prepare('SELECT COLUMN_NAME,DATA_TYPE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$q->execute([$name]);$cols=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as$c)if(in_array(strtolower($c['DATA_TYPE']),['geometry','point','linestring','polygon','multipoint','multilinestring','multipolygon','geometrycollection','vector'],true))throw new RuntimeException('HISTORY_COLUMN_REQUIRES_REVIEW');
        $out[$name]=['ddl'=>$ddl,'columns'=>$cols,'estimated_bytes'=>(int)$t['estimated_bytes']];
    }
    return $out;
}
function historyInventoryHash(array $inventory):string {
    $shapes=[];foreach($inventory as$name=>$t)$shapes[$name]=['ddl'=>historyDdlShape($t['ddl']),'columns'=>$t['columns']];
    return hash('sha256',json_encode($shapes,JSON_THROW_ON_ERROR));
}
function historyMatchPinned(array $inventory,array $capture):void {
    $expected=[];foreach($capture['definitions']as$o){
        if($o['kind']!=='TABLE')throw new RuntimeException('HISTORY_PINNED_OBJECT_KIND_CHANGED');
        $values=array_values(array_filter($o['ddl'][0],fn($v,$k)=>str_starts_with(strtolower((string)$k),'create '),ARRAY_FILTER_USE_BOTH));
        if(count($values)!==1)throw new RuntimeException('HISTORY_PINNED_DDL_INVALID');$expected[$o['name']]=historyDdlShape($values[0]);
    }
    if(array_keys($expected)!==array_keys($inventory)){ksort($expected);$actual=$inventory;ksort($actual);if(array_keys($expected)!==array_keys($actual))throw new RuntimeException('HISTORY_SCHEMA_DRIFT');}
    foreach($inventory as$name=>$t)if($expected[$name]!==historyDdlShape($t['ddl']))throw new RuntimeException('HISTORY_SCHEMA_DRIFT');
}
function historySelectColumns(array $columns):string {
    return implode(',',array_map(function($c){$id=historyId($c['COLUMN_NAME']);return match(strtolower($c['DATA_TYPE'])){'bit'=>'CAST(CAST('.$id.' AS UNSIGNED) AS CHAR CHARACTER SET ascii) AS '.$id,'float','double'=>'CAST('.$id.' AS CHAR CHARACTER SET ascii) AS '.$id,default=>$id};},$columns));
}
function historyRow(array $row):array {
    $bytes=0;$values=[];foreach($row as$v){if($v!==null&&!is_string($v)&&!is_int($v))throw new RuntimeException('HISTORY_LOSSY_FLOAT_FORBIDDEN');if($v!==null)$bytes+=strlen((string)$v);if($bytes>8388608)throw new RuntimeException('HISTORY_ROW_SIZE_LIMIT');$values[]=$v===null?null:base64_encode((string)$v);}return $values;
}
function historyStatsInit():array{return ['rows'=>0,'xor'=>str_repeat("\0",32),'sum'=>str_repeat("\0",32)];}
function historyStatsAdd(array &$s,array $values):void {
    $h=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR),true);$s['xor']=$s['xor']^$h;$carry=0;$sum=$s['sum'];
    for($i=31;$i>=0;$i--){$n=ord($sum[$i])+ord($h[$i])+$carry;$sum[$i]=chr($n&255);$carry=$n>>8;}$s['sum']=$sum;$s['rows']++;
}
function historyStatsFinish(array $s):array{return ['rows'=>$s['rows'],'xor_sha256'=>bin2hex($s['xor']),'sum_sha256'=>bin2hex($s['sum'])];}
function historyWriteAll($f,string $bytes):void {for($p=0,$n=strlen($bytes);$p<$n;){$w=fwrite($f,substr($bytes,$p));if(!$w)throw new RuntimeException('HISTORY_FILE_WRITE_FAILED');$p+=$w;}}
function historyFrameWrite($f,string &$state,array $record,bool $final=false):void {
    $plain=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($plain)>16777216)throw new RuntimeException('HISTORY_FRAME_SIZE_LIMIT');
    $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$plain,'SULB_HISTORY_V1',$final?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:0);
    historyWriteAll($f,pack('N',strlen($cipher)).$cipher);
}
function historyReadExact($f,int $length):string {$s='';while(strlen($s)<$length){$part=fread($f,$length-strlen($s));if($part===false||$part==='')throw new RuntimeException('HISTORY_ARCHIVE_TRUNCATED');$s.=$part;}return $s;}
function historyRecords(string $file,string $key):Generator {
    $f=fopen($file,'rb');if(!$f)throw new RuntimeException('HISTORY_ARCHIVE_UNAVAILABLE');
    try{
        if(historyReadExact($f,8)!=="SULBHST1")throw new RuntimeException('HISTORY_ARCHIVE_FORMAT');
        $header=historyReadExact($f,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);$state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);
        while(true){$n=unpack('N',historyReadExact($f,4))[1];if($n<17||$n>16777233)throw new RuntimeException('HISTORY_FRAME_SIZE_LIMIT');
            $out=sodium_crypto_secretstream_xchacha20poly1305_pull($state,historyReadExact($f,$n),'SULB_HISTORY_V1');if($out===false)throw new RuntimeException('HISTORY_AUTHENTICATION_FAILED');
            [$plain,$tag]=$out;$record=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
            if($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL){if(($record['kind']??null)!=='end'||fread($f,1)!=='')throw new RuntimeException('HISTORY_FINAL_RECORD_INVALID');yield $record;return;}
            yield $record;
        }
    }finally{fclose($f);}
}
function historyCapture(PDO $source,string $file,string $key,int $maxBytes=1073741824,?callable $afterSnapshot=null):array {
    if(strlen($key)!==32||is_file($file))throw new RuntimeException('HISTORY_NEW_ARCHIVE_REQUIRED');
    $source->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    $source->exec("SET SESSION time_zone='+00:00'");$source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $initial=historyInventory($source);$shape=historyInventoryHash($initial);$f=fopen($file,'xb');if(!$f)throw new RuntimeException('HISTORY_NEW_ARCHIVE_REQUIRED');chmod($file,0600);
    [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);$counts=[];$started=microtime(true);
    try{
        historyWriteAll($f,"SULBHST1".$header);
        $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        if($afterSnapshot)$afterSnapshot();
        foreach($initial as$name=>$table){
            historyFrameWrite($f,$state,['kind'=>'table','name'=>$name,'definition'=>$table]);$stats=historyStatsInit();
            $sql='SELECT '.historySelectColumns($table['columns']).' FROM '.historyId($name);
            $q=$source->query($sql);
            while(($row=$q->fetch(PDO::FETCH_NUM))!==false){$values=historyRow($row);historyStatsAdd($stats,$values);historyFrameWrite($f,$state,['kind'=>'row','values'=>$values]);
                if(ftell($f)>$maxBytes)throw new RuntimeException('HISTORY_ARCHIVE_SIZE_LIMIT');
                if(microtime(true)-$started>3600)throw new RuntimeException('HISTORY_SNAPSHOT_TIME_LIMIT');
                if(($stats['rows']%1024)===0&&disk_free_space(dirname($file))<2147483648)throw new RuntimeException('HISTORY_DISK_RESERVE_REQUIRED');
            }$q->closeCursor();$counts[$name]=historyStatsFinish($stats);historyFrameWrite($f,$state,['kind'=>'table_end','name'=>$name,'stats'=>$counts[$name]]);
        }
        if(historyInventoryHash(historyInventory($source))!==$shape)throw new RuntimeException('HISTORY_DDL_CHANGED_DURING_SNAPSHOT');
        $source->exec('ROLLBACK');historyFrameWrite($f,$state,['kind'=>'end','tables'=>count($counts),'inventory_sha256'=>$shape],true);
        if(!fflush($f)||!fsync($f))throw new RuntimeException('HISTORY_FILE_SYNC_FAILED');
    }catch(Throwable $e){if(isset($q))try{$q->closeCursor();}catch(Throwable $unused){}try{$source->exec('ROLLBACK');}catch(Throwable $unused){}throw $e;}finally{fclose($f);$source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
    $result=['format'=>'SULBHST1','file_sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file),'inventory_sha256'=>$shape,'tables'=>$counts,'consistent_snapshot'=>true];
    $result['manifest_mac']=sodium_bin2hex(sodium_crypto_generichash(json_encode($result,JSON_THROW_ON_ERROR),sodium_crypto_generichash('manifest-key',$key,32),32));
    return $result;
}
function historyVerify(string $file,string $key,array $manifest):void {
    $mac=$manifest['manifest_mac']??'';unset($manifest['manifest_mac']);
    $expected=sodium_bin2hex(sodium_crypto_generichash(json_encode($manifest,JSON_THROW_ON_ERROR),sodium_crypto_generichash('manifest-key',$key,32),32));
    if(!hash_equals($expected,$mac)||!hash_equals($manifest['file_sha256'],hash_file('sha256',$file)))throw new RuntimeException('HISTORY_FILE_INTEGRITY_FAILED');
    $name=null;$seen=[];$end=false;
    foreach(historyRecords($file,$key)as$r){
        if($r['kind']==='table'){if($name!==null||isset($seen[$r['name']]))throw new RuntimeException('HISTORY_RECORD_ORDER_INVALID');$name=$r['name'];$stats=historyStatsInit();}
        elseif($r['kind']==='row'){if($name===null)throw new RuntimeException('HISTORY_RECORD_ORDER_INVALID');historyStatsAdd($stats,$r['values']);}
        elseif($r['kind']==='table_end'){if($name!==$r['name']||historyStatsFinish($stats)!==$r['stats']||($manifest['tables'][$name]??null)!==$r['stats'])throw new RuntimeException('HISTORY_ROW_DIGEST_MISMATCH');$seen[$name]=true;$name=null;}
        elseif($r['kind']==='end'){$end=true;if($name!==null||$r['tables']!==count($seen)||$r['inventory_sha256']!==$manifest['inventory_sha256'])throw new RuntimeException('HISTORY_FINAL_RECORD_INVALID');}
        else throw new RuntimeException('HISTORY_RECORD_ORDER_INVALID');
    }
    if(!$end||count($seen)!==count($manifest['tables']))throw new RuntimeException('HISTORY_ARCHIVE_TRUNCATED');
}
function historyRestore(PDO $target,string $owned,string $expectedDatadir,string $file,string $key,array $manifest):array {
    if(!preg_match('/^sulb_hist_restore_[a-f0-9]{16}$/D',$owned)||$owned==='sulbprod')throw new RuntimeException('HISTORY_OWNED_RESTORE_DATABASE_REQUIRED');
    $target->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$target->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    try{
    $actual=$target->query('SELECT DATABASE() db,@@datadir datadir,VERSION() version')->fetch(PDO::FETCH_ASSOC);
    if($actual['db']!==$owned||rtrim(str_replace('\\','/',$actual['datadir']),'/')!==rtrim(str_replace('\\','/',$expectedDatadir),'/')||!preg_match('#/sulb-shipment-[a-f0-9]{32}$#D',rtrim(str_replace('\\','/',$expectedDatadir),'/'))||!preg_match('/^8\.4\./',$actual['version']))throw new RuntimeException('HISTORY_OWNED_RESTORE_SERVER_REQUIRED');
    if($target->query('SHOW TABLES')->fetchAll()!==[])throw new RuntimeException('HISTORY_EMPTY_RESTORE_DATABASE_REQUIRED');
    historyVerify($file,$key,$manifest); // Entire authenticated archive checked BEFORE any DDL/INSERT.
    $target->exec("SET SESSION time_zone='+00:00'");$mode=(string)$target->query('SELECT @@session.sql_mode')->fetchColumn();
    $target->exec('SET SESSION sql_mode='.$target->quote($mode.',NO_AUTO_VALUE_ON_ZERO'));$target->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        foreach(historyRecords($file,$key)as$r){
            if($r['kind']==='table'){
                $name=$r['name'];$definition=$r['definition'];historyId($name);$ddl=$definition['ddl'];
                if(!preg_match('/^CREATE TABLE `'.preg_quote($name,'/').'`\s*\(/D',$ddl)||!preg_match('/\bENGINE=InnoDB\b/i',$ddl)||preg_match('/\bREFERENCES\s+`?\w+`?\s*\.\s*`?\w+/i',$ddl)||preg_match('/\b(DATA DIRECTORY|INDEX DIRECTORY|CONNECTION)\s*=/i',$ddl))throw new RuntimeException('HISTORY_RESTORE_DDL_INVALID');
                $target->exec($ddl);$insertCols=[];$indices=[];$placeholders=[];
                foreach($definition['columns']as$i=>$c)if(($c['GENERATION_EXPRESSION']??'')===''){$insertCols[]=$c['COLUMN_NAME'];$indices[]=$i;$placeholders[]=strtolower($c['DATA_TYPE'])==='bit'?'CAST(CAST(? AS CHAR) AS UNSIGNED)':'?';}
                $sql='INSERT INTO '.historyId($owned).'.'.historyId($name).' ('.implode(',',array_map('historyId',$insertCols)).') VALUES ('.implode(',',$placeholders).')';$insert=$target->prepare($sql);
            }elseif($r['kind']==='row'){
                $values=[];foreach($indices as$i){$v=$r['values'][$i];$decoded=$v===null?null:base64_decode($v,true);if($decoded===false)throw new RuntimeException('HISTORY_VALUE_ENCODING_INVALID');$values[]=$decoded;}$insert->execute($values);
            }
        }
    }finally{$target->exec('SET FOREIGN_KEY_CHECKS=1');}
    $restoredInventory=historyInventory($target);
    foreach($manifest['tables']as$name=>$expected){$stats=historyStatsInit();
        $query=$target->query('SELECT '.historySelectColumns($restoredInventory[$name]['columns']).' FROM '.historyId($owned).'.'.historyId($name));
        while(($row=$query->fetch(PDO::FETCH_NUM))!==false)historyStatsAdd($stats,historyRow($row));$query->closeCursor();
        if(historyStatsFinish($stats)!==$expected)throw new RuntimeException('HISTORY_RESTORE_DIGEST_MISMATCH');
    }
    $q=$target->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');$fks=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r){if($r['REFERENCED_TABLE_SCHEMA']!==$owned)throw new RuntimeException('HISTORY_EXTERNAL_FK_FORBIDDEN');$fks[$r['TABLE_NAME'].'.'.$r['CONSTRAINT_NAME']][]=$r;}
    $orphans=0;foreach($fks as$fk){$join=[];$nonnull=[];foreach($fk as$r){$join[]='c.'.historyId($r['COLUMN_NAME']).'=p.'.historyId($r['REFERENCED_COLUMN_NAME']);$nonnull[]='c.'.historyId($r['COLUMN_NAME']).' IS NOT NULL';}
        $sql='SELECT COUNT(*) FROM '.historyId($fk[0]['TABLE_NAME']).' c LEFT JOIN '.historyId($fk[0]['REFERENCED_TABLE_NAME']).' p ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$nonnull).' AND p.'.historyId($fk[0]['REFERENCED_COLUMN_NAME']).' IS NULL';$orphans+=(int)$target->query($sql)->fetchColumn();
    }
    return ['restore_integrity'=>'PASS','row_digests_match'=>true,'foreign_key_orphans'=>$orphans,'historical_validation_passed'=>false,'release_decision'=>'NO-GO'];
    }finally{$target->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
}
