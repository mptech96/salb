<?php
// Schema-only bootstrap for the separately launched loopback MariaDB instance.
// Never accepts a main database name or a caller-supplied server/port.
$name=$argv[1]??'';
if(!preg_match('/^sulb_hist_schema_[0-9]{8}_[a-f0-9]{8}$/D',$name))throw new RuntimeException('Owned schema-only name required.');
$pdo=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dataPath=strtolower(str_replace('\\','/',(string)$pdo->query('SELECT @@datadir')->fetchColumn()));
$ownedRoot=strtolower(str_replace('\\','/',realpath(__DIR__.'/../storage/framework'))).'/hist-mariadb-';
if(!str_starts_with($dataPath,$ownedRoot))throw new RuntimeException('Separate owned MariaDB data directory required.');
if($pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=".$pdo->quote($name))->fetchColumn())throw new RuntimeException('Refusing existing schema.');
$sql=file_get_contents(__DIR__.'/../database/schema/mysql-schema.sql');
if(preg_match('/^\s*(USE\s|CREATE\s+DATABASE|DROP\s+DATABASE)/im',$sql))throw new RuntimeException('Cross-database SQL prohibited.');
preg_match_all('/^INSERT INTO\s+`([^`]+)`/m',$sql,$matches);
if(array_diff(array_unique($matches[1]),['migrations']))throw new RuntimeException('Business rows prohibited.');
$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{$pdo->exec('USE `'.$name.'`');$pdo->exec($sql);echo 'SCHEMA_SOURCE_CREATED '.$name.PHP_EOL;}
catch(Throwable $e){$pdo->exec('DROP DATABASE `'.$name.'`');throw $e;}
