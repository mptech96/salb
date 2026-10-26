<?php
namespace Tests\Integration\Shipments;

use Illuminate\Support\Facades\DB;

/** Full schema and actual purchase/lot/distribution services; no mocks. */
class DriverPurchaseGatewayTest extends \Tests\TestCase
{
    protected array $writer=[];private ?string $user=null;
    protected function setUp():void
    {
        parent::setUp();
        require_once __DIR__.'/../../Support/DriverGatewayPrototype.php';
        new \Tests\Support\DriverGatewayPrototype([]); // verifies disposable server before DDL
        foreach(['gw_payments','gw_exceptions','gw_origins','gw_requests','gw_scopes'] as $t)\Illuminate\Support\Facades\Schema::dropIfExists($t);
        $sql=file_get_contents(base_path('database/schema/mysql-schema.sql'));
        if(preg_match('/^\s*(USE |CREATE DATABASE|DROP DATABASE)/im',$sql))throw new \RuntimeException('Cross DB schema forbidden');
        preg_match_all('/^INSERT INTO\s+`([^`]+)`/m',$sql,$matches);if(array_diff(array_unique($matches[1]),['migrations']))throw new \RuntimeException('No business rows');
        DB::unprepared($sql);
        $ddl=file_get_contents(__DIR__.'/../../Support/driver_gateway_schema.sql');
        $ddl=str_replace('voucher_id BIGINT UNSIGNED','voucher_id BIGINT',$ddl); // actual vouchers.id signed
        foreach(explode('-- statement',$ddl) as $s)if(str_starts_with(trim($s),'CREATE'))DB::unprepared(trim($s));
        $add=fn($t,$d)=>DB::table($t)->insert($d);
        $add('companies',['id'=>1,'company_name'=>'Purchase gateway test only','is_active'=>1]);
        $add('branches',['id'=>1,'company_id'=>1,'branch_name'=>'TEST','is_active'=>1]);
        $add('users',['id'=>1,'company_id'=>1,'branch_id'=>1,'name'=>'Isolated actor','username'=>'g5-test','password'=>password_hash('synthetic-only',PASSWORD_BCRYPT)]);
        $add('currencies',['currency_code'=>'SAR','currency_name'=>'SAR','decimal_places'=>3,'is_active'=>1]);
        $add('company_currencies',['company_id'=>1,'currency_code'=>'SAR','is_base'=>1,'is_active'=>1]);
        $add('company_settings',['company_id'=>1,'currency_code'=>'SAR','base_currency_code'=>'SAR','currency_decimal_places'=>3]);
        $add('financial_years',['id'=>1,'company_id'=>1,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','is_closed'=>0]);
        foreach([[1,'ASSET'],[2,'LIABILITY'],[3,'LIABILITY'],[4,'ASSET'],[8,'EXPENSE']] as [$id,$type])$add('accounts',['id'=>$id,'company_id'=>1,'account_code'=>'G5-'.$id,'account_name'=>'Test '.$id,'account_type'=>$type,'normal_side'=>$type==='LIABILITY'?'CREDIT':'DEBIT','is_active'=>1,'is_group'=>0,'allow_posting'=>1]);
        foreach(['ACCRUED_EXPENSE_ACCOUNT'=>2,'SUPPLIER_ACCOUNT'=>3,'INVENTORY_ACCOUNT'=>4,'GENERAL_EXPENSE_ACCOUNT'=>8] as $k=>$a)$add('accounting_settings',['company_id'=>1,'setting_key'=>$k,'account_id'=>$a]);
        $add('suppliers',['id'=>1,'company_id'=>1,'branch_id'=>1,'supplier_name'=>'Synthetic supplier','ledger_account_id'=>3,'scope_all_branches'=>1,'is_active'=>1]);
        $add('drivers',['id'=>1,'company_id'=>1,'branch_id'=>1,'driver_name'=>'Synthetic driver','is_active'=>1]);
        $add('financial_accounts',['id'=>1,'company_id'=>1,'branch_id'=>1,'account_code'=>'CASH','account_name'=>'Test cash','account_type'=>'CASH','currency_code'=>'SAR','gl_account_id'=>1,'is_active'=>1]);
        $add('voucher_types',['id'=>2,'type_code'=>'PAYMENT','type_name'=>'Payment']);
        $add('expense_types',['id'=>1,'company_id'=>1,'type_name'=>'Synthetic freight','type_code'=>'G5-FREIGHT','account_id'=>8,'usage_type'=>'SHIPMENT','affects_cost'=>1,'is_active'=>1]);
        $add('items',['id'=>1,'company_id'=>1,'item_name'=>'Synthetic stock','item_code'=>'G5-STOCK','item_type'=>'STOCK','track_inventory'=>1,'inventory_account_id'=>4,'is_active'=>1]);
        $add('shipments',['id'=>1,'company_id'=>1,'branch_id'=>1,'shipment_date'=>'2026-03-02','shipment_number'=>'G5-SHIP','shipment_type'=>'PURCHASE','supplier_id'=>1,'driver_id'=>1,'commercial_status'=>'READY','cost_allocation_method'=>'WEIGHT']);
        $add('shipment_items',['company_id'=>1,'shipment_id'=>1,'item_id'=>1,'qty_kg'=>100,'accepted_qty_kg'=>100,'unit_price_per_kg'=>20,'vat_percent'=>0,'sorting_order'=>1]);
        $this->user='g5_writer_'.bin2hex(random_bytes(5));$this->writer=['username'=>$this->user,'password'=>bin2hex(random_bytes(18))];$db=DB::connection()->getDatabaseName();
        DB::unprepared("CREATE USER '{$this->user}'@'127.0.0.1' IDENTIFIED BY '{$this->writer['password']}'");
        $cleanupUser=$this->user;$admin=DB::connection()->getPdo();register_shutdown_function(static function()use($cleanupUser,$admin){$admin->exec("DROP USER IF EXISTS '$cleanupUser'@'127.0.0.1'");});
        DB::unprepared("GRANT SELECT ON `$db`.* TO '{$this->user}'@'127.0.0.1'");
        foreach(['purchase_invoices','purchase_invoice_lines','invoice_shipment_links','document_sequences','inventory_lots','stock_movements','shipment_items','shipments','shipment_costs','journal_entries','journal_entry_lines','vouchers','sulb_document_sequences','gw_requests','gw_scopes'] as $t)DB::unprepared("GRANT INSERT,UPDATE ON `$db`.`$t` TO '{$this->user}'@'127.0.0.1'");
        foreach(['purchase_invoice_lines','invoice_shipment_links'] as $t)DB::unprepared("GRANT DELETE ON `$db`.`$t` TO '{$this->user}'@'127.0.0.1'");
        foreach(['gw_origins','gw_payments','gw_exceptions'] as $t)DB::unprepared("GRANT INSERT ON `$db`.`$t` TO '{$this->user}'@'127.0.0.1'");
        DB::unprepared("GRANT INSERT,UPDATE ON `$db`.`inventory_lot_movements` TO '{$this->user}'@'127.0.0.1'");
    }
    protected function tearDown():void
    {
        if($this->user)DB::unprepared("DROP USER IF EXISTS '{$this->user}'@'127.0.0.1'");parent::tearDown();
    }
    public function test_real_purchase_stock_origin_partial_full_payment_and_statement():void
    {
        $g=new \Tests\Support\DriverGatewayPrototype($this->writer);
        try{$g->purchaseOrigin('fresh',true);self::fail('Expected fault');}catch(\RuntimeException $e){self::assertSame('FAULT_PURCHASE_BEFORE_ORIGIN',$e->getMessage());}
        foreach(['purchase_invoices','inventory_lots','stock_movements','journal_entries','shipment_costs','gw_origins'] as $t)self::assertSame(0,DB::table($t)->count(),$t.' rollback');
        $o=$g->purchaseOrigin('fresh');self::assertSame($o,$g->purchaseOrigin('fresh'));
        $lot=DB::table('inventory_lots')->sole();self::assertEquals(100,$lot->qty_received_kg);self::assertEquals(3000,$lot->total_cost);self::assertEquals(30,$lot->unit_cost_per_kg);
        self::assertEquals(3000,DB::table('stock_movements')->where('movement_type','IN')->sum('total_cost'));
        $original=DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$o['journal_id'],$o['invoice_journal_id']])->orderBy('id')->get()->toJson();
        self::assertSame('600.000',$g->pay(1,1,$o['origin_id'],'400','partial')['remaining']);
        self::assertSame('0.000',$g->pay(1,1,$o['origin_id'],'600','full')['remaining']);
        self::assertSame($original,DB::table('journal_entry_lines')->whereIn('journal_entry_id',[$o['journal_id'],$o['invoice_journal_id']])->orderBy('id')->get()->toJson());
        self::assertEquals(0,DB::table('journal_entry_lines')->where('account_id',2)->where('party_type','DRIVER')->sum(DB::raw('credit-debit')));
        self::assertEquals(3000,DB::table('journal_entry_lines')->where('account_id',4)->sum(DB::raw('debit-credit')));
        foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));
        $r=\Illuminate\Http\Request::create('/test','GET');$r->attributes->set('tenant_company_id',1);$r->attributes->set('tenant_branch_id',1);
        $report=app(\App\Services\DriverShipmentStatement::class)->report($r,1);
        self::assertEquals(1000,$report['totals']->original_liabilities);self::assertEquals(0,$report['reconciliation'][0]['closing_payable']);
        self::assertNull($report['costs']->items()[0]->remaining_amount);self::assertFalse($report['settlement_enabled']);
    }
    public function test_purchase_held_transaction_with_voucher_payment_and_manual():void
    {
        DB::table('suppliers')->insert(['id'=>99,'company_id'=>1,'branch_id'=>1,'supplier_name'=>'Shared ledger supplier','ledger_account_id'=>2,'scope_all_branches'=>1,'is_active'=>1]);
        $start=function(array $args){$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),...$args],base_path(),['SULB_GW_WRITER'=>json_encode($this->writer)]);$p->setTimeout(30);$p->start();return $p;};
        $purchase=$start(['purchase','concurrent']);$deadline=microtime(true)+10;
        while(!str_contains($purchase->getOutput(),'PURCHASE_CAPTURED')&&microtime(true)<$deadline)usleep(10000);
        self::assertStringContainsString('PURCHASE_CAPTURED',$purchase->getOutput(),$purchase->getErrorOutput());
        $others=[$start(['pay','1','400','race-payment']),$start(['supplier']),$start(['manual','1','8'])];
        $purchase->wait();self::assertSame(0,$purchase->getExitCode(),$purchase->getOutput().$purchase->getErrorOutput());
        foreach($others as $p){$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());}
        self::assertEquals(600,DB::table('journal_entry_lines')->where('account_id',2)->where('party_type','DRIVER')->sum(DB::raw('credit-debit')));
        self::assertSame(2,DB::table('vouchers')->count());self::assertSame(2,DB::table('vouchers')->distinct()->count('voucher_number'));
        fwrite(STDOUT,'PURCHASE_RACE '.json_encode(array_map(fn($p)=>$p->getOutput(),[$purchase,...$others]))."\n");
    }
}
