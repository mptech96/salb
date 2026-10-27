<?php
require_once __DIR__.'/private-migration-contract.php';
$root=dirname(__DIR__,2);$sql=file_get_contents($root.'/backend/database/schema/mysql-schema.sql');
preg_match_all('/CREATE TABLE `([^`]+)` \(.*?\) ENGINE=.*?;/s',$sql,$matches,PREG_SET_ORDER);
$capture=['mode'=>'PRIVATE_SCHEMA_ONLY','business_rows_exported'=>false,'definitions'=>[]];
foreach($matches as$m)$capture['definitions'][]=['kind'=>'TABLE','name'=>$m[1],'ddl'=>[['Create Table'=>$m[0]]]];
$checks=0;$assert=function($yes)use(&$checks){if(!$yes)throw new RuntimeException('CONTRACT_TEST_FAILED');$checks++;};
$result=privateMigrationContract($capture,$root);$assert($result['static_prerequisites']==='PASS');$assert(count($result['migrations'])===14);$assert($result['release_decision']==='NO-GO'&&!$result['execution_rehearsal_passed']);
foreach($capture['definitions']as$i=>$o)if($o['name']==='currencies')$currency=$i;
$changed=$capture;$changed['definitions'][$currency]['ddl'][0]['Create Table']=str_replace('utf8mb4_unicode_ci','utf8mb4_general_ci',$changed['definitions'][$currency]['ddl'][0]['Create Table']);
$assert(isset(privateMigrationContract($changed,$root)['issue_counts']['CURRENCY_FK_COLLATION_MISMATCH']));
$changed=$capture;$changed['definitions'][$currency]['ddl'][0]['Create Table']=str_replace('UNIQUE KEY `currencies_currency_code_unique` (`currency_code`)','KEY `currencies_currency_code_unique` (`currency_code`)',$changed['definitions'][$currency]['ddl'][0]['Create Table']);
$assert(isset(privateMigrationContract($changed,$root)['issue_counts']['CURRENCY_PARENT_UNIQUE_MISSING']));
$changed=$capture;foreach($changed['definitions']as&$o)if($o['name']==='companies')$o['ddl'][0]['Create Table']=str_replace('`id` bigint(20) NOT NULL','`id` bigint(20) unsigned NOT NULL',$o['ddl'][0]['Create Table']);unset($o);
$assert(isset(privateMigrationContract($changed,$root)['issue_counts']['PARENT_TYPE_MISMATCH']));
$changed=$capture;foreach($changed['definitions']as&$o)if($o['name']==='items')$o['ddl'][0]['Create Table']=str_replace("(\n","(\n  `base_uom_id` bigint unsigned DEFAULT NULL,\n",$o['ddl'][0]['Create Table']);unset($o);
$assert(isset(privateMigrationContract($changed,$root)['issue_counts']['EXISTING_UOM_COLUMN_REQUIRES_REVIEW']));
echo "PASS $checks offline prerequisite checks; no production definitions or database access\n";
if(($argv[1]??'')==='--write-synthetic-fixture'){
    $path=$root.'/backend/storage/framework/testing/private-schema-synthetic.json';file_put_contents($path,json_encode($capture,JSON_THROW_ON_ERROR));echo 'SYNTHETIC_FIXTURE_SHA256='.hash_file('sha256',$path).PHP_EOL;
}
