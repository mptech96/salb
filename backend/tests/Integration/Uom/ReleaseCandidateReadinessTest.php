<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;

final class ReleaseCandidateReadinessTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');
        \Tests\Support\UomOperationalLockFixture::install();
        config(['uom.schema_install_approved'=>true]);
    }
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    private function install():void
    {
        foreach(glob(database_path('uom-migrations/*.php')) as $file){
            if(str_contains(basename($file),'000010_')){
                foreach(['stock_movements_company_source_index.php','shipment_cost_parent_fk.php','shipment_cost_allocation_receipts.php','inventory_lot_value_balances.php'] as $dependency)(require base_path('../docs/erp-baseline/proposed-migrations/'.$dependency))->up();
            }
            (require $file)->up();
        }
    }
    public function test_complete_qa_schema_order_installs_all_fourteen_and_preserves_core_history():void
    {
        $columns=DB::getSchemaBuilder()->getColumnListing('items');
        $before=serialize([DB::table('items')->select($columns)->orderBy('id')->get(),DB::table('journal_entry_lines')->orderBy('id')->get()]);
        $this->install();
        foreach(['uom_units','uom_document_snapshots','uom_operational_schema','uom_inventory_adjustments','uom_processing_recipes','uom_processing_commands','uom_lot_capitalization_events','uom_invoice_header_cost_receipts','uom_processing_cost_sources','inventory_lot_value_balances','shipment_cost_allocation_receipts'] as $table)self::assertTrue(DB::getSchemaBuilder()->hasTable($table),$table);
        self::assertSame($before,serialize([DB::table('items')->select($columns)->orderBy('id')->get(),DB::table('journal_entry_lines')->orderBy('id')->get()]));
        self::assertSame(3,DB::table('items')->whereNull('base_uom_id')->where('inventory_quantity_version','LEGACY_KG')->count());
        self::assertSame(5,DB::table('permissions')->whereIn('permission_code',['journals.post','journals.reverse','fixed_assets.manage','workers.manage','payroll.manage'])->count());
        self::assertSame(0,DB::table('role_permissions')->count());
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
    }
    private function process(array $command,?string $input=null):string
    {
        $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];$p=proc_open($command,$descriptors,$pipes,base_path());
        if(!is_resource($p))throw new \RuntimeException('QA_CLIENT_START_FAILED');
        if($input!==null)fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($p)!==0)throw new \RuntimeException('QA_CLIENT_FAILED: '.$err);
        return $out;
    }
    public function test_engine_fk_check_constraints_and_ddl_transaction_guard_remain_enforced():void
    {
        $this->install();self::assertSame(1,(int)DB::selectOne('SELECT @@session.foreign_key_checks v')->v);
        if(!str_contains(strtolower(DB::selectOne('SELECT VERSION() v')->v),'mariadb'))self::assertSame(1,(int)DB::selectOne('SELECT @@global.restrict_fk_on_non_standard_key v')->v);
        try{DB::table('uom_item_modes')->insert(['company_id'=>2,'item_id'=>1,'mode'=>'LEGACY_ONLY']);self::fail('Cross-company parent accepted');}catch(\Illuminate\Database\QueryException $e){self::assertSame(1452,(int)$e->errorInfo[1]);}
        try{DB::table('uom_units')->insert(['id'=>99,'code'=>'QA-BAD','name_ar'=>'QA bad','dimension'=>'COUNT','kind'=>'STANDARD','standard_factor'=>null]);self::fail('CHECK silently ignored');}catch(\Illuminate\Database\QueryException $e){self::assertContains((int)$e->errorInfo[1],[3819,4025]);}
        self::assertSame(0,DB::table('uom_units')->count());DB::beginTransaction();
        try{try{\Tests\Support\QaEngineSchema::trigger('CREATE OR REPLACE TRIGGER qa_never_created BEFORE INSERT ON uom_units FOR EACH ROW SET NEW.name_ar=NEW.name_ar');self::fail('DDL implicitly committed a financial root');}catch(\RuntimeException $e){self::assertSame('QA_OFFLINE_DDL_REQUIRED',$e->getMessage());}self::assertSame(1,DB::transactionLevel());self::assertTrue(DB::connection()->getPdo()->inTransaction());}finally{DB::rollBack();}
        self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='qa_never_created'")->n);
    }
    public function test_fourteen_migrations_then_dry_run_backfill_rollback_restart_and_fifo_gl_reconcile():void
    {
        $this->install();config(['inventory_value.enabled'=>false,'uom.operational_enabled'=>true,'shipment_allocation.enabled'=>false,'shipment_cost_read.enabled'=>false]);
        foreach([[31,'REVENUE','CREDIT'],[32,'EXPENSE','DEBIT'],[33,'ASSET','DEBIT']]as[$id,$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'QA-CHAIN-'.$id,'account_name'=>'QA chain','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('items')->where('id',1)->update(['sales_account_id'=>31,'cogs_account_id'=>32]);DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'QA chain customer','ledger_account_id'=>33,'scope_all_branches'=>1]);
        $invoice=\Tests\Support\UomOperationalLockFixture::purchase(1,1);$service=app(\App\Services\EnterpriseInvoiceService::class);$service->post('PURCHASE',1,$invoice,1,1);
        require_once __DIR__.'/../../Support/HistoricalLotBackfill.php';\Tests\Support\HistoricalLotBackfill::install();
        $source=function(){return serialize([DB::table('inventory_lots')->get(),DB::table('inventory_lot_movements')->get(),DB::table('journal_entries')->get(),DB::table('journal_entry_lines')->get(),DB::table('stock_movements')->get()]);};
        $before=$source();$plan=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($plan['ready'],json_encode($plan['exceptions']));self::assertSame($before,$source());self::assertSame('100.000',$plan['financial']['1:11']['gl_value']);self::assertSame(0,DB::table('inventory_lot_value_balances')->count());
        DB::unprepared("CREATE TRIGGER qa_chain_backfill_failure BEFORE INSERT ON inventory_lot_value_balances FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_CHAIN_BACKFILL_ROLLBACK'");
        try{\Tests\Support\HistoricalLotBackfill::apply($plan,true);self::fail('Partial backfill accepted');}catch(\Illuminate\Database\QueryException $e){self::assertStringContainsString('QA_CHAIN_BACKFILL_ROLLBACK',$e->getMessage());}
        self::assertSame(0,DB::table('inventory_lot_value_balances')->count());self::assertSame(0,DB::table('qa_lot_backfill_runs')->count());self::assertSame($before,$source());DB::unprepared('DROP TRIGGER qa_chain_backfill_failure');
        $run=\Tests\Support\HistoricalLotBackfill::apply($plan,true);self::assertSame($run,\Tests\Support\HistoricalLotBackfill::apply($plan,true));self::assertSame(1,DB::table('inventory_lot_value_balances')->count());self::assertSame(1,DB::table('qa_lot_backfill_runs')->count());
        config(['inventory_value.enabled'=>true]);$sale=$service->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-27','currency_code'=>'SAR','items'=>[['item_id'=>1,'qty_kg'=>'4','unit_price'=>'15','price_unit'=>'KG','vat_percent'=>0]]],1,1,1);$service->post('SALE',1,$sale,1,1);
        $after=\Tests\Support\HistoricalLotBackfill::plan(1);self::assertTrue($after['ready'],json_encode($after['exceptions']));self::assertSame(['replayed_value'=>'60.000','gl_value'=>'60.000'],$after['financial']['1:11']);self::assertSame('60.000',DB::table('inventory_lot_value_balances')->value('remaining_value'));
        $next=\Tests\Support\HistoricalLotBackfill::prepareSequence(1,2026,true);self::assertGreaterThanOrEqual(2,$next);self::assertSame(0,DB::transactionLevel());
    }
    public function test_logical_backup_restores_to_new_schema_without_replacing_new_source_transactions():void
    {
        $this->install();$config=DB::connection()->getConfig();$source=$config['database'];
        self::assertMatchesRegularExpression('/^sulb_ship_test_[a-f0-9]{16}$/D',$source);
        DB::unprepared('CREATE TABLE qa_release_transactions(id INT PRIMARY KEY, amount DECIMAL(18,3) NOT NULL) ENGINE=InnoDB');
        DB::table('qa_release_transactions')->insert(['id'=>1,'amount'=>'180.001']);
        $mysql=!str_contains(strtolower(DB::selectOne('SELECT VERSION() v')->v),'mariadb');
        if(PHP_OS_FAMILY==='Windows'){
            $clientDir=$mysql?(getenv('SULB_QA_MYSQL_BIN')?:throw new \RuntimeException('Owned MySQL QA client directory required')):'D:/xampp/mysql/bin';
            $dumpClient=$clientDir.'/mysqldump.exe';$sqlClient=$clientDir.'/mysql.exe';
        }else{$dumpClient=$mysql?'mysqldump':'mariadb-dump';$sqlClient=$mysql?'mysql':'mariadb';}
        $dump=$this->process([$dumpClient,'--no-defaults','--host=127.0.0.1','--port=3339','--user=root','--single-transaction','--routines','--triggers','--events','--hex-blob','--skip-comments','--skip-dump-date',$source]);
        self::assertStringContainsString('CREATE TABLE',$dump);self::assertStringContainsString('uom_process_cost_source_',$dump);
        DB::table('qa_release_transactions')->insert(['id'=>2,'amount'=>'0.001']);
        $restore='sulb_ship_test_'.bin2hex(random_bytes(8));$pdo=DB::connection()->getPdo();$pdo->exec('CREATE DATABASE `'.$restore.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try{
            $this->process([$sqlClient,'--no-defaults','--host=127.0.0.1','--port=3339','--user=root','--database='.$restore],$dump);
            self::assertSame('180.001',$pdo->query('SELECT SUM(amount) FROM `'.$restore.'`.qa_release_transactions')->fetchColumn());
            self::assertSame('180.002',DB::table('qa_release_transactions')->selectRaw('SUM(amount) v')->first()->v);
            foreach(DB::select('SHOW TABLES') as $row){$table=array_values((array)$row)[0];self::assertSame($pdo->query('SELECT COUNT(*) FROM `'.$source.'`.`'.$table.'`')->fetchColumn()-($table==='qa_release_transactions'?1:0),$pdo->query('SELECT COUNT(*) FROM `'.$restore.'`.`'.$table.'`')->fetchColumn(),$table);}
            $triggers=fn($schema)=>$pdo->query("SELECT TRIGGER_NAME,SHA2(ACTION_STATEMENT,256) h FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=".$pdo->quote($schema).' ORDER BY TRIGGER_NAME')->fetchAll(\PDO::FETCH_ASSOC);
            self::assertSame($triggers($source),$triggers($restore));
            file_put_contents(storage_path('framework/testing/rc-backup-restore-proof.json'),json_encode(['qa_only'=>true,'source_retains_new_transactions'=>true,'restored_to_separate_schema'=>true,'dump_sha256'=>hash('sha256',$dump),'trigger_count'=>count($triggers($restore))],JSON_PRETTY_PRINT));
        }finally{$pdo->exec('DROP DATABASE `'.$restore.'`');}
    }
}
