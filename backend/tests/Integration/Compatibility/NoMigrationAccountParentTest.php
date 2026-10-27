<?php
namespace Tests\Integration\Compatibility;
use App\Domain\Accounting\Services\AccountService;
use App\Support\ApiExceptionMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoMigrationAccountParentTest extends \Tests\TestCase
{
    protected function setUp():void {
        parent::setUp();$c=DB::connection()->getConfig();
        if(app()->environment()!=='testing'||($c['host']??'')!=='127.0.0.1'||(string)$c['port']!=='3339'||!preg_match('/^sulb_compat_test_[a-f0-9]{16}$/D',$c['database']))throw new \RuntimeException('OWNED_COMPAT_QA_REQUIRED');
        if(rtrim(str_replace('\\','/',DB::selectOne('SELECT @@datadir d')->d),'/')!==rtrim(str_replace('\\','/',getenv('SULB_COMPAT_QA_DIR')),'/'))throw new \RuntimeException('WRONG_QA_DATADIR');
        if(!Schema::hasTable('accounts'))DB::unprepared(file_get_contents(database_path('schema/mysql-schema.sql')));
        self::assertFalse(Schema::hasTable('uom_units'));self::assertFalse(Schema::hasColumn('items','base_uom_id'));
        DB::beginTransaction();
        foreach([1,2]as$id)DB::table('companies')->insert(['id'=>$id,'company_name'=>'QA compat '.$id]);
        foreach([[1,1,1,1],[2,1,0,1],[3,1,1,0],[201,2,1,1]]as[$id,$company,$active,$group])DB::table('accounts')->insert(['id'=>$id,'company_id'=>$company,'account_code'=>'QA-'.$id,'account_name'=>'QA account','account_type'=>'ASSET','normal_side'=>'DEBIT','is_active'=>$active,'is_group'=>$group,'allow_posting'=>!$group,'account_level'=>1]);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();DB::disconnect();parent::tearDown();}
    private function payload(?int $parent=1):array{return ['company_id'=>1,'account_code'=>'QA-NEW','account_name'=>'QA child','account_type'=>'ASSET','normal_side'=>'DEBIT','parent_id'=>$parent,'is_group'=>0];}
    public function test_active_parent_creation_and_existing_history_remain_valid():void {
        $before=DB::table('accounts')->where('id',1)->first();$id=app(AccountService::class)->create($this->payload());$row=DB::table('accounts')->where('id',$id)->first();
        self::assertSame(1,(int)$row->parent_id);self::assertSame(2,(int)$row->account_level);self::assertSame(1,(int)$row->allow_posting);self::assertEquals($before,DB::table('accounts')->where('id',1)->first());
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('inventory_lots')->count());
    }
    public function test_inactive_foreign_missing_and_analytical_parents_are_rejected_without_partial_insert():void {
        foreach([2,201,999,3]as$parent){$count=DB::table('accounts')->count();$level=DB::transactionLevel();try{app(AccountService::class)->create($this->payload($parent));self::fail('Invalid parent accepted');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}self::assertSame($count,DB::table('accounts')->count());self::assertSame($level,DB::transactionLevel());}
    }
    public function test_root_and_group_creation_use_existing_columns_only():void {
        $id=app(AccountService::class)->create([...$this->payload(null),'is_group'=>1]);$row=DB::table('accounts')->where('id',$id)->first();self::assertNull($row->parent_id);self::assertSame(1,(int)$row->account_level);self::assertSame(0,(int)$row->allow_posting);
    }
    public function test_duplicate_and_outer_rollback_preserve_existing_accounts():void {
        $before=DB::table('accounts')->orderBy('id')->get()->toJson();
        try{app(AccountService::class)->create([...$this->payload(),'account_code'=>'QA-1']);self::fail('Duplicate accepted');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}
        self::assertSame($before,DB::table('accounts')->orderBy('id')->get()->toJson());
        try{DB::transaction(function(){app(AccountService::class)->create($this->payload());throw new \RuntimeException('QA_OUTER_FAILURE');});}catch(\RuntimeException $e){self::assertSame('QA_OUTER_FAILURE',$e->getMessage());}
        self::assertSame($before,DB::table('accounts')->orderBy('id')->get()->toJson());
    }
    public function test_error_redaction_does_not_disclose_sql_bindings_or_passwords():void {
        $error=new \PDOException('SQLSTATE[HY000]: test SQL: secret-binding password=qa');$error->errorInfo=['HY000',1213,'qa'];$safe=ApiExceptionMessage::for(new \RuntimeException('wrapper',0,$error));
        self::assertStringNotContainsString('secret-binding',$safe);self::assertStringNotContainsString('password',$safe);self::assertStringNotContainsString('SQLSTATE',$safe);self::assertStringContainsString('1213',$safe);
        self::assertSame('Known business validation',ApiExceptionMessage::for(new \RuntimeException('Known business validation')));
        self::assertStringNotContainsString('private-value',ApiExceptionMessage::for(new \RuntimeException('SQLSTATE[23000] SQL: private-value')));
    }
    public function test_report_run_and_each_export_failure_redact_database_details():void {
        foreach(['run','csv','xls','pdf']as$format){
            $context=\Mockery::mock(\App\Services\Accounting\AccountingContext::class);$context->shouldReceive('branchFilter')->andReturn(null);$context->shouldReceive('companyId')->andReturn(1);
            $reports=\Mockery::mock(\App\Services\ReportCenterService::class);$reports->shouldReceive('run')->once()->andThrow(new \PDOException('SQLSTATE[HY000] SQL: PRIVATE_QA_BINDING password=qa'));
            $request=\Illuminate\Http\Request::create('/api/reports/qa','GET',$format==='run'?[]:['format'=>$format]);
            $controller=app(\App\Http\Controllers\Api\AdvancedReportController::class);
            $response=$format==='run'?$controller->run($request,'qa',$reports,$context):$controller->export($request,'qa',$reports,$context);
            self::assertSame(422,$response->getStatusCode());self::assertStringNotContainsString('PRIVATE_QA_BINDING',$response->getContent());self::assertStringNotContainsString('password',$response->getContent());
        }
    }
}
