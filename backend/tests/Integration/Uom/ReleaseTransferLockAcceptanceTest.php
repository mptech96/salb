<?php
namespace Tests\Integration\Uom;

use App\Services\EnterpriseInvoiceService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\UomOperationalLockFixture as F;

/** New acceptance assertions, not expected-defect assertions from the original audit. */
class ReleaseTransferLockAcceptanceTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['UomStageAProof', 'UomOperationalLockFixture', 'ShipmentPurchaseLockFixture'] as $file) {
            require_once __DIR__.'/../../Support/'.$file.'.php';
        }
        \Tests\Support\UomStageAProof::guard();
        config(['inventory_transfer.root_protocol_enabled'=>true]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) DB::unprepared('DROP TABLE `'.array_values((array) $row)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        \Tests\Support\ShipmentPurchaseLockFixture::install();
        $migration = require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');
        $migration->up();
    }

    private function fixtureCall(string $method, mixed ...$args): mixed
    {
        return $this->{$method}(...$args);
    }

    protected function operation(): int
    {
        DB::table('branches')->insert(['id'=>4,'company_id'=>1,'branch_name'=>'Synthetic destination']);
        foreach ([41=>['ASSET','DEBIT'],42=>['LIABILITY','CREDIT']] as $id=>[$type,$side]) {
            DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'DUE-'.$id,'account_name'=>'Synthetic interbranch','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1]);
        }
        foreach (['INTERBRANCH_DUE_FROM_ACCOUNT'=>41,'INTERBRANCH_DUE_TO_ACCOUNT'=>42] as $key=>$id) {
            DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
        }
        return app(\App\Services\InventoryOperationService::class)->create(['company_id'=>1,'operation_type'=>'TRANSFER','operation_date'=>'2026-10-14','from_branch_id'=>1,'to_branch_id'=>4,'created_by'=>1,'from_lines'=>[['item_id'=>1,'qty_kg'=>5]]]);
    }

    protected function worker(string $kind, int $company, int $id, string $phase=''): Process
    {
        $p = new Process([PHP_BINARY, base_path('scripts/batch_three_producer_worker.php'), $kind, (string) $company, (string) $id, $phase], base_path());
        $p->setTimeout(25); $p->start(); return $p;
    }

    protected function await(Process $process, string $text): void
    {
        $end = microtime(true) + 10;
        while ($process->isRunning() && !str_contains($process->getOutput(), $text) && microtime(true) < $end) usleep(10000);
        self::assertStringContainsString($text, $process->getOutput(), $process->getErrorOutput());
    }

    public function test_release_same_company_transfer_and_purchase_serialize_without_deadlock(): void
    {
        $this->exerciseTransferCompetition(1, 1);
    }

    public function test_release_other_company_commits_while_transfer_owns_fifo(): void
    {
        $this->exerciseTransferCompetition(2, 3);
    }

    private function exerciseTransferCompetition(int $company, int $item): void
    {
        $initial = F::purchase(1, 1);
        app(EnterpriseInvoiceService::class)->post('PURCHASE', 1, $initial, 1, 1);
        $operation = $this->fixtureCall('operation');
        $invoice = F::purchase($company, $item);
        $key = DB::connection()->getDatabaseName().'-release-transfer';
        self::assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?,1) ok', [$key])->ok);
        $a = $b = null;
        try {
            $a = $this->fixtureCall('worker', 'transfer', 1, $operation, 'hold_fifo');
            $this->fixtureCall('await', $a, 'TRANSFER_OWNS_FIFO');
            $b = $this->fixtureCall('worker', 'purchase', $company, $invoice);
            if ($company === 1) {
                $this->fixtureCall('await', $b, 'from `sulb_document_sequences`');
                preg_match('/CONNECTION (\d+)/', $b->getOutput(), $match);
                $wait = [];
                $end = microtime(true) + 4;
                do {
                    $wait = DB::select('SELECT l.lock_table,l.lock_index,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?', [(int) $match[1]]);
                    if (!$wait) usleep(10000);
                } while (!$wait && microtime(true) < $end);
                self::assertNotEmpty($wait, 'Must observe a real server wait, not only process timing');
                self::assertStringContainsString('sulb_document_sequences', json_encode($wait));
                self::assertStringNotContainsString('COMMITTED', $b->getOutput());
                fwrite(STDOUT, 'RELEASE_TRANSFER_WAIT '.json_encode($wait)."\n");
            } else {
                $this->fixtureCall('await', $b, 'REQUEST_LOT_INSERT');
                preg_match('/CONNECTION (\d+)/', $b->getOutput(), $match);
                $wait = [];
                $end = microtime(true) + 2;
                do {
                    $wait = DB::select('SELECT l.lock_table,l.lock_index,l.lock_data,r.trx_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?', [(int) $match[1]]);
                    if (!$wait) usleep(10000);
                } while (!$wait && $b->isRunning() && microtime(true) < $end);
                fwrite(STDOUT, 'RELEASE_CROSS_COMPANY_WAIT '.json_encode($wait)."\n");
                $this->fixtureCall('await', $b, 'COMMITTED');
                self::assertTrue($a->isRunning(), 'Independent company must commit before barrier release');
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$key]);
            if ($a) $a->wait();
            if ($b) $b->wait();
        }
        self::assertSame(0, $a->getExitCode(), $a->getOutput().$a->getErrorOutput());
        self::assertSame(0, $b->getExitCode(), $b->getOutput().$b->getErrorOutput());
        self::assertStringNotContainsString('1213', $a->getOutput().$b->getOutput());
        self::assertSame('POSTED', DB::table('inventory_operations')->where('id', $operation)->value('status'));
        self::assertSame('POSTED', DB::table('purchase_invoices')->where('id', $invoice)->value('document_status'));
        self::assertSame(1, DB::table('inventory_lots')->where('purchase_invoice_id', $invoice)->count());
        self::assertSame(1, DB::table('inventory_lots')->where('inventory_operation_id', $operation)->count());
        foreach (DB::table('journal_entries')->pluck('id') as $id) {
            self::assertEquals(0, DB::table('journal_entry_lines')->where('journal_entry_id', $id)->sum(DB::raw('debit-credit')));
        }
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
