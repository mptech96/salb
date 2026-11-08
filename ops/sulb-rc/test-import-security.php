<?php
require dirname(__DIR__,2).'/backend/vendor/autoload.php';
$app=new \Illuminate\Foundation\Application(dirname(__DIR__,2).'/backend');
$responses=\Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
$responses->shouldReceive('json')->andReturnUsing(fn($data,$status=200)=>new \Illuminate\Http\JsonResponse($data,$status));
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,$responses);
\Illuminate\Http\Request::macro('validate',fn($rules)=>[]);
$checks=0;
$assert=function($ok)use(&$checks){if(!$ok)throw new RuntimeException('IMPORT_SECURITY_ASSERT_'.($checks+1));$checks++;};
$ctx=\Mockery::mock(\App\Services\Accounting\AccountingContext::class);
$ctx->shouldReceive('companyId')->andReturn(3);$ctx->shouldReceive('branchFilter')->andReturn(3);
$controller=new \App\Http\Controllers\Api\DataImportController;
$request=\Illuminate\Http\Request::create('/synthetic','GET');
$private="SQLSTATE[HY000]: private_schema secret_binding SELECT confidential";
foreach(['batch','export']as$method){
    $svc=\Mockery::mock(\App\Services\MigrationCenterService::class);
    $svc->shouldReceive($method==='export'?'exportRows':'batch')->once()->andThrow(new PDOException($private));
    $response=$method==='batch'?$controller->batch($request,1,$ctx,$svc):$controller->export($request,'items',$ctx,$svc);
    $assert($response->getStatusCode()===($method==='batch'?404:422));
    foreach(['SQLSTATE','private_schema','secret_binding','SELECT confidential']as$secret)$assert(!str_contains($response->getContent(),$secret));
}
$svc=\Mockery::mock(\App\Services\MigrationCenterService::class);
$svc->shouldReceive('batch')->andThrow(new RuntimeException('Reviewed domain validation'));
$assert(str_contains($controller->batch($request,1,$ctx,$svc)->getContent(),'Reviewed domain validation'));
// Exercise real batch() read path with an existing stored SQL diagnostic, never a DB connection.
$db=\Mockery::mock();$batch=\Mockery::mock();$logs=\Mockery::mock();
$db->shouldReceive('table')->with('data_migration_batches')->andReturn($batch);
$batch->shouldReceive('where')->with('company_id',3)->andReturnSelf();$batch->shouldReceive('where')->with('id',1)->andReturnSelf();$batch->shouldReceive('first')->andReturn((object)['id'=>1]);
$db->shouldReceive('table')->with('data_migration_row_logs')->andReturn($logs);
$logs->shouldReceive('where')->with('company_id',3)->andReturnSelf();$logs->shouldReceive('where')->with('batch_id',1)->andReturnSelf();$logs->shouldReceive('orderBy')->with('id')->andReturnSelf();$logs->shouldReceive('limit')->with(1000)->andReturnSelf();
$logs->shouldReceive('get')->andReturn(collect([(object)['message'=>$private],(object)['message'=>'Reviewed domain validation']]));
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);\Illuminate\Support\Facades\DB::swap($db);
$real=(new ReflectionClass(\App\Services\MigrationCenterService::class))->newInstanceWithoutConstructor();
$result=$real->batch(3,1);$assert(!str_contains($result['logs'][0]->message,'SQLSTATE'));$assert($result['logs'][1]->message==='Reviewed domain validation');
\Mockery::close();
echo "PASS $checks import response/stored-log redaction and scoped read contracts; database_connections=0\n";
