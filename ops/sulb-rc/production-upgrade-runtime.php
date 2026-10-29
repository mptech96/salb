<?php
namespace Sulb\Release;
use Illuminate\Support\Facades\DB;
final class ProductionUpgradeContext {
    private static ?array $state=null;
    public static function begin(string $mode,array $proof,string $receipt):void {
        if(self::$state!==null||PHP_SAPI!=='cli')throw new \RuntimeException('UPGRADE_CONTEXT_NOT_FRESH');
        $db=DB::connection();$pdo=$db->getPdo();$actual=$pdo->query('SELECT DATABASE() db,@@datadir datadir,@@server_uuid uuid,VERSION() version,@@version_comment distribution')->fetch(\PDO::FETCH_ASSOC);
        if(!preg_match('/^8\.4\./',$actual['version'])||str_contains(strtolower($actual['version']),'mariadb'))throw new \RuntimeException('UPGRADE_MYSQL84_REQUIRED');
        if($mode==='isolated-rehearsal') {
            require_once SULB_UPGRADE_ROOT.'/backend/tests/Support/UomStageAProof.php';\Tests\Support\UomStageAProof::guard();
            if($actual['db']==='sulbprod')throw new \RuntimeException('UPGRADE_PRODUCTION_REHEARSAL_FORBIDDEN');
        } elseif($mode==='production') {
            if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||!app()->environment('production')||$actual['db']!=='sulbprod'||stripos($actual['distribution'],'Percona')===false)throw new \RuntimeException('UPGRADE_PRODUCTION_IDENTITY_REQUIRED');
            if(!preg_match('#^/home/mgye-api-sulb/sulb-release-backup\.[A-Za-z0-9]+/upgrade-authorization\.json$#D',$receipt)||is_link($receipt)||!is_file($receipt)||realpath($receipt)!==$receipt||fileowner($receipt)!==0||(fileperms($receipt)&0077)!==0||filesize($receipt)>65536)throw new \RuntimeException('UPGRADE_PRIVATE_RECEIPT_REQUIRED');
            if(fileowner(dirname($receipt))!==0||(fileperms(dirname($receipt))&0077)!==0)throw new \RuntimeException('UPGRADE_PRIVATE_BACKUP_DIRECTORY_REQUIRED');
            $bound=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);if($bound!==$proof)throw new \RuntimeException('UPGRADE_RECEIPT_CHANGED');
            $release=json_decode(file_get_contents(SULB_UPGRADE_ROOT.'/ops/sulb-rc/release.json'),true,512,JSON_THROW_ON_ERROR);
            foreach(['deployment_authorized','production_executor_qualified','write_fence_qualified','deployment_backup_restore_qualified','approved_history_verified','staging_passed','complete_security_review','production_derived_balance_executor_qualified','deployment_pipeline_qualified']as$k)if(($release[$k]??false)!==true)throw new \RuntimeException('UPGRADE_RELEASE_GATE_REQUIRED');
            require_once SULB_UPGRADE_ROOT.'/ops/sulb-rc/rollback-policy.php';\reviewedRollbackPolicy($release);
            if(($release['release_decision']??'')!=='GO')throw new \RuntimeException('UPGRADE_RELEASE_GATE_REQUIRED');
            if(realpath(dirname($receipt))!==realpath((string)getenv('SULB_CURRENT_BACKUP_DIR')))throw new \RuntimeException('UPGRADE_CURRENT_BACKUP_BINDING_REQUIRED');
            require_once SULB_UPGRADE_ROOT.'/ops/sulb-rc/backup-receipt.php';\validateBackupReceiptFiles(dirname($receipt),$proof,$release,SULB_UPGRADE_ROOT);
            require_once SULB_UPGRADE_ROOT.'/ops/sulb-rc/release-fence-evidence.php';require_once SULB_UPGRADE_ROOT.'/ops/sulb-rc/release-process-fence.php';\verifyReleaseFence($pdo,$release);\verifyReleaseProcessFence($release);
            $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(\PDO::FETCH_COLUMN);$process=false;foreach($grants as$grant)if(preg_match('/^GRANT (.+) ON \*\.\* TO /i',$grant,$g)&&preg_match('/(?:^|,\s*)(PROCESS|ALL PRIVILEGES)(?:,|$)/i',$g[1]))$process=true;if(!$process)throw new \RuntimeException('UPGRADE_PROCESS_VISIBILITY_REQUIRED');
            if(($proof['database_account']??null)!==$pdo->query('SELECT CURRENT_USER()')->fetchColumn())throw new \RuntimeException('UPGRADE_APPROVED_DATABASE_ACCOUNT_REQUIRED');
            foreach(['all_tenant_write_paths_fenced','queues_imports_schedulers_admin_writes_fenced','exact_backup_verified','backup_restored_offline','post_failure_write_fence_retained','accounting_approval_resolved']as$k)if(($proof[$k]??false)!==true)throw new \RuntimeException('UPGRADE_WRITE_FENCE_BACKUP_REQUIRED');
            if(($proof['database']??null)!=='sulbprod'||($proof['server_uuid']??null)!==$actual['uuid']||!is_int($proof['created_unix']??null)||$proof['created_unix']>time()||time()-$proof['created_unix']>3600||!is_string($proof['approved_commit']??null)||!preg_match('/^[a-f0-9]{40}$/D',$proof['approved_commit']))throw new \RuntimeException('UPGRADE_RECEIPT_IDENTITY_OR_EXPIRY');
            $head=trim((string)shell_exec('git -C '.escapeshellarg(SULB_UPGRADE_ROOT).' rev-parse HEAD'));if(!hash_equals($head,$proof['approved_commit'])||trim((string)shell_exec('git -C '.escapeshellarg(SULB_UPGRADE_ROOT).' status --porcelain'))!=='')throw new \RuntimeException('UPGRADE_PINNED_CLEAN_SOURCE_REQUIRED');
        }else throw new \RuntimeException('UPGRADE_EXPLICIT_MODE_REQUIRED');
        if($db->transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('UPGRADE_DDL_NO_TRANSACTION');
        if((int)$pdo->query("SELECT GET_LOCK('sulb-full-release-18',0)")->fetchColumn()!==1)throw new \RuntimeException('UPGRADE_ALREADY_RUNNING');
        self::$state=['mode'=>$mode,'proof'=>$proof,'receipt'=>$receipt,'receipt_sha'=>$receipt?hash_file('sha256',$receipt):null,'pdo'=>$pdo,'actual'=>$actual,'step'=>null];
        config(['uom.schema_install_approved'=>true]);self::guard();
    }
    public static function guard():void {
        $s=self::$state;if(!$s)throw new \RuntimeException('UPGRADE_CONTEXT_REQUIRED');$pdo=DB::connection()->getPdo();
        if($pdo!==$s['pdo']||DB::transactionLevel()!==0||$pdo->inTransaction())throw new \RuntimeException('UPGRADE_CONNECTION_CHANGED_OR_TRANSACTION');
        $r=$pdo->query('SELECT DATABASE() db,@@server_uuid uuid,@@datadir datadir')->fetch(\PDO::FETCH_ASSOC);foreach(['db','uuid','datadir']as$k)if($r[$k]!==$s['actual'][$k])throw new \RuntimeException('UPGRADE_SERVER_CHANGED');
        $lock=$pdo->query("SELECT IS_USED_LOCK('sulb-full-release-18')=CONNECTION_ID()")->fetchColumn();if((int)$lock!==1)throw new \RuntimeException('UPGRADE_LOCK_LOST');
        if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()')->fetchColumn()!==0)throw new \RuntimeException('UPGRADE_OTHER_DATABASE_CLIENTS');
        if($s['mode']==='production'){$release=json_decode(file_get_contents(SULB_UPGRADE_ROOT.'/ops/sulb-rc/release.json'),true,512,JSON_THROW_ON_ERROR);\verifyReleaseFence($pdo,$release);\verifyReleaseProcessFence($release);}
        if($s['mode']==='production'&&(!is_file($s['receipt'])||is_link($s['receipt'])||!hash_equals($s['receipt_sha'],hash_file('sha256',$s['receipt']))||time()-$s['proof']['created_unix']>3600))throw new \RuntimeException('UPGRADE_APPROVAL_EXPIRED_OR_CHANGED');
    }
    public static function end():void {if(self::$state){try{self::$state['pdo']->query("SELECT RELEASE_LOCK('sulb-full-release-18')");}finally{self::$state=null;}}}
}
final class ProductionUpgradeDialect {
    public static function assertSupported():bool {ProductionUpgradeContext::guard();return false;}
    public static function columnType(string $type):string {return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i','$1',strtolower($type));}
    public static function trigger(string $sql):void {
        ProductionUpgradeContext::guard();if(!preg_match('/^CREATE OR REPLACE TRIGGER\s+`?([A-Za-z0-9_]+)`?\s/i',$sql,$m))throw new \RuntimeException('UPGRADE_TRIGGER_CONTRACT_REQUIRED');
        DB::unprepared('DROP TRIGGER IF EXISTS `'.$m[1].'`');DB::unprepared(preg_replace('/^CREATE OR REPLACE TRIGGER/i','CREATE TRIGGER',$sql,1));
    }
}
final class ProductionUpgradeExecutor {
    public static function plan(string $root):array {
        $catalog=json_decode(file_get_contents($root.'/ops/sulb-rc/production-upgrade/catalog.json'),true,512,JSON_THROW_ON_ERROR);$p=$catalog['schedule']??[];if(count($p)!==18)throw new \RuntimeException('UPGRADE_EXACT_18_REQUIRED');
        require_once $root.'/ops/sulb-rc/private-rehearsal-plan.php';$original=\privateRehearsalPlan($root);
        foreach($p as$i=>$m){if($m['step']!==$i+1||$m['source_path']!==$original[$i]['path']||$m['source_sha256']!==$original[$i]['sha256']||$m['adapter_path']!=='ops/sulb-rc/production-upgrade/'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT).'.php')throw new \RuntimeException('UPGRADE_ORDER_OR_PATH_CHANGED');foreach(['source'=>'source_path','adapter'=>'adapter_path']as$k=>$field){$f=$root.'/'.$m[$field];if(is_link($f)||!hash_equals($m[$k.'_sha256'],hash('sha256',str_replace("\r\n","\n",file_get_contents($f)))))throw new \RuntimeException('UPGRADE_SOURCE_HASH_CHANGED');}}
        return $p;
    }
    public static function run(string $root,string $journal):array {
        ProductionUpgradeContext::guard();$plan=self::plan($root);if(is_link($journal)||file_exists($journal))throw new \RuntimeException('UPGRADE_FRESH_JOURNAL_REQUIRED');$f=fopen($journal,'xb');chmod($journal,0600);
        $record=function(array $row)use($f){$line=json_encode($row+['utc'=>gmdate('c')],JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$line)!==strlen($line)||!fflush($f)||!fsync($f))throw new \RuntimeException('UPGRADE_AUDIT_WRITE_FAILED');};
        try{
            foreach($plan as$m)if(DB::table('migrations')->where('migration',$m['repository_name'])->exists())throw new \RuntimeException('UPGRADE_PREEXISTING_OR_PARTIAL_SCHEDULE_REVIEW_REQUIRED');
            $batch=(int)DB::table('migrations')->max('batch')+1;
            foreach($plan as$m){ProductionUpgradeContext::guard();$record(['state'=>'START','step'=>$m['step'],'source_sha256'=>$m['source_sha256'],'adapter_sha256'=>$m['adapter_sha256']]);$migration=require $root.'/'.$m['adapter_path'];$migration->up();ProductionUpgradeContext::guard();DB::table('migrations')->insert(['migration'=>$m['repository_name'],'batch'=>$batch]);$record(['state'=>'DONE','step'=>$m['step']]);}
            return ['ordered_adapters_applied'=>18,'automatic_down_supported'=>false,'financial_backfill_performed'=>false];
        }catch(\Throwable $e){$record(['state'=>'FAILED_RETAIN_WRITE_FENCE','sqlstate'=>$e instanceof \PDOException?($e->errorInfo[0]??null):null]);throw $e;}finally{fclose($f);}
    }
}
