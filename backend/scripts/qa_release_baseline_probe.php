<?php
/** Execute the proposed metadata-only SQL against the exact owned synthetic QA server. */
$directory=trim(file_get_contents(__DIR__.'/../storage/framework/testing/resume-qa-dir.txt'));
if(!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('Owned QA directory required');
$schema=$argv[1]??'';if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Synthetic schema required');
$pdo=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$pdo->query('SELECT @@datadir')->fetchColumn();if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$directory),'/'))throw new RuntimeException('Wrong QA server');
$sql=preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/release_schema_baseline_readonly.sql'));$result=[];
try{foreach(explode(';',$sql) as $query){$query=trim($query);if($query==='')continue;if(!preg_match('/^(START TRANSACTION READ ONLY|ROLLBACK|SELECT\s)/i',$query))throw new RuntimeException('Non-read-only baseline statement');$statement=$pdo->query($query);if(str_starts_with($query,'SELECT'))$result[]=$statement->fetchAll(PDO::FETCH_ASSOC);}}
finally{if($pdo->inTransaction())$pdo->rollBack();}
$path=__DIR__.'/../storage/framework/testing/extra-cost-schema-baseline.json';file_put_contents($path,json_encode(['writes_performed'=>false,'metadata'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['writes_performed'=>false,'result_sets'=>count($result),'row_counts'=>array_map('count',$result),'artifact_sha256'=>hash_file('sha256',$path)]).PHP_EOL;
