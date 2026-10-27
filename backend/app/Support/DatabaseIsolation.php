<?php
namespace App\Support;
final class DatabaseIsolation
{
    public static function current(\PDO $pdo):string
    {
        $values=$pdo->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation')")->fetchAll(\PDO::FETCH_KEY_PAIR);
        if(isset($values['transaction_isolation'],$values['tx_isolation'])&&$values['transaction_isolation']!==$values['tx_isolation'])throw new \RuntimeException('DATABASE_ISOLATION_METADATA_CONFLICT');
        $value=$values['transaction_isolation']??$values['tx_isolation']??throw new \RuntimeException('DATABASE_ISOLATION_METADATA_REQUIRED');
        $level=str_replace('-',' ',strtoupper($value));
        if(!in_array($level,['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'],true))throw new \RuntimeException('DATABASE_ISOLATION_UNKNOWN');
        return $level;
    }
}
