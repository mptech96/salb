<?php
namespace App\Support;
use Illuminate\Database\Connection;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
final class ReleaseWriteFence {
    private static ?array $ownedQa=null;
    public const MARKER='/run/sulb-release.fence';
    public static function active():bool {clearstatcache(true,self::MARKER);return is_dir(self::MARKER)||is_link(self::MARKER)||file_exists(self::MARKER);}
    public static function assertState(bool $active,bool $privileged=false):void {if($active&&!$privileged)throw new ServiceUnavailableHttpException(60,'SULB_RELEASE_WRITE_FENCE');}
    public static function assertDatabaseAccess(?Connection $connection=null,bool $forceActive=false):void {self::assertState($forceActive||self::active(),self::privileged($connection));}
    public static function attach(Connection $connection):void {$connection->beforeExecuting(static function($query,$bindings,$connection){self::assertDatabaseAccess($connection);});}
    public static function permitOwnedQa(\PDO $pdo,string $context,string $socket,string $datadir,string $owned):void {
        if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||posix_getppid()!==1||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$owned)||!preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}/native-context\.json$#D',$context))throw new \RuntimeException('FENCE_OWNED_NAMESPACE_REQUIRED');
        $helper=dirname(__DIR__,3).'/ops/sulb-rc/history-native-context.php';if(!is_file($helper))throw new \RuntimeException('FENCE_PRIVATE_SOURCE_REQUIRED');require_once $helper;\verifyHistoryNativeContext($context,$socket,$datadir);$v=$pdo->query('SELECT DATABASE() db,@@datadir d')->fetch(\PDO::FETCH_ASSOC);if($v['db']!==$owned||rtrim($v['d'],'/')!==$datadir||$v['db']==='sulbprod')throw new \RuntimeException('FENCE_QA_PDO_SCOPE');self::$ownedQa=['pdo'=>$pdo,'context'=>$context,'context_sha'=>hash_file('sha256',$context),'net'=>readlink('/proc/self/ns/net')];
    }
    private static function privileged(?Connection $connection=null):bool {
        if(self::$ownedQa!==null&&PHP_SAPI==='cli'&&PHP_OS_FAMILY==='Linux'&&posix_geteuid()===0&&posix_getppid()===1){$p=self::$ownedQa;if(hash_file('sha256',$p['context'])===$p['context_sha']&&readlink('/proc/self/ns/net')===$p['net']&&($connection===null||$connection->getPdo()===$p['pdo']))return true;}

        if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()!==0)return false;
        $entry=realpath($_SERVER['SCRIPT_FILENAME']??'');$allowed=['/home/sulb-release/ops/sulb-rc/production-upgrade.php','/home/sulb-release/ops/sulb-rc/release-backup.php','/home/sulb-release/ops/sulb-rc/release-postcheck.php','/home/sulb-release/ops/sulb-rc/release-fence.php'];if(!$entry||!in_array($entry,$allowed,true))return false;
        $p=self::MARKER.'/permit.json';if(!is_dir(self::MARKER)||is_link(self::MARKER)||fileowner(self::MARKER)!==0||(fileperms(self::MARKER)&0077)!==0||is_link($p)||!is_file($p)||fileowner($p)!==0||(fileperms($p)&0077)!==0||filesize($p)>65536)return false;
        $expected=getenv('SULB_RELEASE_PERMIT_SHA256');if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,hash_file('sha256',$p)))return false;
        try{$v=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return false;}
        return ($v['deployment_authorized']??false)===true&&($v['database']??'')==='sulbprod'&&is_int($v['created_unix']??null)&&$v['created_unix']<=time()&&time()-$v['created_unix']<=3600&&($v['allowed_entry_sha256'][$entry]??null)===hash_file('sha256',$entry);
    }
}
