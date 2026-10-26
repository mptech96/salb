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
    public function test_logical_backup_restores_to_new_schema_without_replacing_new_source_transactions():void
    {
        $this->install();$config=DB::connection()->getConfig();$source=$config['database'];
        self::assertMatchesRegularExpression('/^sulb_ship_test_[a-f0-9]{16}$/D',$source);
        DB::unprepared('CREATE TABLE qa_release_transactions(id INT PRIMARY KEY, amount DECIMAL(18,3) NOT NULL) ENGINE=InnoDB');
        DB::table('qa_release_transactions')->insert(['id'=>1,'amount'=>'180.001']);
        $dumpClient=PHP_OS_FAMILY==='Windows'?'D:/xampp/mysql/bin/mysqldump.exe':'mariadb-dump';
        $sqlClient=PHP_OS_FAMILY==='Windows'?'D:/xampp/mysql/bin/mysql.exe':'mariadb';
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
