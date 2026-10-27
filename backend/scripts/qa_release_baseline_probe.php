<?php
/** Execute the proposed metadata-only SQL against the exact owned synthetic QA server. */
require __DIR__.'/release_metadata_collect.php';
$directory=getenv('SULB_SHIPMENT_TEST_DIR')?:trim(file_get_contents(__DIR__.'/../storage/framework/testing/resume-qa-dir.txt'));
if(!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('Owned QA directory required');
$schema=$argv[1]??'';if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Synthetic schema required');
$pdo=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$pdo->query('SELECT @@datadir')->fetchColumn();if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$directory),'/'))throw new RuntimeException('Wrong QA server');
$result=[];
try{foreach(releaseMetadataQueries($pdo)as$query){$statement=$pdo->query($query);if(str_starts_with($query,'SELECT')||str_starts_with($query,'SHOW SESSION VARIABLES'))$result[]=$statement->fetchAll(PDO::FETCH_ASSOC);}}
finally{if($pdo->inTransaction())$pdo->rollBack();}
$family=releaseMetadataServer($pdo)['family'];$path=__DIR__.'/../storage/framework/testing/schema-baseline-'.$family.'.json';file_put_contents($path,json_encode(['writes_performed'=>false,'metadata'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['writes_performed'=>false,'result_sets'=>count($result),'row_counts'=>array_map('count',$result),'artifact_sha256'=>hash_file('sha256',$path)]).PHP_EOL;
