<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;

/** Dialect adaptation only inside an already ownership-verified disposable QA DB. */
final class QaEngineSchema
{
    public static function assertSupported():bool
    {
        UomStageAProof::guard();$version=(string)DB::selectOne('SELECT VERSION() v')->v;
        $maria=str_contains(strtolower($version),'mariadb');
        if(!($maria&&str_starts_with($version,'10.4.32-MariaDB'))&&!(!$maria&&preg_match('/^8\.4\./',$version)))throw new \RuntimeException('Target QA engine proof required');
        return $maria;
    }
    public static function trigger(string $sql):void
    {
        $maria=self::assertSupported();
        if(!preg_match('/^CREATE OR REPLACE TRIGGER\s+`?([A-Za-z0-9_]+)`?\s/i',$sql,$matches))throw new \RuntimeException('QA_TRIGGER_STATEMENT_REQUIRED');
        if(DB::transactionLevel()!==0||DB::connection()->getPdo()->inTransaction())throw new \RuntimeException('QA_OFFLINE_DDL_REQUIRED');
        if($maria){DB::unprepared($sql);return;}
        // MySQL has no OR REPLACE. Only disposable offline setup may replace it;
        // never relax the trigger body or expose this as a production executor.
        gc_collect_cycles(); // Release unreachable PDO objects from prior QA cases.
        if(DB::transactionLevel()!==0||(int)DB::selectOne('SELECT COUNT(*) n FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()')->n!==0)throw new \RuntimeException('QA_OFFLINE_DDL_REQUIRED');
        DB::unprepared('DROP TRIGGER IF EXISTS `'.$matches[1].'`');
        DB::unprepared(preg_replace('/^CREATE OR REPLACE TRIGGER/i','CREATE TRIGGER',$sql,1));
    }
    public static function columnType(string $type):string
    {
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i','$1',strtolower($type));
    }
}
