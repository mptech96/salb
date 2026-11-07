<?php
require __DIR__.'/production-schema-gate.php';

$sets = [[['TABLE_NAME'=>'inventory_lots', 'ORDINAL_POSITION'=>1]]];
$expected = hash('sha256', json_encode($sets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
requireProductionSchemaFingerprint($expected, fn () => $sets);

foreach ([
    ['UPGRADE_SCHEMA_FINGERPRINT_MISMATCH', $expected, fn () => []],
    ['UPGRADE_SCHEMA_METADATA_UNREADABLE', $expected, fn () => throw new PDOException('private detail')],
    ['UPGRADE_SCHEMA_BASELINE_REQUIRED', '', fn () => throw new LogicException('unexpected read')],
] as [$reason, $baseline, $read]) {
    try {
        requireProductionSchemaFingerprint($baseline, $read);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $reason) continue;
        throw $error;
    }
    throw new LogicException('Expected rejection: '.$reason);
}
echo "PASS schema match, drift, unreadable metadata and missing baseline\n";

final class SchemaGateStatement extends PDOStatement {
    public function __construct(private array $rows, private bool $stringify) {}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        return $this->rows[0]??false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        return array_map(function($row){
            return array_map(fn($value)=>$this->stringify&&is_int($value)?(string)$value:$value,$row);
        },$this->rows);
    }
}
final class SchemaGateConnection extends PDO {
    public bool $transaction=false;
    public int $rollbacks=0;
    public bool $failRead=false;
    public bool $drift=false;
    public array $queries=[];
    public function __construct(public bool $stringify=true) {}
    public function getAttribute(int $attribute):mixed {
        return $attribute===PDO::ATTR_ERRMODE?PDO::ERRMODE_EXCEPTION:$this->stringify;
    }
    public function setAttribute(int $attribute,mixed $value):bool {
        if($attribute!==PDO::ATTR_STRINGIFY_FETCHES)throw new LogicException('Unexpected attribute');
        $this->stringify=$value;return true;
    }
    public function inTransaction():bool {return $this->transaction;}
    public function rollBack():bool {$this->transaction=false;$this->rollbacks++;return true;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $this->queries[]=$query;
        if($query==='START TRANSACTION READ ONLY'){$this->transaction=true;return new SchemaGateStatement([],false);}
        if($query==='ROLLBACK'){$this->transaction=false;return new SchemaGateStatement([],false);}
        if(!preg_match('/^(SELECT|SHOW SESSION VARIABLES)\b/i',$query))throw new LogicException('Non-read query');
        if(str_starts_with($query,'SELECT VERSION() version')){
            return new SchemaGateStatement([['version'=>'8.4.8-8','distribution'=>'Percona Server']],false);
        }
        if(str_contains($query,'FROM information_schema.TABLES')){
            if($this->failRead)throw new PDOException('SYNTHETIC_PRIVATE_METADATA_FAILURE');
            return new SchemaGateStatement([['TABLE_NAME'=>$this->drift?'CHANGED':'SYNTHETIC','count'=>1]],$this->stringify);
        }
        return new SchemaGateStatement([['synthetic'=>1]],$this->stringify);
    }
}
$native=new SchemaGateConnection(false);
$nativeExpected=releaseSchemaFingerprint(releaseSchemaMetadata($native));
$connection=new SchemaGateConnection(true);
verifyProductionSchemaFingerprint($connection,$nativeExpected);
if(!$connection->stringify||$connection->transaction)throw new LogicException('Connection state not restored');
$failed=new SchemaGateConnection(true);$failed->failRead=true;
try{verifyProductionSchemaFingerprint($failed,$nativeExpected);throw new LogicException('Read failure accepted');}
catch(RuntimeException $error){if($error->getMessage()!=='UPGRADE_SCHEMA_METADATA_UNREADABLE')throw$error;}
if(!$failed->stringify||$failed->transaction||$failed->rollbacks!==1)throw new LogicException('Failure cleanup incomplete');
$drifted=new SchemaGateConnection(true);$drifted->drift=true;
try{verifyProductionSchemaFingerprint($drifted,$nativeExpected);throw new LogicException('Drift accepted');}
catch(RuntimeException $error){if($error->getMessage()!=='UPGRADE_SCHEMA_FINGERPRINT_MISMATCH')throw$error;}
if(!$drifted->stringify||$drifted->transaction)throw new LogicException('Drift cleanup incomplete');
$busy=new SchemaGateConnection(true);$busy->transaction=true;
try{verifyProductionSchemaFingerprint($busy,$nativeExpected);throw new LogicException('Existing transaction accepted');}
catch(RuntimeException $error){if($error->getMessage()!=='UPGRADE_SCHEMA_METADATA_UNREADABLE')throw$error;}
if($busy->queries||!$busy->transaction)throw new LogicException('Existing transaction was modified');
$entry=file_get_contents(__DIR__.'/production-upgrade.php');
$check=strpos($entry,'verifyProductionSchemaFingerprint($admin');
$execute=strpos($entry,'ProductionUpgradeExecutor::run(');
if($check===false||$execute===false||$check>=$execute)throw new LogicException('Schema gate no longer precedes DDL entry');
echo "PASS PDO metadata entry, native fetch typing, read-only queries, failure cleanup, transaction rejection and pre-DDL wiring; real_database_connections=0\n";
