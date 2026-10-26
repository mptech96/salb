<?php
// Standalone read-only VPS evidence collection; never boots Laravel or reads .env.
require __DIR__.'/../../backend/scripts/release_metadata_collect.php';
ini_set('display_errors','0');
try{
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI_REQUIRED');
    $tty=@fopen('/dev/tty','r+');if(!$tty)throw new RuntimeException('TTY_REQUIRED');
    fwrite($tty,'Password for dedicated sulb_metadata_ro only: ');
    exec('stty -echo < /dev/tty',$unused,$status);if($status!==0)throw new RuntimeException('HIDDEN_INPUT_REQUIRED');
    try{$line=fgets($tty);}finally{exec('stty echo < /dev/tty');fwrite($tty,"\n");fclose($tty);}
    if($line===false)throw new RuntimeException('PASSWORD_INPUT_REQUIRED');
    $password=rtrim($line,"\r\n");unset($line);
    try{$db=new PDO('mysql:host=localhost;dbname=sulbprod;charset=utf8mb4','sulb_metadata_ro',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10]);}finally{unset($password);}
    $authorization=verifyReleaseMetadataReader($db);
    $sql=preg_replace('/^--[^\r\n]*$/m','',file_get_contents(__DIR__.'/../../backend/scripts/release_schema_baseline_readonly.sql'));
    $sets=[];foreach(explode(';',$sql)as$statement){$statement=trim($statement);if($statement==='')continue;if(!preg_match('/^(SELECT\s|START TRANSACTION READ ONLY$|ROLLBACK$)/i',$statement))throw new RuntimeException('UNEXPECTED_SQL');$rows=$db->query($statement);if(str_starts_with(strtoupper($statement),'SELECT'))$sets[]=$rows->fetchAll(PDO::FETCH_ASSOC);}
    foreach([
        'SELECT TABLE_NAME,CONSTRAINT_NAME,SHA2(CHECK_CLAUSE,256) clause_sha256 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        'SELECT TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        'SELECT TABLE_NAME,COLUMN_NAME,SHA2(GENERATION_EXPRESSION,256) expression_sha256 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME'
    ]as$statement)$sets[]=$db->query($statement)->fetchAll(PDO::FETCH_ASSOC);
    $hasMigrations=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='migrations'")->fetchColumn();
    $migrations=$hasMigrations?$db->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC):null;
    $release=json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR);
    $extensions=[];foreach($release['required_php_extensions']as$name)$extensions[$name]=extension_loaded($name);
    $schemaHash=hash('sha256',json_encode($sets,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $live=[];$source=json_decode(file_get_contents(__DIR__.'/source-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($source as$file){$path=$file['path'];foreach(['backend','frontend']as$component){if(!str_starts_with($path,$component.'/'))continue;$target=$release['paths'][$component].'/'.substr($path,strlen($component)+1);$live[$path]=is_file($target)?hash_file('sha256',$target):null;}}
    echo json_encode(['mode'=>'READ_ONLY_PREFLIGHT','writes_performed'=>false,'release_decision'=>'NO-GO','deployment_authorized'=>false,
        'authorization'=>$authorization,'php_cli'=>PHP_VERSION,'php_extensions'=>$extensions,'php_fpm_runtime_verified'=>false,
        'schema_sha256'=>$schemaHash,'schema_matches_approved_baseline'=>is_string($release['expected_production_schema_sha256'])&&hash_equals($release['expected_production_schema_sha256'],$schemaHash),
        'metadata'=>$sets,'migration_repository'=>$migrations,'live_source_sha256'=>$live,
        'not_proven'=>['representative_history','LOT_GL_reconciliation','trigger_visibility_with_readonly_grants','FPM_runtime','active_UOM_LOT_L3_flags','LOT_sequence_data_readiness','production_equivalent_staging','write_fence','production_upgrade_and_rollback']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"STOP: dedicated-reader/schema evidence could not be verified. No production action authorized.\n");exit(1);}
