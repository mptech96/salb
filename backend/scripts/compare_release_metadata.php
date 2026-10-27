<?php
/** Offline comparison only: never connects to any database or executes supplied SQL. */
if(PHP_SAPI!=='cli'||count($argv)!==3)throw new RuntimeException('Usage: php compare_release_metadata.php preflight.json mysql-schema.sql');
$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(($j['mode']??'')!=='READ_ONLY_PREFLIGHT'||($j['writes_performed']??true)!==false)throw new RuntimeException('Unexpected evidence');
$sets=$j['metadata'];$tables=array_column($sets[2],null,'TABLE_NAME');$cols=[];$indexes=[];$fk=[];
foreach($sets[3]as$r)$cols[$r['TABLE_NAME']][$r['COLUMN_NAME']]=$r;
foreach($sets[4]as$r)$indexes[$r['TABLE_NAME']][$r['INDEX_NAME']][]=$r;
foreach($sets[5]as$r)if($r['REFERENCED_TABLE_NAME']!==null)$fk[$r['TABLE_NAME']][$r['CONSTRAINT_NAME']][]=$r;
$sql=file_get_contents($argv[2]);preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=([^ ;]+).*?COLLATE=([^ ;]+);/s',$sql,$blocks,PREG_SET_ORDER);
if(!$blocks)throw new RuntimeException('Unsupported baseline format');
$norm=fn($s)=>preg_replace('/\b(bigint|int|smallint|tinyint|mediumint)\(\d+\)/i','$1',strtolower($s));
$issues=[];$counts=['tables'=>0,'columns'=>0,'index_entries'=>0,'foreign_keys'=>0];$expectedTables=[];$expectedIndexes=[];$expectedFks=[];
foreach($blocks as$b){[$all,$table,$body,$engine,$collation]=$b;$expectedTables[]=$table;$counts['tables']++;
 if(!isset($tables[$table])){$issues[]=['missing_table',$table];continue;}
 if(strcasecmp($tables[$table]['ENGINE'],$engine)||$tables[$table]['TABLE_COLLATION']!==$collation)$issues[]=['table_engine_collation',$table,$engine,$collation,$tables[$table]];
 foreach(explode("\n",$body)as$line){$line=trim($line);
  if(preg_match('/^`([^`]+)`\s+((?:enum\([^\n]+?\)|[a-z]+(?:\([\d,]+\))?)(?: unsigned)?)/i',$line,$m)){
   $counts['columns']++;$actual=$cols[$table][$m[1]]??null;
   if(!$actual)$issues[]=['missing_column',$table,$m[1]];
   elseif($norm($m[2])!==$norm($actual['COLUMN_TYPE'])||((str_contains($line,'NOT NULL')?'NO':'YES')!==$actual['IS_NULLABLE']))$issues[]=['column_type_nullable',$table,$m[1],$m[2],$actual];
  }elseif(preg_match('/^(PRIMARY KEY|UNIQUE KEY `([^`]+)`|KEY `([^`]+)`) \(([^)]+)\)/',$line,$m)){
   $name=$m[1]==='PRIMARY KEY'?'PRIMARY':($m[2]?:$m[3]);$expectedIndexes[$table][$name]=true;$unique=!str_starts_with($m[1],'KEY');preg_match_all('/`([^`]+)`(?:\((\d+)\))?/',$m[4],$parts,PREG_SET_ORDER);$expected=[];
   foreach($parts as$i=>$p){$expected[]=[$p[1],isset($p[2])?(int)$p[2]:null,$unique?0:1,$i+1];$counts['index_entries']++;}
   $actual=array_map(fn($r)=>[$r['COLUMN_NAME'],$r['SUB_PART'],$r['NON_UNIQUE'],$r['SEQ_IN_INDEX']],$indexes[$table][$name]??[]);
   if($actual!==$expected)$issues[]=['index_definition',$table,$name,$expected,$actual];
  }elseif(preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY \((.*?)\) REFERENCES `([^`]+)` \((.*?)\)/',$line,$m)){
   $counts['foreign_keys']++;$expectedFks[$table][$m[1]]=true;preg_match_all('/`([^`]+)`/',$m[2],$a);preg_match_all('/`([^`]+)`/',$m[4],$z);$expected=[];foreach($a[1]as$i=>$c)$expected[]=[$c,$m[3],$z[1][$i]];
   $actual=array_map(fn($r)=>[$r['COLUMN_NAME'],$r['REFERENCED_TABLE_NAME'],$r['REFERENCED_COLUMN_NAME']],$fk[$table][$m[1]]??[]);if($actual!==$expected)$issues[]=['fk_definition',$table,$m[1],$expected,$actual];
   foreach($sets[8]as$rule)if($rule['TABLE_NAME']===$table&&$rule['CONSTRAINT_NAME']===$m[1])foreach(['UPDATE','DELETE']as$action){preg_match('/ON '.$action.' (CASCADE|RESTRICT|SET NULL|NO ACTION)/',$line,$m2);$normalize=fn($v)=>$v==='NO ACTION'?'RESTRICT':$v;if($normalize($m2[1]??'RESTRICT')!==$normalize($rule[$action.'_RULE']))$issues[]=['fk_action',$table,$m[1],$action];}
  }
 }
}
$extraColumns=[];foreach($cols as$t=>$cs){if(!in_array($t,$expectedTables,true))continue;foreach($cs as$c=>$r)if(!preg_match('/CREATE TABLE `'.preg_quote($t,'/').'` \((?:(?!\) ENGINE=).)*?\n\s*`'.preg_quote($c,'/').'`/s',$sql))$extraColumns[]="$t.$c";}
$extraIndexes=[];foreach($indexes as$t=>$is)foreach($is as$n=>$rows)if(!isset($expectedIndexes[$t][$n]))$extraIndexes[]=['table'=>$t,'name'=>$n,'entries'=>$rows];
$catalog=json_decode(file_get_contents(__DIR__.'/../../ops/sulb-rc/migration-catalog.json'),true,512,JSON_THROW_ON_ERROR);$migrationAudit=[];
foreach($catalog['uom_order']as$m){$path=__DIR__.'/../../'.$m['path'];$source=file_get_contents($path);preg_match_all('/CREATE TABLE\s+(\w+)/',$source,$names);$collision=array_values(array_intersect($names[1],array_keys($tables)));$migrationAudit[]=['sequence'=>$m['sequence'],'path'=>$m['path'],'existing_created_table_collisions'=>$collision,'qa_guard'=>str_contains($source,'UomStageAProof::guard()'),'production_approved'=>$m['production_approved']];}
echo json_encode(['schema_sha256'=>$j['schema_sha256'],'baseline_compared'=>$counts,'production_counts'=>['tables'=>count($sets[2]),'columns'=>count($sets[3]),'index_entries'=>count($sets[4]),'fk_rules'=>count($sets[8])],'issues'=>$issues,'extra_tables'=>array_values(array_diff(array_keys($tables),$expectedTables)),'extra_columns'=>$extraColumns,'extra_indexes'=>$extraIndexes,'migration_audit'=>$migrationAudit,'limits'=>['column collation/defaults not collected','CHECK bodies hashed only','trigger visibility incomplete','metadata cannot validate historical rows or financial values','static migration inventory is not an execution rehearsal']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
