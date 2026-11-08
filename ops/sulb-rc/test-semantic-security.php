<?php
/** Changed authorization/error boundaries only; no financial posting/history or production connection. */
require dirname(__DIR__,2).'/backend/vendor/autoload.php';
$app=new \Illuminate\Foundation\Application(dirname(__DIR__,2).'/backend');
$responses=\Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
$responses->shouldReceive('json')->andReturnUsing(fn($data,$status=200)=>new \Illuminate\Http\JsonResponse($data,$status));
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,$responses);
$validator=new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$app);
\Illuminate\Http\Request::macro('validate',fn($rules)=>$validator->make($this->all(),$rules)->validate());
$capsule=new \Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$capsule->setAsGlobal();
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);\Illuminate\Support\Facades\DB::swap($capsule->getDatabaseManager());
$db=$capsule->getConnection();
$db->statement('CREATE TABLE branches (id INTEGER PRIMARY KEY, company_id INTEGER, is_active INTEGER)');
$db->statement('CREATE TABLE drivers (id INTEGER PRIMARY KEY, company_id INTEGER, branch_id INTEGER, driver_name TEXT, phone TEXT, id_number TEXT, license_number TEXT, affiliation_type TEXT, affiliation_id INTEGER, notes TEXT, is_active INTEGER, updated_at TEXT, created_at TEXT)');
foreach(['weighbridge_cards','shipments']as$table)$db->statement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY,company_id INTEGER,driver_id INTEGER)');
$db->table('branches')->insert([['id'=>3,'company_id'=>3,'is_active'=>1],['id'=>8,'company_id'=>3,'is_active'=>1]]);
$db->table('drivers')->insert([['id'=>1,'company_id'=>3,'branch_id'=>3,'driver_name'=>'local','is_active'=>1],['id'=>2,'company_id'=>3,'branch_id'=>8,'driver_name'=>'other branch','is_active'=>1],['id'=>3,'company_id'=>4,'branch_id'=>3,'driver_name'=>'other tenant','is_active'=>1]]);
$checks=0;$assert=function($ok)use(&$checks){if(!$ok)throw new RuntimeException('SEMANTIC_SECURITY_ASSERT_'.($checks+1));$checks++;};
$ctx=\Mockery::mock(\App\Services\Accounting\AccountingContext::class);$ctx->shouldReceive('companyId')->andReturn(3);$ctx->shouldReceive('branchFilter')->andReturn(3);$ctx->shouldReceive('userId')->andReturn(1);
$body=['driver_name'=>'allowed','affiliation_type'=>'INDEPENDENT','branch_id'=>3];
$request=\Illuminate\Http\Request::create('/synthetic','PUT',$body);$controller=new \App\Http\Controllers\Api\DriverController;
foreach([2,3]as$id){$assert($controller->update($request,$id,$ctx)->getStatusCode()===404);$assert($controller->destroy($request,$id,$ctx)->getStatusCode()===404);}
$assert($db->table('drivers')->where('id',2)->value('driver_name')==='other branch');
$assert($db->table('drivers')->where('id',3)->exists());
$assert($controller->update($request,1,$ctx)->getStatusCode()===200);
$assert($db->table('drivers')->where('id',1)->value('driver_name')==='allowed');
// Inject a branch change immediately before the terminal UPDATE. This is a scope-predicate
// regression test, not a claim to emulate InnoDB lock scheduling on SQLite.
$inject=false;$writes=0;
$db->beforeExecuting(function($sql,$bindings,$connection)use(&$inject,&$writes,$assert){
 if(preg_match('/^(update|delete).*"drivers"/i',$sql)){
  $assert($connection->transactionLevel()>0);$writes++;
  if($inject){$inject=false;$connection->getPdo()->exec('UPDATE drivers SET branch_id=8 WHERE id=1');}
 }
});
$inject=true;$controller->update($request,1,$ctx);
$assert($db->table('drivers')->where('id',1)->value('branch_id')===8);
$assert($db->table('drivers')->where('id',1)->value('driver_name')==='allowed');
$db->getPdo()->exec('UPDATE drivers SET branch_id=3 WHERE id=1');
$inject=true;$controller->destroy($request,1,$ctx);$assert($db->table('drivers')->where('id',1)->exists());
$db->getPdo()->exec('UPDATE drivers SET branch_id=3 WHERE id=1');
$db->table('shipments')->insert(['id'=>1,'company_id'=>3,'driver_id'=>1]);
$assert($controller->destroy($request,1,$ctx)->getStatusCode()===200);$assert($db->table('drivers')->where('id',1)->value('is_active')===0);$assert($writes>=3);
// Read-only financial boundaries: mock only the failed service, execute actual controllers.
$private='SQLSTATE[HY000] private_schema secret_binding';
$years=\Mockery::mock(\App\Domain\Accounting\Services\FinancialYearService::class);
foreach(['store'=>'create','preview'=>'preview','close'=>'close','reopen'=>'reopen']as$action=>$method){
 $years->shouldReceive($method)->once()->andThrow(new PDOException($private));
 $r=\Illuminate\Http\Request::create('/synthetic','POST',['start_date'=>'2026-01-01','end_date'=>'2026-12-31','confirm_year_close'=>true]);
 $c=new \App\Http\Controllers\Api\FinancialYearController;
 $response=$action==='store'?$c->store($r,$years,$ctx):$c->$action($r,1,$years,$ctx);
 $assert($response->getStatusCode()===422);$assert(!str_contains($response->getContent(),'SQLSTATE'));$assert(!str_contains($response->getContent(),'secret_binding'));
}
$reports=\Mockery::mock(\App\Domain\Accounting\Services\AccountingReportService::class);$reports->shouldReceive('ledger')->andThrow(new PDOException($private));
$r=\Illuminate\Http\Request::create('/synthetic','GET',['account_id'=>1]);
$response=(new \App\Http\Controllers\Api\AccountingReportController)->ledger($r,$reports,$ctx);$assert(!str_contains($response->getContent(),'SQLSTATE'));$assert($response->getStatusCode()===422);
\Mockery::close();echo "PASS $checks changed driver transaction/scope and financial error-boundary checks; isolated_sqlite_only=true; financial_postings=0; production_connections=0\n";
