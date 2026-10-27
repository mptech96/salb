<?php
require_once __DIR__.'/history-archive.php';
require_once __DIR__.'/history-fifo-audit.php';
require_once __DIR__.'/private-rehearsal-plan.php';
function historyLegacyProjection(PDO $pdo,array $before,array $manifest,?string $maxPermission,bool $skipSequence=false):void {
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    try{foreach($before as$name=>$t){if($skipSequence&&$name==='sulb_document_sequences')continue;$stats=historyStatsInit();$sql='SELECT '.historySelectColumns($t['columns']).' FROM '.historyId($name);
        if($name==='permissions')$sql.=$maxPermission===null?' WHERE 1=0':' WHERE id <= '. $pdo->quote($maxPermission);
        $q=$pdo->query($sql);while(($row=$q->fetch(PDO::FETCH_NUM))!==false)historyStatsAdd($stats,historyRow($row));$q->closeCursor();
        if(historyStatsFinish($stats)!==$manifest['tables'][$name])throw new RuntimeException('HISTORY_LEGACY_COLUMNS_CHANGED');
    }}finally{$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
}
function historyDataRehearsal(string $root,string $socket,string $datadir,string $owned,array $manifest,string $privateReport):array {
    if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$owned))throw new RuntimeException('HISTORY_QA_DATABASE_REQUIRED');
    if(is_file($root.'/backend/.env')||is_file($root.'/backend/.env.testing')||is_file($root.'/backend/bootstrap/cache/config.php'))throw new RuntimeException('HISTORY_NO_APP_ENV_OR_CACHE_REQUIRED');
    $env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_SOCKET'=>$socket,'DB_DATABASE'=>$owned,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_URL'=>'','SULB_SHIPMENT_TEST_DIR'=>$datadir,'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'null'];
    foreach($env as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
    require_once $root.'/backend/vendor/autoload.php';$app=require $root.'/backend/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach(['UomStageAProof','QaEngineSchema','HistoricalLotBackfill']as$class)require_once $root.'/backend/tests/Support/'.$class.'.php';
    \Tests\Support\UomStageAProof::guard();\Tests\Support\QaEngineSchema::assertSupported();
    $pdo=\Illuminate\Support\Facades\DB::connection()->getPdo();$pdo->exec("SET SESSION time_zone='+00:00'");$before=historyInventory($pdo);$max=$pdo->query('SELECT MAX(id) FROM permissions')->fetchColumn();$max=$max===null?null:(string)$max;
    config(['uom.schema_install_approved'=>true,'inventory_value.enabled'=>false,'uom.operational_enabled'=>false,'shipment_allocation.enabled'=>false,'shipment_cost_read.enabled'=>false]);
    foreach(privateRehearsalPlan($root)as$step){file_put_contents(dirname($privateReport).'/historical-progress.json',json_encode(['step'=>$step['step']],JSON_THROW_ON_ERROR));(require $root.'/'.$step['path'])->up();}
    historyLegacyProjection($pdo,$before,$manifest,$max);
    $allowed=array_column(array_merge(config('manual_journal.permissions',[]),config('release_write_permissions.permissions',[])),'permission_code');
    $q=$pdo->query('SELECT permission_code FROM permissions'.($max===null?'':' WHERE id > '.$pdo->quote($max)));
    foreach($q->fetchAll(PDO::FETCH_COLUMN)as$code)if(!in_array($code,$allowed,true))throw new RuntimeException('HISTORY_UNREVIEWED_PERMISSION_INSERT');
    \Tests\Support\HistoricalLotBackfill::install();$plans=[];$details=[];$ready=true;$lotCount=0;$financialScopes=0;
    foreach(\Illuminate\Support\Facades\DB::table('companies')->orderBy('id')->pluck('id')as$company){
        $company=(int)$company;
        $plan=\Tests\Support\HistoricalLotBackfill::plan($company);$lots=\Illuminate\Support\Facades\DB::table('inventory_lots')->where('company_id',$company)->get()->map(fn($r)=>(array)$r)->all();
        $fifo=historyFifoEvidence($lots,\Illuminate\Support\Facades\DB::table('inventory_lot_movements')->where('company_id',$company)->orderBy('id')->cursor());
        $lotCount+=count($lots);$financialScopes+=count($plan['financial']);
        $details[$company]=['backfill'=>$plan,'fifo'=>$fifo];$plans[$company]=$plan;
        if(!$plan['ready']||!$fifo['ready'])$ready=false;
    }
    historyLegacyProjection($pdo,$before,$manifest,$max);
    if($lotCount===0||$financialScopes===0)$ready=false;
    $applied=0;$rollback='NOT_APPLICABLE_NO_NEW_BALANCES';$restart='NOT_RUN';
    if($ready){foreach($plans as$company=>$plan){
        if($rollback==='NOT_APPLICABLE_NO_NEW_BALANCES'&&$plan['rows']){
            $beforeCount=\Illuminate\Support\Facades\DB::table('inventory_lot_value_balances')->count();$runs=\Illuminate\Support\Facades\DB::table('qa_lot_backfill_runs')->count();
            \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER qa_history_backfill_abort BEFORE INSERT ON inventory_lot_value_balances FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_HISTORY_EXPECTED_ROLLBACK'");
            $failed=false;try{\Tests\Support\HistoricalLotBackfill::apply($plan,true);}catch(\Illuminate\Database\QueryException $e){$failed=(int)($e->errorInfo[1]??0)===1644;}finally{\Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER qa_history_backfill_abort');}
            if(!$failed||\Illuminate\Support\Facades\DB::table('inventory_lot_value_balances')->count()!==$beforeCount||\Illuminate\Support\Facades\DB::table('qa_lot_backfill_runs')->count()!==$runs)throw new RuntimeException('HISTORY_BACKFILL_ROLLBACK_FAILED');$rollback='PASS';
        }
        $run=\Tests\Support\HistoricalLotBackfill::apply($plan,true);if($run!==\Tests\Support\HistoricalLotBackfill::apply($plan,true))throw new RuntimeException('HISTORY_BACKFILL_RESTART_FAILED');$applied++;$restart='PASS';
        $after=\Tests\Support\HistoricalLotBackfill::plan($company);if(!$after['ready']||$after['financial']!==$plan['financial'])throw new RuntimeException('HISTORY_BACKFILL_LOT_GL_CHANGED');
        foreach($plan['rows']as$row){$balance=\Illuminate\Support\Facades\DB::table('inventory_lot_value_balances')->where('company_id',$company)->where('inventory_lot_id',$row['inventory_lot_id'])->first();if(!$balance||bccomp($balance->remaining_value,$row['remaining_value'],3)!==0||bccomp($balance->remaining_quantity,$row['remaining_quantity'],3)!==0)throw new RuntimeException('HISTORY_BACKFILL_BALANCE_MISMATCH');}
    }}
    historyLegacyProjection($pdo,$before,$manifest,$max);
    $sequenceReady=$ready;
    if($ready)foreach(array_keys($plans)as$company){$years=\Illuminate\Support\Facades\DB::table('inventory_lots')->where('company_id',$company)->selectRaw('DISTINCT YEAR(received_at) y')->pluck('y');foreach($years as$year){try{$n=\Tests\Support\HistoricalLotBackfill::prepareSequence($company,(int)$year,true);if($n!==\Tests\Support\HistoricalLotBackfill::prepareSequence($company,(int)$year,true))throw new RuntimeException('LOT_SEQUENCE_RESTART_FAILED');}catch(Throwable $e){$sequenceReady=false;$details[$company]['sequence_exception']=$e->getMessage();}}}
    historyLegacyProjection($pdo,$before,$manifest,$max,true);
    historyPrivateJson($privateReport,['companies'=>$details,'backfill_ready'=>$ready,'sequence_ready'=>$sequenceReady]);
    $counts=[];if($lotCount===0||$financialScopes===0)$counts['HISTORY_REPRESENTATIVE_INVENTORY_REQUIRED']=1;foreach($details as$d){foreach(array_merge($d['backfill']['exceptions'],$d['fifo']['exceptions'])as$e){$r=preg_match('/^[A-Z][A-Z0-9_]+$/D',$e['reason'])?$e['reason']:'HISTORY_EVIDENCE_REVIEW';$counts[$r]=($counts[$r]??0)+1;}if(isset($d['sequence_exception']))$counts['LOT_SEQUENCE_REVIEW_REQUIRED']=($counts['LOT_SEQUENCE_REVIEW_REQUIRED']??0)+1;}
    \Illuminate\Support\Facades\DB::disconnect();
    return ['historical_validation_passed'=>$ready&&$sequenceReady,'historical_status'=>$ready&&$sequenceReady?'PASS':'BLOCKED','uom_migrations_applied'=>14,'lot_l3_dependencies_applied'=>4,'backfill_companies_applied'=>$applied,'rollback'=>$rollback,'idempotency'=>$restart,'exceptions_by_reason'=>$counts,'legacy_financial_columns_preserved'=>true,'fifo_basis'=>'SALE_FIFO_BY_RECORDED_MOVEMENT_ORDER','production_executor_qualified'=>false,'release_decision'=>'NO-GO'];
}
