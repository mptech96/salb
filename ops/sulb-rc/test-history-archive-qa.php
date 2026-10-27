<?php
require_once __DIR__.'/history-archive.php';
$checks=0;$admin=null;$owned=[];$readerUser=null;$readerHost=null;$qaExit=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('HISTORY_QA_ASSERTION_FAILED');$checks++;};
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
try{
    $expected=getenv('SULB_HISTORY_QA_DATADIR')?:'';
    if(!preg_match('#/sulb-shipment-[a-f0-9]{32}$#D',rtrim(str_replace('\\','/',$expected),'/')))throw new RuntimeException('HISTORY_OWNED_QA_DATADIR_REQUIRED');
    $admin=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',$options);
    $observed=$admin->query('SELECT @@datadir d,VERSION() v')->fetch(PDO::FETCH_ASSOC);
    if(rtrim(str_replace('\\','/',$observed['d']),'/')!==rtrim(str_replace('\\','/',$expected),'/')||!preg_match('/^8\.4\./',$observed['v']))throw new RuntimeException('HISTORY_WRONG_QA_SERVER');
    $source='sulb_hist_source_'.bin2hex(random_bytes(8));$restored='sulb_hist_restore_'.bin2hex(random_bytes(8));$restored2='sulb_hist_restore_'.bin2hex(random_bytes(8));
    foreach([$source,$restored,$restored2]as$d){$admin->exec('CREATE DATABASE '.historyId($d).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned[]=$d;}
    $admin->exec('USE '.historyId($source));
    $admin->exec("SET SESSION time_zone='+00:00'");$admin->exec("SET SESSION sql_mode=CONCAT(@@session.sql_mode,',NO_AUTO_VALUE_ON_ZERO')");
    foreach([
      'CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
      'CREATE TABLE lots(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,qty DECIMAL(26,6),value DECIMAL(26,8),uom VARCHAR(16),received_at DATETIME(6),FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB',
      'CREATE TABLE gl(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,debit DECIMAL(26,8),credit DECIMAL(26,8),FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB',
      'CREATE TABLE movements(id BIGINT UNSIGNED PRIMARY KEY,lot_id BIGINT UNSIGNED,qty DECIMAL(26,6),at_time TIMESTAMP(6),FOREIGN KEY(lot_id) REFERENCES lots(id)) ENGINE=InnoDB',
      'CREATE TABLE private_payloads(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payload LONGBLOB,note VARCHAR(100),nullable_value TEXT,j JSON,flags BIT(64),ratio DOUBLE,enum_unit ENUM(\'PCS\',\'KG\'),set_unit SET(\'PCS\',\'KG\'),amount DECIMAL(26,8),twice DECIMAL(27,8) GENERATED ALWAYS AS (amount*2) STORED) ENGINE=InnoDB'
    ]as$sql)$admin->exec($sql);
    $admin->exec('INSERT INTO companies VALUES(1),(2)');
    $admin->exec("INSERT INTO lots VALUES(1,1,6.250000,150.00100000,'PCS','2020-01-02 03:04:05.123456'),(2,1,0.250000,22.25000000,'CARTON','2020-01-02 03:04:06.123456')");
    $admin->exec('INSERT INTO gl VALUES(18446744073709551610,1,172.25100000,0)');
    $admin->exec("INSERT INTO movements VALUES(0,1,-0.250000,'2020-01-02 03:04:05.654321')");
    $stmt=$admin->prepare('INSERT INTO private_payloads(id,payload,note,nullable_value,j,flags,ratio,enum_unit,set_unit,amount) VALUES(?,?,?,?,?,CAST(CAST(? AS CHAR) AS UNSIGNED),?,?,?,?)');
    $stmt->execute(['0',"PRIVATE_CUSTOMER_MARKER\0\xff\x01",'synthetic '.hex2bin('d8b5d984d8a8'),null,'{"escaped":"\\u0000","test":true}','18446744073709551610','0.12345678912345678','PCS','PCS,KG','123456789012345678.12345678']);
    $readerUser='sulb_hist_ro_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
    $readerHost=(string)$admin->query("SELECT SUBSTRING_INDEX(USER(),'@',-1)")->fetchColumn();
    if($readerHost===''||str_contains($readerHost,'%')||str_contains($readerHost,'_')||strlen($readerHost)>255)throw new RuntimeException('HISTORY_QA_CLIENT_IDENTITY_REQUIRED');
    $admin->exec('CREATE USER '.$admin->quote($readerUser).'@'.$admin->quote($readerHost).' IDENTIFIED BY '.$admin->quote($password));
    $admin->exec('GRANT SELECT ON '.historyId($source).'.* TO '.$admin->quote($readerUser).'@'.$admin->quote($readerHost));
    $reader=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$source.';charset=utf8mb4',$readerUser,$password,$options);unset($password);
    $denied=false;try{$reader->exec('INSERT INTO companies VALUES(3)');}catch(PDOException $e){$denied=(int)($e->errorInfo[1]??0)===1142;}$assert($denied);
    $root=dirname(__DIR__,2).'/backend/storage/framework/testing/history-archive-qa-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
    $key=sodium_crypto_secretstream_xchacha20poly1305_keygen();$file=$root.'/snapshot.enc';
    $manifest=historyCapture($reader,$file,$key,104857600,function()use($admin){$admin->beginTransaction();$admin->exec('UPDATE lots SET value=160.001 WHERE id=1');$admin->exec('UPDATE gl SET debit=182.251');$admin->commit();});
    historyVerify($file,$key,$manifest);$assert($manifest['consistent_snapshot']===true);$assert(count($manifest['tables'])===5);
    $assert(strpos(file_get_contents($file),'PRIVATE_CUSTOMER_MARKER')===false);
    $target=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$restored.';charset=utf8mb4','root','',$options);
    $proof=historyRestore($target,$restored,$expected,$file,$key,$manifest);$assert($proof['row_digests_match']&&$proof['foreign_key_orphans']===0);
    $assert($target->query('SELECT SUM(value) FROM lots')->fetchColumn()==='172.25100000');
    $assert($target->query('SELECT SUM(debit-credit) FROM gl')->fetchColumn()==='172.25100000');
    $assert($admin->query('SELECT SUM(value) FROM lots')->fetchColumn()==='182.25100000');
    $assert($target->query('SELECT id FROM gl')->fetchColumn()==='18446744073709551610');
    $assert($target->query('SELECT id FROM private_payloads')->fetchColumn()==='0');
    $assert($target->query('SELECT payload FROM private_payloads')->fetchColumn()==="PRIVATE_CUSTOMER_MARKER\0\xff\x01");
    $assert($target->query('SELECT amount FROM private_payloads')->fetchColumn()==='123456789012345678.12345678');
    $assert($target->query('SELECT CAST(CAST(flags AS UNSIGNED) AS CHAR) FROM private_payloads')->fetchColumn()==='18446744073709551610');
    $assert((int)$target->query("SELECT ratio=CAST('0.12345678912345678' AS DOUBLE) FROM private_payloads")->fetchColumn()===1);
    $target2=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$restored2.';charset=utf8mb4','root','',$options);
    $proof2=historyRestore($target2,$restored2,$expected,$file,$key,$manifest);$assert($proof2['row_digests_match']);
    $denied=false;try{historyRestore($target,'sulbprod',$expected,$file,$key,$manifest);}catch(RuntimeException $e){$denied=$e->getMessage()==='HISTORY_OWNED_RESTORE_DATABASE_REQUIRED';}$assert($denied);
    $denied=false;try{historyRestore($target,$restored,'/home/mysql',$file,$key,$manifest);}catch(RuntimeException $e){$denied=$e->getMessage()==='HISTORY_OWNED_RESTORE_SERVER_REQUIRED';}$assert($denied);
    $denied=false;try{historyRestore($target,$restored,$expected,$file,$key,$manifest);}catch(RuntimeException $e){$denied=$e->getMessage()==='HISTORY_EMPTY_RESTORE_DATABASE_REQUIRED';}$assert($denied);
    $bad=$manifest;$bad['bytes']++;$denied=false;try{historyVerify($file,$key,$bad);}catch(RuntimeException $e){$denied=true;}$assert($denied);
    $denied=false;try{historyVerify($file,random_bytes(32),$manifest);}catch(RuntimeException $e){$denied=true;}$assert($denied);
    $corrupt=$root.'/corrupt.enc';$bytes=file_get_contents($file);$bytes[80]=chr(ord($bytes[80])^1);file_put_contents($corrupt,$bytes);$denied=false;try{historyVerify($corrupt,$key,$manifest);}catch(RuntimeException $e){$denied=true;}$assert($denied);
    $partial=$root.'/partial.enc';$bytes=file_get_contents($file);file_put_contents($partial,substr($bytes,0,-25));$denied=false;try{historyVerify($partial,$key,$manifest);}catch(RuntimeException $e){$denied=true;}$assert($denied);
    $aborted=$root.'/aborted.enc';$denied=false;try{historyCapture($reader,$aborted,$key,20);}catch(RuntimeException $e){$denied=$e->getMessage()==='HISTORY_ARCHIVE_SIZE_LIMIT';}$assert($denied);$assert(!$reader->inTransaction());
    $captureRoot=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$source.';charset=utf8mb4','root','',$options);$readOnlyBlocked=false;
    historyCapture($captureRoot,$root.'/readonly-root.enc',$key,104857600,function()use($captureRoot,&$readOnlyBlocked){try{$captureRoot->exec('UPDATE companies SET id=id');}catch(PDOException $e){$readOnlyBlocked=(int)($e->errorInfo[1]??0)===1792;}});
    $assert($readOnlyBlocked);$assert(!$captureRoot->inTransaction());
    $third='sulb_hist_restore_'.bin2hex(random_bytes(8));$admin->exec('CREATE DATABASE '.historyId($third).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned[]=$third;
    $admin->exec('SET FOREIGN_KEY_CHECKS=0');$admin->exec("INSERT INTO lots VALUES(3,777,1,1,'PCS','2019-01-01 00:00:00')");$admin->exec('SET FOREIGN_KEY_CHECKS=1');
    $orphanManifest=historyCapture($reader,$root.'/orphan.enc',$key,104857600);$target3=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$third.';charset=utf8mb4','root','',$options);
    $orphanProof=historyRestore($target3,$third,$expected,$root.'/orphan.enc',$key,$orphanManifest);$assert($orphanProof['foreign_key_orphans']===1);$assert($orphanProof['historical_validation_passed']===false);
    $changed=false;try{historyCapture($reader,$root.'/ddl-change.enc',$key,104857600,function()use($admin){$admin->exec('ALTER TABLE companies ADD COLUMN qa_added INT NULL');});}catch(Throwable $e){$changed=$e->getMessage()==='HISTORY_DDL_CHANGED_DURING_SNAPSHOT'||($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1412);}
    $assert($changed);$assert(!$reader->inTransaction());
    $assert((string)$admin->query('SELECT DATABASE()')->fetchColumn()===$source);
    echo json_encode(['history_tools_qa'=>'PASS','checks'=>$checks,'server_version'=>$observed['v'],'synthetic_only'=>true,'concurrent_snapshot_consistent'=>true,'two_restores_verified'=>true,'source_newer_transaction_preserved'=>true,'production_connected'=>false,'production_history_validated'=>false,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){$reason=preg_match('/^HISTORY_[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'HISTORY_QA_RUNTIME_FAILURE';$state=$e instanceof PDOException?(string)($e->errorInfo[0]??''):'';$code=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;fwrite(STDERR,"$reason SQLSTATE=$state CODE=$code CHECKS=$checks LINE={$e->getLine()}\n");$qaExit=1;
}finally{
    if($admin){foreach($owned as$d)if(preg_match('/^sulb_hist_(source|restore)_[a-f0-9]{16}$/D',$d))$admin->exec('DROP DATABASE IF EXISTS '.historyId($d));if($readerUser)$admin->exec('DROP USER IF EXISTS '.$admin->quote($readerUser).'@'.$admin->quote($readerHost));}
}
exit($qaExit);
