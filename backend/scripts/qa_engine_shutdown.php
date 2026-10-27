<?php
/** Normal shutdown of this invocation's disposable local QA only. */
$expected=getenv('SULB_SHIPMENT_TEST_DIR');
if(getenv('APP_ENV')!=='testing'||!$expected||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$expected))throw new RuntimeException('OWNED_QA_REQUIRED');
$pdo=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$pdo->query('SELECT @@datadir')->fetchColumn();
if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$expected),'/'))throw new RuntimeException('WRONG_QA_SERVER');
$others=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND USER<>'system user' AND COMMAND<>'Daemon'")->fetchColumn();
if($others)throw new RuntimeException('OTHER_QA_CLIENTS_PRESENT');
$pdo->exec('SHUTDOWN');echo "OWNED_QA_SHUTDOWN; DATA_PRESERVED\n";
