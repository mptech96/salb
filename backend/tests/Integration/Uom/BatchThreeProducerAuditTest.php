<?php
namespace Tests\Integration\Uom;

use App\Services\EnterpriseInvoiceService;
use App\Services\ShipmentCostDistributionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Audit counterexamples deliberately assert observed defects, NOT repaired behaviour. */
class BatchThreeProducerAuditTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $f) require_once __DIR__.'/../../Support/'.$f.'.php';
        \Tests\Support\UomStageAProof::guard();
        config(['inventory_transfer.root_protocol_enabled'=>true]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $r) DB::unprepared('DROP TABLE `'.array_values((array)$r)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1'); F::install();
        $m=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php'); $m->up();
    }

    private function multi(string $amount, bool $reverse=false, string $method='RELATIVE_VALUE'): array
    {
        $s=F::purchase();
        DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>$amount,'foreign_amount'=>$amount]);
        DB::table('shipment_items')->where('id',$s['line'])->delete();
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->delete();
        DB::table('shipments')->where('id',$s['shipment'])->update(['cost_allocation_method'=>$method]);
        for($n=1;$n<=4;$n++) {
            $item=10+$n;$acc=30+$n;
            DB::table('accounts')->insert(['id'=>$acc,'company_id'=>1,'account_code'=>'AUDIT-'.$n,'account_name'=>'Synthetic inventory '.$n,'account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1]);
            DB::table('items')->insert(['id'=>$item,'company_id'=>1,'item_code'=>'AUDIT-'.$n,'item_name'=>'Synthetic '.$n,'inventory_account_id'=>$acc]);
            $row=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$s['shipment'],'item_id'=>$item,'qty_kg'=>$n,'accepted_qty_kg'=>$n,'remaining_qty_kg'=>$n,'base_cost'=>100,'sorting_order'=>$reverse?5-$n:$n,'cost_share_percent'=>25,'manual_allocated_cost'=>100]);
            DB::table('purchase_invoice_lines')->insert(['company_id'=>1,'purchase_invoice_id'=>$s['invoice'],'item_id'=>$item,'shipment_id'=>$s['shipment'],'shipment_item_id'=>$row,'qty_kg'=>$n,'quantity'=>$n,'unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>100/$n,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
        }
        DB::table('purchase_invoices')->where('id',$s['invoice'])->update(['total_before_vat'=>400,'total_amount'=>400,'base_total_before_vat'=>400,'base_total_amount'=>400]);
        DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->update(['allocated_amount'=>400]);
        return $s;
    }

    private function facts(array $s): array
    {
        $j=DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id');$rows=[];
        foreach(DB::table('shipment_items')->where('shipment_id',$s['shipment'])->orderBy('id')->get() as $r) {
            $acc=DB::table('items')->where('id',$r->item_id)->value('inventory_account_id');
            $rows[]=['item'=>$r->item_id,'sort'=>$r->sorting_order,'allocated'=>$r->allocated_cost,'lot'=>DB::table('inventory_lots')->where('id',$r->inventory_lot_id)->value('allocated_cost'),'gl'=>DB::table('journal_entry_lines')->where('journal_entry_id',$j)->where('account_id',$acc)->sum('debit')];
        }
        return $rows;
    }

    public static function allocationMethods(): array
    {
        return array_map(fn($m)=>[$m],['RELATIVE_VALUE','WEIGHT','MANUAL_PERCENT','MANUAL_COST']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allocationMethods')]
    public function test_four_item_allocation_reconciles_totals_and_is_repeat_safe(string $method): void
    {
            $s=$this->multi('20.001',false,$method);
            app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
            self::assertSame('420.001',bcadd((string)DB::table('inventory_lots')->sum('total_cost'),'0',3));
            self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));
            self::assertSame(4,DB::table('inventory_lots')->count());
            foreach($this->facts($s) as $r) {
                self::assertSame(bcadd((string)$r['allocated'],'0',3),bcadd((string)$r['lot'],'0',3));
                self::assertSame(bcadd((string)$r['allocated'],'0',3),bcadd((string)$r['gl'],'0',3));
            }
            foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertSame('0.000',bcadd((string)DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')),'0',3));
            $before=$this->financialHash();
            app(ShipmentCostDistributionService::class)->distributeByShipmentId(1,$s['shipment']);
            app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
            self::assertSame($before,$this->financialHash());
            fwrite(STDOUT,'B3_RECONCILIATION '.json_encode(['method'=>$method,'rows'=>$this->facts($s),'total'=>'420.001'])."\n");
    }

    public function test_counterexample_reordered_rows_put_rounding_on_different_gl_account(): void
    {
        $s=$this->multi('20.001',true);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $rows=$this->facts($s);$mismatches=array_filter($rows,fn($r)=>bccomp((string)$r['allocated'],(string)$r['gl'],3)!==0);
        self::assertCount(2,$mismatches,'Counterexample disappeared: review expected defect, do not claim a repair');
        self::assertEquals(420.001,DB::table('inventory_lots')->sum('total_cost'));
        fwrite(STDOUT,'B3_CONFIRMED_ROUNDING_ORDER_DEFECT '.json_encode($rows)."\n");
    }

    public function test_counterexample_two_costs_are_rounded_separately_in_gl_but_together_in_lots(): void
    {
        $s=$this->multi('20.001');
        // Public service creates the second draft before posting; no synthetic POSTED evidence.
        app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$s['shipment'],'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>'20.001','currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>true,'created_by'=>1]);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $entries=DB::table('shipment_costs')->where('shipment_id',$s['shipment'])->pluck('journal_entry_id');
        $diff=[];
        foreach(DB::table('shipment_items')->where('shipment_id',$s['shipment'])->get() as $r) {
            $acc=DB::table('items')->where('id',$r->item_id)->value('inventory_account_id');
            $gl=DB::table('journal_entry_lines')->whereIn('journal_entry_id',$entries)->where('account_id',$acc)->sum('debit');
            if(bccomp((string)$r->allocated_cost,(string)$gl,3)!==0)$diff[]=['item'=>$r->item_id,'lot'=>$r->allocated_cost,'gl'=>$gl];
        }
        self::assertNotEmpty($diff);fwrite(STDOUT,'B3_CONFIRMED_MULTI_COST_ROUNDING_DEFECT '.json_encode($diff)."\n");
    }

    public function test_tiny_positive_cost_rejects_zero_allocation_line_and_rolls_back_everything(): void
    {
        $s=$this->multi('0.003');$before=$this->financialHash();$error=null;
        try { app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1); }catch(\Throwable $e){$error=$e;}
        self::assertNotNull($error);self::assertStringContainsString('السطر رقم 4',$error->getMessage());
        self::assertSame($before,$this->financialHash());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        fwrite(STDOUT,'B3_TINY_COST_REJECTION_FULL_ROLLBACK '.$error->getMessage()."\n");
    }

    public function test_multi_item_purchase_void_preserves_balance_and_zero_remaining_stock(): void
    {
        $s=$this->multi('20.001');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        app(EnterpriseInvoiceService::class)->void('PURCHASE',1,$s['invoice'],1,'Synthetic Batch3 reversal',1);
        self::assertEquals(0,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        foreach(DB::table('journal_entries')->pluck('id') as $id)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$id)->sum(DB::raw('debit-credit')));
        self::assertEquals(0,DB::table('journal_entry_lines')->whereIn('account_id',[31,32,33,34])->sum(DB::raw('debit-credit')));
        self::assertSame(1,DB::table('vouchers')->count());
    }

    private function operation(): int
    {
        DB::table('branches')->insert(['id'=>4,'company_id'=>1,'branch_name'=>'Synthetic destination']);
        foreach([41=>['ASSET','DEBIT'],42=>['LIABILITY','CREDIT']] as $id=>[$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'DUE-'.$id,'account_name'=>'Synthetic interbranch','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1]);
        foreach(['INTERBRANCH_DUE_FROM_ACCOUNT'=>41,'INTERBRANCH_DUE_TO_ACCOUNT'=>42] as $key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
        return app(\App\Services\InventoryOperationService::class)->create(['company_id'=>1,'operation_type'=>'TRANSFER','operation_date'=>'2026-10-14','from_branch_id'=>1,'to_branch_id'=>4,'created_by'=>1,'from_lines'=>[['item_id'=>1,'qty_kg'=>5]]]);
    }

    public function test_real_interbranch_transfer_preserves_quantity_value_and_balanced_gl(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $id=$this->operation();app(\App\Services\InventoryOperationService::class)->approve(1,$id,1,1);
        self::assertEquals(5,DB::table('inventory_lots')->where('branch_id',1)->sum('qty_remaining_kg'));
        self::assertEquals(5,DB::table('inventory_lots')->where('branch_id',4)->sum('qty_remaining_kg'));
        self::assertEquals(100,DB::table('inventory_lots')->sum(DB::raw('total_cost*qty_remaining_kg/qty_received_kg')));
        self::assertEquals(0,DB::table('journal_entry_lines')->sum(DB::raw('debit-credit')));
        self::assertSame(0,DB::table('vouchers')->count());
    }

    public function test_repaired_transfer_and_purchase_do_not_recreate_the_original_lot_item_cycle(): void
    {
        for($round=1;$round<=3;$round++) {
            $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
            if($round===1)$operation=$this->operation();else $operation=app(\App\Services\InventoryOperationService::class)->create(['company_id'=>1,'operation_type'=>'TRANSFER','operation_date'=>'2026-10-14','from_branch_id'=>1,'to_branch_id'=>4,'created_by'=>1,'from_lines'=>[['item_id'=>1,'qty_kg'=>5]]]);
            $purchase=\Tests\Support\UomOperationalLockFixture::purchase(1,1);
            $key=DB::connection()->getDatabaseName().'-release-transfer';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;$wait=[];
            try {
                $a=$this->worker('transfer',1,$operation,'hold_fifo');$this->await($a,'TRANSFER_OWNS_FIFO');
                $b=$this->worker('purchase',1,$purchase);$this->await($b,'from `sulb_document_sequences`');preg_match('/CONNECTION (\d+)/',$b->getOutput(),$conn);
                $end=microtime(true)+3;
                do{$wait=DB::select('SELECT l.lock_table,l.lock_index,r.trx_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?',[(int)$conn[1]]);if(!$wait)usleep(10000);}while(!$wait&&microtime(true)<$end);
                self::assertNotEmpty($wait,'Acceptance requires a real Q wait while transfer owns FIFO');
                self::assertStringContainsString('sulb_document_sequences',json_encode($wait));
                self::assertStringNotContainsString('COMMITTED',$b->getOutput());
            } finally {DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b)$b->wait();}
            $out=$a->getOutput().$b->getOutput();self::assertStringNotContainsString('1213',$out);
            self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());
            self::assertSame('POSTED',DB::table('inventory_operations')->where('id',$operation)->value('status'));
            self::assertSame('POSTED',DB::table('purchase_invoices')->where('id',$purchase)->value('document_status'));
            if($a->getExitCode()!==0){self::assertSame('DRAFT',DB::table('inventory_operations')->where('id',$operation)->value('status'));self::assertSame(0,DB::table('inventory_lots')->where('inventory_operation_id',$operation)->count());self::assertSame(0,DB::table('stock_movements')->where('source_type','TRANSFER')->where('source_id',$operation)->count());}
            if($b->getExitCode()!==0){self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$purchase)->value('document_status'));self::assertSame(0,DB::table('inventory_lots')->where('purchase_invoice_id',$purchase)->count());self::assertSame(0,DB::table('stock_movements')->where('source_type','PURCHASE')->where('source_id',$purchase)->count());self::assertSame(0,DB::table('journal_entries')->where('source_type','PURCHASE')->where('source_id',$purchase)->count());}
            $status=DB::selectOne('SHOW ENGINE INNODB STATUS')->Status;$start=strpos($status,'LATEST DETECTED DEADLOCK');
            fwrite(STDOUT,'B3_REPAIRED_TRANSFER_SERIALIZATION '.json_encode(['round'=>$round,'wait'=>$wait,'workers'=>$out])."\n");
            foreach(DB::table('journal_entries')->pluck('id') as $j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));
            self::assertEquals(0,DB::table('inventory_lots')->where('qty_remaining_kg','<',0)->count());
        }
    }

    private function worker(string $kind,int $company,int $id,string $phase=''): Process
    {
        $p=new Process([PHP_BINARY,base_path('scripts/batch_three_producer_worker.php'),$kind,(string)$company,(string)$id,$phase],base_path());$p->setTimeout(25);$p->start();return $p;
    }

    private function await(Process $p,string $text): void
    {
        $end=microtime(true)+10;while($p->isRunning()&&!str_contains($p->getOutput(),$text)&&microtime(true)<$end)usleep(10000);
        self::assertStringContainsString($text,$p->getOutput(),$p->getErrorOutput());
    }

    private function financialHash(): string
    {
        $out=[];foreach(['purchase_invoices','journal_entries','journal_entry_lines','inventory_lots','inventory_lot_movements','stock_movements','vouchers','sulb_document_sequences','shipment_costs','shipment_items','shipments','inventory_operations','inventory_operation_lines','inventory_operation_lot_links'] as $t)$out[$t]=DB::table($t)->orderBy('id')->get()->toArray();
        return hash('sha256',serialize($out));
    }

    public function test_real_transfer_timeout_rolls_back_exact_state_then_explicit_retry_succeeds(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $id=$this->operation();$before=$this->financialHash();$worker=null;
        DB::beginTransaction();\App\Services\InventoryPostingLocks::reserveLotScope(1,'2026-10-14');
        try{$worker=$this->worker('transfer',1,$id,'timeout');$this->await($worker,'TRANSFER_HOLDS_LOTS_BEFORE_Q');$worker->wait();}
        finally{DB::rollBack();if($worker&&$worker->isRunning())$worker->wait();}
        self::assertSame(1,$worker->getExitCode());self::assertStringContainsString('1205',$worker->getOutput());self::assertStringContainsString('"level":0,"pdo":false',$worker->getOutput());
        self::assertSame($before,$this->financialHash());
        app(\App\Services\InventoryOperationService::class)->approve(1,$id,1,1);
        self::assertSame('POSTED',DB::table('inventory_operations')->where('id',$id)->value('status'));
        self::assertSame(1,DB::table('inventory_lots')->where('inventory_operation_id',$id)->count());
        fwrite(STDOUT,'B3_REAL_1205_ROLLBACK_RETRY '.$worker->getOutput()."\n");
    }

    public function test_real_processing_conserves_value_without_changing_fifo(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $service=app(\App\Services\InventoryOperationService::class);
        $id=$service->create(['company_id'=>1,'operation_type'=>'CONVERSION','operation_date'=>'2026-10-14','from_branch_id'=>1,'created_by'=>1,'from_lines'=>[['item_id'=>1,'qty_kg'=>5]],'to_lines'=>[['item_id'=>2,'qty_kg'=>5,'market_value_per_kg'=>10]]]);
        $service->approve(1,$id,1,1);
        self::assertEquals(5,DB::table('inventory_lots')->where('item_id',1)->sum('qty_remaining_kg'));
        self::assertEquals(5,DB::table('inventory_lots')->where('item_id',2)->sum('qty_remaining_kg'));
        self::assertEquals(100,DB::table('inventory_lots')->sum(DB::raw('total_cost*qty_remaining_kg/qty_received_kg')));
        self::assertEquals(0,DB::table('journal_entry_lines')->sum(DB::raw('debit-credit')));
    }

    public function test_purchase_return_post_repeat_and_void_use_original_lots(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $line=DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->value('id');$svc=app(\App\Services\CommercialReturnService::class);
        $r=$svc->saveDraft(1,1,1,['return_type'=>'PURCHASE_RETURN','return_date'=>'2026-10-14','source_invoice_id'=>$p,'lines'=>[['source_invoice_line_id'=>$line,'quantity'=>2]]]);
        $svc->post(1,$r,1,1);self::assertEquals(8,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        $count=DB::table('journal_entries')->count();$svc->post(1,$r,1,1);self::assertSame($count,DB::table('journal_entries')->count());
        $svc->void(1,$r,1,'Synthetic audit return reversal',1);self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        self::assertEquals(0,DB::table('journal_entry_lines')->sum(DB::raw('debit-credit')));
        self::assertSame(1,DB::table('inventory_lots')->count());
    }

    public function test_other_company_can_post_while_transfer_is_held_before_lot_reservation(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $id=$this->operation();$other=\Tests\Support\UomOperationalLockFixture::purchase(2,3);
        $key=DB::connection()->getDatabaseName().'-batch3-transfer';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;$started=microtime(true);
        try{$a=$this->worker('transfer',1,$id,'hold');$this->await($a,'TRANSFER_HOLDS_LOTS_BEFORE_Q');$b=$this->worker('purchase',2,$other);$this->await($b,'REQUEST_LOT_INSERT');preg_match('/CONNECTION (\d+)/',$b->getOutput(),$conn);
            $wait=[];$end=microtime(true)+3;do{$wait=DB::select('SELECT l.lock_table,l.lock_index,l.lock_type,l.lock_mode,l.lock_data,r.trx_query FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE r.trx_mysql_thread_id=?',[(int)$conn[1]]);if(!$wait)usleep(10000);}while(!$wait&&microtime(true)<$end);
            fwrite(STDOUT,'B3_CROSS_COMPANY_WAIT_EVIDENCE '.json_encode(['wait'=>$wait,'fifo_plan'=>DB::select("EXPLAIN SELECT * FROM inventory_lots WHERE company_id=1 AND branch_id=1 AND item_id=1 AND lot_status='OPEN' AND qty_remaining_kg>0 ORDER BY received_at,id FOR UPDATE")])."\n");
            $b->wait();self::assertTrue($a->isRunning());}
        finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b&&$b->isRunning())$b->wait();}
        self::assertSame(0,$a->getExitCode(),$a->getOutput());
        if($b->getExitCode()!==0&&str_contains($b->getOutput(),'1205')) {
            self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$other)->value('document_status'));
            self::assertSame(0,DB::table('inventory_lots')->where('purchase_invoice_id',$other)->count());
            self::assertSame(0,DB::table('journal_entries')->where('company_id',2)->where('source_type','PURCHASE')->where('source_id',$other)->count());
            self::assertStringContainsString('"level":0,"pdo":false',$b->getOutput());
            // Explicit retry only after confirmed server timeout/root rollback and fresh source checks.
            $retry=$this->worker('purchase',2,$other);$retry->wait();self::assertSame(0,$retry->getExitCode(),$retry->getOutput());
            fwrite(STDOUT,"B3_COMPANY2_AFTER_RELEASE_RETRY_CONFIRMED_ONE_RECEIPT\n");
        }
        // Retain independence acceptance FAIL even if the post-release retry succeeded.
        self::assertSame(0,$b->getExitCode(),$b->getOutput());
        self::assertEquals(10,DB::table('inventory_lots')->where('company_id',2)->sum('qty_remaining_kg'));
        self::assertEquals(100,DB::table('inventory_lots')->where('company_id',2)->sum('total_cost'));
        fwrite(STDOUT,'B3_CROSS_COMPANY '.json_encode(['elapsed_ms'=>(microtime(true)-$started)*1000,'company2_completed_before_release'=>true])."\n");
    }

    public function test_cross_company_source_id_rejected_without_financial_writes(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(2,3);$before=$this->financialHash();$error=null;
        try{app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);}catch(\Throwable $e){$error=$e;}
        self::assertNotNull($error);self::assertSame($before,$this->financialHash());self::assertSame(0,DB::transactionLevel());
    }

    private function sale(array $s): int
    {
        foreach([16=>['ASSET','DEBIT'],17=>['REVENUE','CREDIT'],18=>['EXPENSE','DEBIT'],19=>['REVENUE','DEBIT']] as $id=>[$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'B3-S-'.$id,'account_name'=>'Synthetic sales','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1]);
        foreach(['CUSTOMER_ACCOUNT'=>16,'SALES_ACCOUNT'=>17,'COGS_ACCOUNT'=>18,'SALES_RETURN_ACCOUNT'=>19] as $key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Synthetic audit customer','ledger_account_id'=>16,'scope_all_branches'=>1]);
        $id=DB::table('sales_invoices')->insertGetId(['company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'B3-SALE','invoice_date'=>'2026-10-14','currency_code'=>'SAR','exchange_rate'=>1,'total_before_vat'=>10,'total_amount'=>10,'base_total_before_vat'=>10,'base_total_amount'=>10,'created_by'=>1]);
        DB::table('sales_invoice_lines')->insert(['company_id'=>1,'sales_invoice_id'=>$id,'item_id'=>11,'qty_kg'=>'0.5','quantity'=>'0.5','unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>20,'total_before_vat'=>10,'total_after_vat'=>10,'line_total'=>10,'base_total_before_vat'=>10,'base_total_after_vat'=>10,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
        return $id;
    }

    public function test_sales_return_and_sale_void_restore_original_fifo_without_new_lots(): void
    {
        $s=$this->multi('20.001');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);$sale=$this->sale($s);
        app(EnterpriseInvoiceService::class)->post('SALE',1,$sale,1,1);
        self::assertEquals(52.5,DB::table('sales_line_lot_sources')->sum('total_cost'));
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');$svc=app(\App\Services\CommercialReturnService::class);
        $r=$svc->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','return_date'=>'2026-10-14','source_invoice_id'=>$sale,'lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'0.2']]]);
        $svc->post(1,$r,1,1);self::assertEquals(0.7,DB::table('inventory_lots')->where('item_id',11)->sum('qty_remaining_kg'));
        $svc->void(1,$r,1,'Synthetic audit reversal',1);self::assertEquals(0.5,DB::table('inventory_lots')->where('item_id',11)->sum('qty_remaining_kg'));
        app(EnterpriseInvoiceService::class)->void('SALE',1,$sale,1,'Synthetic sale reversal',1);
        self::assertEquals(10,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertSame(4,DB::table('inventory_lots')->count());
        self::assertEquals(0,DB::table('journal_entry_lines')->sum(DB::raw('debit-credit')));
    }

    public function test_multilot_sale_and_distribution_noop_serialize_on_item(): void
    {
        $s=$this->multi('20.001');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);$sale=$this->sale($s);
        $key=DB::connection()->getDatabaseName().'-batch2-barrier';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;$started=microtime(true);
        try {
            $a=new Process([PHP_BINARY,base_path('scripts/batch_two_integration_worker.php'),'sale','1',(string)$sale,'hold'],base_path());$a->setTimeout(25);$a->start();$this->await($a,'BEFORE_FINANCIAL_YEAR');
            $b=new Process([PHP_BINARY,base_path('scripts/batch_two_integration_worker.php'),'distribution','1',(string)$s['shipment']],base_path());$b->setTimeout(25);$b->start();$this->await($b,'from `items`');
            self::assertStringNotContainsString('COMMITTED',$b->getOutput());
        }finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b)$b->wait();}
        self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(0,$b->getExitCode(),$b->getOutput());
        self::assertEquals(9.5,DB::table('inventory_lots')->sum('qty_remaining_kg'));self::assertEquals(52.5,DB::table('sales_line_lot_sources')->sum('total_cost'));
        self::assertSame(3,DB::table('journal_entries')->count());self::assertSame(1,DB::table('vouchers')->count());
        fwrite(STDOUT,'B3_SALE_DISTRIBUTION '.json_encode(['elapsed_ms'=>(microtime(true)-$started)*1000,'pending_costs'=>0,'both_committed'=>true])."\n");
    }

    public function test_concurrent_same_transfer_rejects_second_without_duplicate_lot_or_gl(): void
    {
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);$id=$this->operation();
        $key=DB::connection()->getDatabaseName().'-batch3-transfer';DB::selectOne('SELECT GET_LOCK(?,1)',[$key]);$a=$b=null;
        try{$a=$this->worker('transfer',1,$id,'hold');$this->await($a,'TRANSFER_HOLDS_LOTS_BEFORE_Q');$b=$this->worker('transfer',1,$id);$this->await($b,'from `inventory_operations`');self::assertStringNotContainsString('COMMITTED',$b->getOutput());}
        finally{DB::selectOne('SELECT RELEASE_LOCK(?)',[$key]);if($a)$a->wait();if($b)$b->wait();}
        self::assertSame(0,$a->getExitCode(),$a->getOutput());self::assertSame(1,$b->getExitCode(),$b->getOutput());self::assertStringNotContainsString('1213',$b->getOutput());
        self::assertSame(1,DB::table('inventory_lots')->where('inventory_operation_id',$id)->count());self::assertSame(1,DB::table('journal_entries')->where('source_type','INTERBRANCH_INVENTORY_TRANSFER')->where('source_id',$id)->count());
    }
}
