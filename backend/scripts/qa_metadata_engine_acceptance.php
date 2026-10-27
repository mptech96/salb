<?php
/** Disposable local QA only. Never connects to the deployed database server. */
require __DIR__.'/release_metadata_collect.php';
$directory=getenv('SULB_SHIPMENT_TEST_DIR');
if(getenv('APP_ENV')!=='testing'||!$directory||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('OWNED_QA_REQUIRED');
$root=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$root->query('SELECT @@datadir')->fetchColumn();
if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$directory),'/'))throw new RuntimeException('WRONG_QA_SERVER');
$server=releaseMetadataServer($root);$maria=$server['mariadb'];$account="'sulb_metadata_ro'@'127.0.0.1'";$password=bin2hex(random_bytes(20));$role='qa_meta_'.bin2hex(random_bytes(4));$child=$role.'_child';$other=$role.'_db';
if($root->query("SHOW DATABASES LIKE 'sulbprod'")->fetchColumn()!==false||(int)$root->query("SELECT COUNT(*) FROM mysql.user WHERE User='sulb_metadata_ro'")->fetchColumn())throw new RuntimeException('QA_NAME_ALREADY_EXISTS');
if(!$maria&&$root->query('SELECT @@global.mandatory_roles')->fetchColumn()!=='')throw new RuntimeException('QA_GLOBAL_ROLES_NOT_EMPTY');
$passed=[];$sets=[];
try{
    $root->exec('CREATE DATABASE sulbprod');$root->exec("CREATE DATABASE `$other`");
    $root->exec("CREATE TABLE sulbprod.qa_probe(id INT PRIMARY KEY, secret_text VARCHAR(80), CHECK(id>0)) ENGINE=InnoDB");$root->exec("INSERT INTO sulbprod.qa_probe VALUES(1,'SYNTHETIC_ROW_NEVER_EXPORT')");
    $root->exec("CREATE USER $account IDENTIFIED BY ".$root->quote($password));
    $connect=fn()=>new PDO('mysql:host=127.0.0.1;port=3339;dbname=sulbprod;charset=utf8mb4','sulb_metadata_ro',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $reset=function()use($root,$account){$root->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");$root->exec("GRANT SELECT, SHOW VIEW ON sulbprod.* TO $account");};
    $deny=function($name)use($connect,&$passed){try{verifyReleaseMetadataReader($connect());}catch(RuntimeException $e){$passed[]=$name;return;}throw new RuntimeException('UNSAFE_ACCEPTED_'.$name);};
    $reset();$db=$connect();$gate=verifyReleaseMetadataReader($db);$passed[]='authenticated_dedicated_reader';
    foreach(releaseMetadataQueries($db)as$query){$result=$db->query($query);if(str_starts_with($query,'SELECT')||str_starts_with($query,'SHOW SESSION VARIABLES'))$sets[]=$result->fetchAll(PDO::FETCH_ASSOC);}
    foreach(releaseExtendedMetadataQueries($db)as$query)$sets[]=$db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    if(str_contains(json_encode($sets),'SYNTHETIC_ROW_NEVER_EXPORT')||(int)$root->query('SELECT COUNT(*) FROM sulbprod.qa_probe')->fetchColumn()!==1)throw new RuntimeException('PRIVATE_ROW_OR_MUTATION');$passed[]='metadata_queries_no_business_rows_or_writes';unset($db);
    $root->exec("GRANT INSERT ON sulbprod.* TO $account");$deny('schema_write_denied');$reset();
    $root->exec("GRANT SELECT ON sulbprod.* TO $account WITH GRANT OPTION");$deny('grant_option_denied');$reset();
    $root->exec("GRANT PROCESS ON *.* TO $account");$deny('global_privilege_denied');$reset();
    $root->exec("GRANT SELECT ON `$other`.* TO $account");$deny('other_schema_denied');$reset();
    $root->exec("GRANT UPDATE(secret_text) ON sulbprod.qa_probe TO $account");$deny('column_write_denied');$reset();
    $root->exec('CREATE PROCEDURE sulbprod.qa_probe_procedure() SELECT 1');$root->exec("GRANT EXECUTE ON PROCEDURE sulbprod.qa_probe_procedure TO $account");$deny('routine_execute_denied');$reset();
    $root->exec("CREATE ROLE `$role`");$root->exec("CREATE ROLE `$child`");$root->exec("GRANT INSERT ON sulbprod.* TO `$child`");$root->exec("GRANT `$child` TO `$role`");$root->exec("GRANT `$role` TO $account");$deny('nondefault_nested_role_denied');
    $root->exec("SET DEFAULT ROLE `$role` ".($maria?'FOR':'TO')." $account");$deny('default_nested_role_denied');
    if(!$maria){$root->exec("SET DEFAULT ROLE NONE TO $account");$root->exec("REVOKE `$role` FROM $account");$root->exec('SET GLOBAL mandatory_roles='.$root->quote($role.'@%'));$deny('mandatory_role_denied');$root->exec("SET GLOBAL mandatory_roles=''");}
    $proof=['qa_only'=>true,'server'=>$server,'passed'=>$passed,'count'=>count($passed),'result_sets'=>count($sets),'writes_performed_by_collector'=>false];
    file_put_contents(__DIR__.'/../storage/framework/testing/metadata-engine-acceptance-'.$server['family'].'.json',json_encode($proof,JSON_PRETTY_PRINT));echo json_encode($proof,JSON_PRETTY_PRINT).PHP_EOL;
}finally{
    if(!$maria)$root->exec("SET GLOBAL mandatory_roles=''");$root->exec("DROP USER IF EXISTS $account");$root->exec("DROP ROLE IF EXISTS `$role`");$root->exec("DROP ROLE IF EXISTS `$child`");$root->exec('DROP DATABASE sulbprod');$root->exec("DROP DATABASE `$other`");
}
