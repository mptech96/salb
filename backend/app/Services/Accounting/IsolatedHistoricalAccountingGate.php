<?php
namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/** Hard stop: experimental services are not enabled for normal users/databases. */
final class IsolatedHistoricalAccountingGate
{
    public static function assert(): void
    {
        $connection=DB::connection();$config=$connection->getConfig();
        if($connection->getDriverName()!=='mysql'||!in_array($config['host']??'', ['localhost','127.0.0.1','::1'],true)
            ||!preg_match('/^sulb_hist_test_[0-9]{8}_[a-f0-9]{8}$/D',(string)($config['database']??''))
            ||$connection->selectOne('SELECT DATABASE() AS db')->db!==$config['database']){
            throw new \RuntimeException('Historical accounting services are restricted to the explicitly isolated test database.');
        }
    }
}
