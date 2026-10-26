<?php
namespace Tests\Integration\HistoricalAccounting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Explicit separate suite: never silently SKIP MariaDB acceptance. */
final class HistoricalMariaDbTest extends TestCase
{
    private function runOwnedDatabase(array $options=[]): Process
    {
        self::assertSame('1',getenv('SULB_HISTORICAL_DB_TEST'),'Explicit SULB_HISTORICAL_DB_TEST=1 required; missing environment is FAILURE, not SKIP.');
        $backend=dirname(__DIR__,3);
        $process=new Process([PHP_BINARY,$backend.'/scripts/test_historical_accounting_isolated.php','--run-isolated',...$options],$backend,[
            'DB_CONNECTION'=>'mysql',
            // Do not inherit SQLite :memory: from the normal PHPUnit suite.
            // The child loads the approved LOCAL .env or explicit CI metadata source.
            'DB_DATABASE'=>getenv('SULB_HISTORICAL_SCHEMA_SOURCE')?:false,
            'DB_URL'=>false,
            'APP_ENV'=>'testing',
            'APP_CONFIG_CACHE'=>sys_get_temp_dir().'/sulb-historical-no-cache-'.bin2hex(random_bytes(8)).'.php',
        ]);
        $process->setTimeout(180);
        $process->run();
        return $process;
    }

    public function test_complete_isolated_acceptance_has_no_skipped_checks(): void
    {
        $p=$this->runOwnedDatabase();$out=$p->getOutput();
        self::assertSame(0,$p->getExitCode(),$p->getErrorOutput().' '.$out);
        self::assertMatchesRegularExpression('/SUMMARY (\{[^\r\n]+\})/',$out);
        preg_match('/SUMMARY (\{[^\r\n]+\})/',$out,$match);$summary=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
        self::assertSame([],$summary['failures']);
        self::assertGreaterThanOrEqual(642,$summary['passed']);
        foreach(['WORKER payroll 99 actual approval journal','WORKER payroll 100 actual payment journal','WORKER payroll 101 preflight rejects','WORKER payment 101 preflight rejects',
            'WORKER request concurrent retry same journal','WORKER request fault rolls back loan voucher journal sequence',
            'WORKER receipt then payroll 200 leaves loan 500','WORKER allocation detail matches actual advance GL 500',
            'WORKER concurrent receipts only one fits remaining balance','WORKER allocation fault full financial rollback']as$label)
            self::assertStringContainsString('PASS '.$label,$out);
        foreach(['ATOMIC SALE create allocate post','ATOMIC PURCHASE create allocate post','ATOMIC concurrent retry one entire settlement','ATOMIC corrective SUSPENDED accounting disabled','ATOMIC exact opposite journal line','ATOMIC failure after voucher insertion rolls back all writes']as$label)self::assertStringContainsString('PASS '.$label,$out);
        foreach(['CORRECTIVE exact 600 plus 400 atomic settlement','CORRECTIVE partial A rejected without reversing B or journal','CORRECTIVE rollback after GL and first reverse row leaves no orphan','CORRECTIVE EXPIRED independent permission actual full reversal']as$label)self::assertStringContainsString('PASS '.$label,$out);
        foreach(['METADATA permanent scoped request result','METADATA concurrent retry one durable request','METADATA numbering skips existing legacy number','METADATA legacy voucher unchanged without automatic backfill','METADATA preserves user notes exactly','METADATA request failure rolls back voucher journal allocations and numbering','METADATA audit failure rolls back inverse and allocation reversal','METADATA corrective audit actual actor reason references time']as$label)self::assertStringContainsString('PASS '.$label,$out);
        self::assertSame($summary['passed'],preg_match_all('/^PASS /m',$out));
        foreach(['NUMBERING concurrent real legacy atomic RECEIPT unique results','NUMBERING concurrent real legacy atomic PAYMENT unique results','NUMBERING completed retry reserves no number RECEIPT','NUMBERING completed retry reserves no number PAYMENT','NUMBERING manual competing requests one voucher one journal','NUMBERING concurrent branches share company series','NUMBERING database rejects direct duplicate insert','NUMBERING preexisting collation duplicate blocks migration','NUMBERING atomic rollback no orphan or consumed number RECEIPT','NUMBERING different companies can share a number safely'] as $label)self::assertStringContainsString('PASS '.$label,$out);
        self::assertStringNotContainsString('SKIP',$out);
        foreach(['WORKER loan exact advance debit cash credit','WORKER concurrent approval one accrual journal','WORKER concurrent payment one voucher one journal','WORKER payment uses historical payable not new setting','WORKER payroll accrues salary only not commission twice','WORKER advance GL balance zero after payroll deduction','WORKER party-tagged payable ledger agrees with zero liability','WORKER durable READ_ONLY rejected','WORKER no preexisting journal or line modified'] as $label)self::assertStringContainsString('PASS '.$label,$out);
        self::assertStringContainsString('TEMP_DATABASE_REMOVED '.$summary['database'],$out);
    }

    public function test_failure_still_removes_owned_temporary_database(): void
    {
        $p=$this->runOwnedDatabase(['--fail-after-schema']);
        self::assertSame(1,$p->getExitCode());
        self::assertStringContainsString('ISOLATED injected failure after schema',$p->getErrorOutput());
        self::assertMatchesRegularExpression('/TEMP_DATABASE_REMOVED sulb_hist_test_[0-9]{8}_[a-f0-9]{8}/',$p->getOutput());
    }
}
