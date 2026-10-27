<?php
require_once __DIR__.'/history-capture.php';
$checks=0;$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('HISTORY_READ_PRIVILEGE_TEST_FAILED');$checks++;};
class HistoryGrantRows extends PDOStatement {
    public function __construct(private array $rows){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
    public function fetchColumn(int $column=0):mixed{return $this->rows[0]??false;}
}
class HistoryGrantConnection extends PDO {
    public array $queries=[];
    public function __construct(private array $grants,private string $identity='sulbmetadata@localhost'){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $this->queries[]=$query;
        return match($query){'SHOW GRANTS FOR CURRENT_USER'=>new HistoryGrantRows($this->grants),'SELECT CURRENT_USER()'=>new HistoryGrantRows([$this->identity]),default=>throw new RuntimeException('UNEXPECTED_READ_PRIVILEGE_QUERY')};
    }
    public function quote(string $string,int $type=PDO::PARAM_STR):string|false{return "'".str_replace("'","''",$string)."'";}
}
$usage="GRANT USAGE ON *.* TO `sulbmetadata`@`localhost`";
$select="GRANT SELECT ON `sulbprod`.* TO `sulbmetadata`@`localhost`";
$view="GRANT SHOW VIEW ON `sulbprod`.* TO `sulbmetadata`@`localhost`";
$db=new HistoryGrantConnection([$usage,$select]);$e=historyRequiredSchemaReadPrivileges($db);
$assert($e['schema_wide_select']===true);$assert($e['show_view_granted']===false);$assert($e['required_schema_privileges']===['SELECT']);$assert($db->queries===['SHOW GRANTS FOR CURRENT_USER']);
$e=historyRequiredSchemaReadPrivileges(new HistoryGrantConnection([$usage,$select,$view]));$assert($e['schema_wide_select']&&$e['show_view_granted']);
$e=historyRequiredSchemaReadPrivileges(new HistoryGrantConnection(["GRANT SELECT, SHOW VIEW ON sulbprod.* TO 'sulbmetadata'@'localhost'"]));$assert($e['schema_wide_select']&&$e['show_view_granted']);
foreach(['localhost','%','127.0.0.1']as$host){
    $db=new HistoryGrantConnection([$usage,$view],'sulbmetadata@'.$host);$caught=false;
    try{historyRequiredSchemaReadPrivileges($db);}catch(HistorySchemaSelectRequired $x){$caught=true;$assert($x->getMessage()==='HISTORY_SCHEMA_SELECT_REQUIRED');$assert(!$x->evidence['schema_wide_select']&&$x->evidence['show_view_granted']);$assert($x->sqlForReview==="GRANT SELECT ON `sulbprod`.* TO 'sulbmetadata'@'$host';");$assert(!str_contains(json_encode($x->evidence),'GRANT'));}
    $assert($caught);$assert($db->queries===['SHOW GRANTS FOR CURRENT_USER','SELECT CURRENT_USER()']);
}
foreach([
 "GRANT SELECT, INSERT ON `sulbprod`.* TO 'sulbmetadata'@'localhost'",
 "GRANT SELECT ON *.* TO 'sulbmetadata'@'localhost'",
 "GRANT SELECT ON `other`.* TO 'sulbmetadata'@'localhost'",
 "GRANT SELECT ON `sulbprod`.`users` TO 'sulbmetadata'@'localhost'",
 "GRANT SELECT ON `sulbprod`.* TO 'sulbmetadata'@'localhost' WITH GRANT OPTION",
 "GRANT `reader_role`@`%` TO 'sulbmetadata'@'localhost'",
 'SECRET malformed grant'
]as$bad){$caught=false;try{historySchemaReadEvidence([$bad]);}catch(RuntimeException $x){$caught=$x->getMessage()==='HISTORY_READ_SCOPE_UNVERIFIED';}$assert($caught);}
$caught=false;try{historyRequiredSchemaReadPrivileges(new HistoryGrantConnection([$view],'sulbprod@localhost'));}catch(RuntimeException $x){$caught=$x->getMessage()==='DEDICATED_READER_REQUIRED';}$assert($caught);
$assert(!historyPolicyAllowsCapture(json_decode(file_get_contents(__DIR__.'/release.json'),true,512,JSON_THROW_ON_ERROR)));
echo "PASS $checks history read-privilege checks; database_connections=0; grants_executed=0\n";
