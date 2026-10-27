<?php
require __DIR__.'/staging-prepare.php';
$checks=0;
$assert=function($yes)use(&$checks){if(!$yes)throw new RuntimeException('TEST_FAILED');$checks++;};
$meta=['mode'=>'READ_ONLY_PREFLIGHT','schema_sha256'=>'synthetic','metadata'=>[[],[],[],[['TABLE_NAME'=>'lots','COLUMN_NAME'=>'id','COLUMN_TYPE'=>'bigint'],['TABLE_NAME'=>'lots','COLUMN_NAME'=>'amount','COLUMN_TYPE'=>'decimal(18,3)']]]];
$p=stagingPlan($meta);$assert(count($p['columns'])===2&&!$p['row_export_enabled']&&!$p['production_reads']);
$assert(array_unique(array_column($p['columns'],'decision'))===['UNREVIEWED']);
foreach([[],['mode'=>'READ_ONLY_PREFLIGHT','metadata'=>[]]]as$bad){try{stagingPlan($bad);throw new LogicException('ACCEPTED');}catch(RuntimeException $e){$assert(true);}}
$duplicate=$meta;$duplicate['metadata'][3][]=$meta['metadata'][3][0];try{stagingPlan($duplicate);throw new LogicException('ACCEPTED');}catch(RuntimeException $e){$assert($e->getMessage()==='DUPLICATE_COLUMN');}
$fixture=function($role='NONE',$grant='GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON *.* TO admin'){
 return function($sql)use($role,$grant){
  if(str_starts_with($sql,'SELECT VERSION'))return [['version'=>'8.4.8-8','distribution'=>'synthetic fixture']];
  if(str_contains($sql,'CURRENT_ROLE'))return [['role_name'=>$role]];
  if($sql==='SHOW GRANTS FOR CURRENT_USER')return [['grant'=>$grant]];
  if(str_contains($sql,'information_schema.TABLES'))return [['TABLE_NAME'=>'odd`name','TABLE_TYPE'=>'BASE TABLE'],['TABLE_NAME'=>'qa_view','TABLE_TYPE'=>'VIEW']];
  if(str_contains($sql,'information_schema.TRIGGERS'))return [['object_name'=>'qa_trigger']];
  if(str_contains($sql,'information_schema.ROUTINES')||str_contains($sql,'information_schema.EVENTS'))return [];
  if(str_starts_with($sql,'SHOW CREATE '))return [['definition'=>'SYNTHETIC_DEFINITION']];
  throw new RuntimeException('UNEXPECTED_QUERY');
 };
};
$r=schemaCapture($fixture());$assert(count($r['definitions'])===3&&!$r['business_rows_exported']&&!$r['automatic_import_authorized']);
$assert(!str_contains(json_encode($r),'GRANT SELECT'));
foreach([$fixture('admin_role'),$fixture('NONE','GRANT SELECT ON sulbprod.* TO admin')]as$read){try{schemaCapture($read);throw new LogicException('ACCEPTED');}catch(RuntimeException $e){$assert(true);}}
$secret='synthetic-qa-password!$()';
foreach(["Host: 127.0.0.1\nUser Name: admin\nPassword: $secret\nPort: 3306", "| Host | localhost |\n| User Name | admin |\n| Password | $secret |\n| Port | 3306 |"]as$output){$credentials=cloudPanelMasterCredentials($output);$assert($credentials['password']===$secret&&$credentials['user']==='admin');}
foreach(["Connect Command: mysql -uroot -psecret", "Host: remote.example\nUser: admin\nPassword: qa\nPort: 3306", "Host: localhost\nUser: sulbprod\nPassword: qa\nPort: 3306", "Host: localhost\nUser: admin\nPassword: qa\nPort: 3306\nPort: 3307"]as$output){try{cloudPanelMasterCredentials($output);throw new LogicException('ACCEPTED');}catch(RuntimeException $e){$assert(true);}}
echo "PASS $checks local contract checks; fake reader only, not a live engine integration test\n";
