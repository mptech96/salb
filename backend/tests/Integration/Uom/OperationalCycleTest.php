<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use App\Services\Uom\ItemUnitService;

final class OperationalCycleTest extends \Tests\TestCase
{
    protected function setUp():void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture','UomActorFixture'] as $file)require_once __DIR__.'/../../Support/'.$file.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');
        \Tests\Support\UomOperationalLockFixture::install();
        config(['uom.schema_install_approved'=>true,'uom.operational_enabled'=>true]);
        (require base_path('database/uom-migrations/2026_10_13_000001_uom_foundation.php'))->up();
        (require base_path('database/uom-migrations/2026_10_24_000003_uom_operational.php'))->up();
        foreach([[1,'PCS','COUNT','1',0,'STANDARD'],[2,'CARTON','COUNT',null,1,'ITEM_PACKAGING'],[3,'M','LENGTH','1',1,'STANDARD']] as [$id,$code,$dimension,$factor,$fraction,$kind])DB::table('uom_units')->insert(['id'=>$id,'code'=>$code,'name_ar'=>$code,'dimension'=>$dimension,'standard_factor'=>$factor,'allow_fractional'=>$fraction,'kind'=>$kind]);
        // Existing fixture user1 is the actual posting actor; define only item permissions.
        foreach(['items.view','items.update'] as $permission)DB::table('permissions')->insert(['permission_code'=>$permission,'permission_name'=>$permission]);
        DB::table('roles')->insert(['id'=>1,'role_code'=>'MANAGER','role_name'=>'QA']);DB::table('user_roles')->insert(['user_id'=>1,'role_id'=>1,'company_id'=>1]);
        \Illuminate\Support\Facades\Auth::login(\App\Models\User::findOrFail(1));
        $units=app(ItemUnitService::class);$units->registerNew(1,1,1);$units->reviseMapping(1,1,1,'1');$units->reviseMapping(1,1,2,'12');
        DB::table('accounts')->insert([
            ['id'=>31,'company_id'=>1,'account_code'=>'SALES','account_name'=>'QA sales','account_type'=>'REVENUE','normal_side'=>'CREDIT','allow_posting'=>1,'allow_cost_center'=>0],
            ['id'=>32,'company_id'=>1,'account_code'=>'COGS','account_name'=>'QA COGS','account_type'=>'EXPENSE','normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0],
            ['id'=>33,'company_id'=>1,'account_code'=>'AR','account_name'=>'QA receivable','account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0],
        ]);
        DB::table('items')->where('id',1)->update(['sales_account_id'=>31,'cogs_account_id'=>32,'sales_return_account_id'=>31,'purchase_return_account_id'=>11]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'QA customer','ledger_account_id'=>33,'scope_all_branches'=>1]);
    }

    private function invoice(string $mode,string $unit,string $quantity,string $price):int
    {
        return app(EnterpriseInvoiceService::class)->saveDraft($mode,[($mode==='SALE'?'customer_id':'supplier_id')=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>$unit,'quantity'=>$quantity,'unit_price'=>$price,'price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
    }

    public function test_carton_receipt_piece_sale_actual_fifo_and_balanced_gl():void
    {
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);
        $lot=DB::table('inventory_lots')->first();self::assertSame('12.000000',$lot->remaining_base_qty);self::assertSame('0.000',$lot->qty_received_kg);
        $sale=$this->invoice('SALE','PCS','5','15');app(EnterpriseInvoiceService::class)->post('SALE',1,$sale,1,1);
        $lot=DB::table('inventory_lots')->first();self::assertSame('7.000000',$lot->remaining_base_qty);self::assertSame('70.000',$lot->uom_remaining_value);
        self::assertSame('50.000',DB::table('sales_line_lot_sources')->value('total_cost'));
        self::assertSame('12.000000',DB::table('purchase_invoice_lines')->value('uom_factor_snapshot'));
        foreach(DB::table('journal_entries')->get() as $journal){$sum=DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertSame(0,bccomp((string)$sum->d,(string)$sum->c,3));}
        self::assertSame(0,DB::transactionLevel());
    }

    public function test_returns_use_original_units_after_factor_revision_and_can_be_voided():void
    {
        $service=app(EnterpriseInvoiceService::class);$returns=app(\App\Services\CommercialReturnService::class);
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');$service->post('PURCHASE',1,$purchase,1,1);
        $sale=$this->invoice('SALE','PCS','5','15');$service->post('SALE',1,$sale,1,1);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $saleLine=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');
        $return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$saleLine,'quantity'=>'2']]]);
        $returns->post(1,$return,1,1);
        self::assertSame('9.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('90.000',DB::table('inventory_lots')->value('uom_remaining_value'));
        $purchaseLine=DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$purchase)->value('id');
        $purchaseReturn=$returns->saveDraft(1,1,1,['return_type'=>'PURCHASE_RETURN','source_invoice_id'=>$purchase,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$purchaseLine,'quantity'=>'0.25']]]);
        $returns->post(1,$purchaseReturn,1,1);
        self::assertSame('3.000000',DB::table('commercial_return_lines')->where('return_id',$purchaseReturn)->value('base_quantity'));
        self::assertSame('6.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        $returns->void(1,$purchaseReturn,1,'QA reverse purchase return',1);
        $returns->void(1,$return,1,'QA reverse sale return',1);
        self::assertSame('7.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('70.000',DB::table('inventory_lots')->value('uom_remaining_value'));
        $service->void('SALE',1,$sale,1,'QA reverse sale',1);
        self::assertSame('12.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('120.000',DB::table('inventory_lots')->value('uom_remaining_value'));
        foreach(DB::table('journal_entries')->get() as $journal){$sum=DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();self::assertSame(0,bccomp((string)$sum->d,(string)$sum->c,3));}
    }

    public function test_transfer_preserves_base_quantity_and_lot_value_without_duplicate_post():void
    {
        config(['inventory_transfer.root_protocol_enabled'=>true]);
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA destination']);
        foreach(['INTERBRANCH_DUE_FROM_ACCOUNT'=>33,'INTERBRANCH_DUE_TO_ACCOUNT'=>12] as $key=>$account)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$account]);
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);
        $operations=app(\App\Services\InventoryOperationService::class);
        $id=$operations->create(['company_id'=>1,'operation_type'=>'TRANSFER','from_branch_id'=>1,'to_branch_id'=>3,'operation_date'=>'2026-10-24','created_by'=>1,'from_lines'=>[['item_id'=>1,'unit_code'=>'PCS','quantity'=>'5']]]);
        $operations->approve(1,$id,1,1);
        self::assertSame('7.000000',DB::table('inventory_lots')->where('branch_id',1)->value('remaining_base_qty'));
        self::assertSame('5.000000',DB::table('inventory_lots')->where('branch_id',3)->value('remaining_base_qty'));
        self::assertSame('50.000',DB::table('inventory_lots')->where('branch_id',3)->value('uom_remaining_value'));
        $before=DB::table('inventory_lots')->count();
        try{$operations->approve(1,$id,1,1);}catch(\RuntimeException $exception){self::assertNotEmpty($exception->getMessage());}
        self::assertSame($before,DB::table('inventory_lots')->count());
        self::assertSame('120.000',DB::table('inventory_lots')->selectRaw('SUM(uom_remaining_value) v')->first()->v);
    }

    private function worker(string $mode,int $id,string $phase=''): \Symfony\Component\Process\Process
    {
        $process=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/batch_two_integration_worker.php'),$mode,'1',(string)$id,$phase],base_path(),['SULB_UOM_OPERATIONAL_ENABLED'=>'true']);
        $process->setTimeout(25);$process->start();return $process;
    }

    public function test_three_partial_returns_reconcile_original_revenue_and_fifo_residual_exactly():void
    {
        $service=app(EnterpriseInvoiceService::class);$returns=app(\App\Services\CommercialReturnService::class);
        $purchase=$this->invoice('PURCHASE','PCS','3','3.333667');$service->post('PURCHASE',1,$purchase,1,1);
        $sale=$this->invoice('SALE','PCS','3','0.333667');$service->post('SALE',1,$sale,1,1);
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->first();
        $costs=[];$revenues=[];
        for($i=0;$i<3;$i++){
            $id=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$line->id,'quantity'=>'1']]]);
            $returns->post(1,$id,1,1);
            $posted=DB::table('commercial_return_lines')->where('return_id',$id)->first();$costs[]=$posted->inventory_cost;$revenues[]=$posted->total_before_vat;
            $before=$this->financialState();$returns->post(1,$id,1,1);self::assertSame($before,$this->financialState());
        }
        self::assertSame(['3.334','3.334','3.333'],$costs);
        self::assertSame(['0.334','0.334','0.333'],$revenues);
        self::assertSame('3.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('10.001',DB::table('inventory_lots')->value('uom_remaining_value'));
        $this->assertBooks();
    }

    private function financialState():string
    {
        $state=[];foreach(['purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','inventory_lots','inventory_lot_movements','stock_movements','sales_line_lot_sources','commercial_returns','commercial_return_lines','commercial_return_lot_sources','journal_entries','journal_entry_lines','sulb_document_sequences'] as $table)$state[$table]=DB::table($table)->orderBy('id')->get();
        return hash('sha256',serialize($state));
    }

    private function assertBooks():void
    {
        $value='0';
        foreach(DB::table('inventory_lots')->where('company_id',1)->get() as $lot){
            $quantity='0';$movementValue='0';
            foreach(DB::table('inventory_lot_movements')->where('inventory_lot_id',$lot->id)->get() as $movement){
                self::assertSame('0.000',$movement->qty_kg);
                $quantity=$movement->movement_type==='IN'?bcadd($quantity,$movement->base_quantity,6):bcsub($quantity,$movement->base_quantity,6);
                $movementValue=$movement->movement_type==='IN'?bcadd($movementValue,$movement->total_cost,3):bcsub($movementValue,$movement->total_cost,3);
            }
            self::assertSame($lot->remaining_base_qty,$quantity);
            self::assertSame($lot->uom_remaining_value,$movementValue);
            $value=bcadd($value,$lot->uom_remaining_value,3);
        }
        $gl=DB::table('journal_entry_lines')->where('company_id',1)->where('account_id',11)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first();
        self::assertSame(0,bccomp($value,(string)$gl->v,3));
        foreach(DB::table('journal_entries')->where('company_id',1)->get() as $journal){
            $sum=DB::table('journal_entry_lines')->where('journal_entry_id',$journal->id)->selectRaw('SUM(debit) d,SUM(credit) c')->first();
            self::assertSame(0,bccomp((string)$sum->d,(string)$sum->c,3));
        }
    }

    public function test_http_kernel_purchase_sale_return_and_void_preserve_frozen_units_and_tenant_scope():void
    {
        DB::table('plans')->insert(['id'=>1,'plan_code'=>'UOM-CYCLE','plan_name'=>'Synthetic QA']);
        foreach([1,2] as $company)DB::table('subscriptions')->insert(['company_id'=>$company,'plan_id'=>1,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        foreach(['purchases','sales','inventory','accounting'] as $feature)DB::table('plan_features')->insert(['plan_id'=>1,'feature_code'=>$feature,'is_enabled'=>1]);
        $this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        $purchase=$this->postJson('/api/purchase-invoices',['company_id'=>2,'branch_id'=>1,'supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'CARTON','quantity'=>'1','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]])->assertCreated()->json('id');
        $this->postJson('/api/purchase-invoices/'.$purchase.'/post')->assertOk();
        self::assertSame(1,(int)DB::table('purchase_invoices')->where('id',$purchase)->value('company_id'));
        $sale=$this->postJson('/api/sales-invoices',['branch_id'=>1,'customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'PCS','quantity'=>'5','unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0]]])->assertCreated()->json('id');
        $this->postJson('/api/sales-invoices/'.$sale.'/post')->assertOk();$this->assertBooks();
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');
        $return=$this->postJson('/api/commercial-returns',['branch_id'=>1,'return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'2']]])->assertCreated()->json('id');
        $blocker=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $before=$this->financialState();DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        $blocker->beginTransaction();$blocker->query('SELECT id FROM financial_years WHERE id=1 FOR UPDATE')->fetchAll();
        try{
            $failed=$this->postJson('/api/commercial-returns/'.$return.'/post')->assertUnprocessable();
            self::assertStringContainsString('1205',$failed->getContent());self::assertStringNotContainsString('SQLSTATE',$failed->getContent());
            self::assertSame($before,$this->financialState());self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
        }finally{$blocker->rollBack();DB::statement('SET SESSION innodb_lock_wait_timeout=50');}
        $this->postJson('/api/commercial-returns/'.$return.'/post')->assertOk();$this->assertBooks();
        self::assertSame('9.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        $before=$this->financialState();$this->postJson('/api/commercial-returns/'.$return.'/post')->assertOk();self::assertSame($before,$this->financialState());
        $this->postJson('/api/commercial-returns/'.$return.'/void',['reason'=>'QA original return reversal'])->assertOk();
        $this->postJson('/api/sales-invoices/'.$sale.'/void',['reason'=>'QA original invoice reversal'])->assertOk();$this->assertBooks();
        self::assertSame('12.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('120.000',DB::table('inventory_lots')->value('uom_remaining_value'));
        DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>1,'company_id'=>2]);
        $this->actingAs(\App\Models\User::findOrFail(2),'sanctum');
        $before=$this->financialState();
        $this->getJson('/api/purchase-invoices/'.$purchase)->assertNotFound();
        $this->postJson('/api/sales-invoices/'.$sale.'/post')->assertNotFound();
        $this->postJson('/api/commercial-returns/'.$return.'/void',['reason'=>'Foreign company attempt'])->assertNotFound();
        self::assertSame($before,$this->financialState());
    }

    public function test_over_return_duplicate_source_rows_and_unknown_outer_transactions_are_rejected_atomically():void
    {
        $service=app(EnterpriseInvoiceService::class);$returns=app(\App\Services\CommercialReturnService::class);
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');$service->post('PURCHASE',1,$purchase,1,1);
        $sale=$this->invoice('SALE','PCS','5','15');
        $before=$this->financialState();DB::beginTransaction();
        try{try{$service->post('SALE',1,$sale,1,1);self::fail('Unknown root adopted');}catch(\RuntimeException $error){self::assertSame('UOM_TRUSTED_POSTING_ROOT_REQUIRED',$error->getMessage());}self::assertTrue(DB::connection()->getPdo()->inTransaction());self::assertSame($before,$this->financialState());}finally{DB::rollBack();}
        $service->post('SALE',1,$sale,1,1);$line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');
        $before=$this->financialState();
        try{$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'3'],['source_invoice_line_id'=>$line,'quantity'=>'3']]]);self::fail('Oversubscribed source accepted');}catch(\RuntimeException $error){self::assertSame('UOM_RETURN_EXCEEDS_SOURCE',$error->getMessage());}
        self::assertSame($before,$this->financialState());
        $return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'1']]]);
        $before=$this->financialState();DB::beginTransaction();
        try{try{$returns->post(1,$return,1,1);self::fail('Unknown return root adopted');}catch(\RuntimeException $error){self::assertSame('UOM_TRUSTED_RETURN_ROOT_REQUIRED',$error->getMessage());}self::assertTrue(DB::connection()->getPdo()->inTransaction());self::assertSame($before,$this->financialState());}finally{DB::rollBack();}
        $service->void('SALE',1,$sale,1,'Void original before return posting',1);$before=$this->financialState();
        try{$returns->post(1,$return,1,1);self::fail('Return of VOID invoice posted');}catch(\RuntimeException $error){self::assertSame('UOM_RETURN_SOURCE_NOT_POSTED',$error->getMessage());}
        self::assertSame($before,$this->financialState());$this->assertBooks();
    }

    public function test_half_carton_sale_return_uses_original_snapshot_and_duplicate_posts_do_nothing():void
    {
        $service=app(EnterpriseInvoiceService::class);$returns=app(\App\Services\CommercialReturnService::class);
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');$service->post('PURCHASE',1,$purchase,1,1);
        $before=$this->financialState();$service->post('PURCHASE',1,$purchase,1,1);self::assertSame($before,$this->financialState());
        $sale=$this->invoice('SALE','CARTON','0.5','180');$service->post('SALE',1,$sale,1,1);
        $before=$this->financialState();$service->post('SALE',1,$sale,1,1);self::assertSame($before,$this->financialState());
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->first();
        self::assertSame('6.000000',$line->base_quantity);self::assertSame('90.000',$line->base_total_before_vat);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $id=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-14','lines'=>[['source_invoice_line_id'=>$line->id,'quantity'=>'0.25']]]);
        $returns->post(1,$id,1,1);$this->assertBooks();
        self::assertSame('9.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('45.000',DB::table('commercial_return_lines')->where('return_id',$id)->value('base_total_before_vat'));
        $before=$this->financialState();$returns->post(1,$id,1,1);self::assertSame($before,$this->financialState());
        try{$service->void('SALE',1,$sale,1,'Active return must block',1);self::fail('Active return was ignored');}catch(\RuntimeException $error){self::assertSame('UOM_VOID_ACTIVE_RETURN_EXISTS',$error->getMessage());}
        self::assertSame($before,$this->financialState());
        $returns->void(1,$id,1,'QA reverse return',1);$this->assertBooks();
        self::assertStringStartsWith(date('Y-m-d'),DB::table('inventory_lot_movements')->where('source_type','SALES_RETURN_REVERSAL')->value('movement_at'));
        $before=$this->financialState();try{$returns->void(1,$id,1,'Duplicate reversal',1);self::fail('Repeated void accepted');}catch(\RuntimeException $error){self::assertNotEmpty($error->getMessage());}self::assertSame($before,$this->financialState());
        $service->void('SALE',1,$sale,1,'QA reverse original sale',1);$this->assertBooks();
        $service->void('PURCHASE',1,$purchase,1,'QA reverse original purchase',1);$this->assertBooks();
        self::assertSame('0.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
        self::assertSame('0.000',DB::table('inventory_lots')->value('uom_remaining_value'));
    }

    public function test_two_lots_fifo_and_full_return_restore_exact_original_costs():void
    {
        $service=app(EnterpriseInvoiceService::class);$returns=app(\App\Services\CommercialReturnService::class);
        foreach([['CARTON','1','120'],['PCS','1','20']] as [$unit,$quantity,$price]){$id=$this->invoice('PURCHASE',$unit,$quantity,$price);$service->post('PURCHASE',1,$id,1,1);}
        $sale=$this->invoice('SALE','PCS','13','25');$service->post('SALE',1,$sale,1,1);$this->assertBooks();
        $sources=DB::table('sales_line_lot_sources')->orderBy('id')->get();
        self::assertSame(['12.000000','1.000000'],$sources->pluck('base_quantity')->all());
        self::assertSame(['120.000','20.000'],$sources->pluck('total_cost')->all());
        $line=DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->value('id');
        $return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','source_invoice_id'=>$sale,'return_date'=>'2026-10-24','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>'13']]]);
        $returns->post(1,$return,1,1);$this->assertBooks();
        self::assertSame(['120.000','20.000'],DB::table('inventory_lots')->orderBy('id')->pluck('uom_remaining_value')->all());
    }

    private function awaitFinancialWait(\Symfony\Component\Process\Process $worker):void
    {
        $observer=new \PDO('mysql:host=127.0.0.1;port=3339;dbname='.DB::connection()->getDatabaseName(),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $holder=(int)DB::selectOne('SELECT CONNECTION_ID() id')->id;
        $end=microtime(true)+6;$found=false;$method='';$waiter=0;
        do{
            if(preg_match('/CONNECTION (\d+)/',$worker->getOutput(),$match)){
                $waiter=(int)$match[1];
                $statement=$observer->prepare('SELECT l.lock_table FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.blocking_lock_id WHERE t.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?');
                $statement->execute([$waiter,$holder]);$rows=$statement->fetchAll(\PDO::FETCH_OBJ);$statement->closeCursor();
                if($rows&&str_contains($rows[0]->lock_table,'financial_years')){$found=true;$method='INNODB_LOCK_WAITS';}
                if(!$found){
                    // MariaDB's information_schema snapshot can omit this active waiter.
                    // The CURRENT transaction section of the engine monitor must prove
                    // LOCK WAIT, exact worker thread, target table, and known holder.
                    $monitor=$observer->query('SHOW ENGINE INNODB STATUS')->fetch(\PDO::FETCH_ASSOC)['Status'];
                    $position=strpos($monitor,'LIST OF TRANSACTIONS FOR EACH SESSION:');
                    $transactions=$position===false?'':substr($monitor,$position);
                    $transactions=explode('FILE I/O',$transactions,2)[0];
                    $blocks=preg_split('/(?=^---TRANSACTION )/m',$transactions);
                    $waiting=false;$holding=false;
                    foreach($blocks as $block){
                        if(preg_match('/(?:MySQL|MariaDB) thread id '.$waiter.',/',$block)&&str_contains($block,'LOCK WAIT')&&str_contains($block,'`'.DB::connection()->getDatabaseName().'`.`financial_years`')&&str_contains($block,'index PRIMARY')&&str_contains($block,' waiting'))$waiting=true;
                        if(preg_match('/(?:MySQL|MariaDB) thread id '.$holder.',/',$block)&&str_contains($block,'row lock(s)'))$holding=true;
                    }
                    if($waiting&&$holding){$found=true;$method='CURRENT_INNODB_MONITOR';}
                }
            }
            if(!$found)usleep(10000);
        }while(!$found&&$worker->isRunning()&&microtime(true)<$end);
        self::assertTrue($found,$worker->getOutput().$worker->getErrorOutput());
        fwrite(STDOUT,'UOM_FY_WAIT '.json_encode(['waiter'=>$waiter,'holder'=>$holder,'table'=>'financial_years','evidence'=>$method]).PHP_EOL);
    }

    public function test_real_1205_and_1213_after_base_fifo_write_roll_back_every_effect():void
    {
        $purchase=$this->invoice('PURCHASE','CARTON','1','120');app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$purchase,1,1);
        $sale=$this->invoice('SALE','PCS','5','15');$baseline=$this->financialState();
        foreach([1205,1213] as $code){
            $worker=null;DB::beginTransaction();
            DB::table('financial_years')->where('id',1)->lockForUpdate()->first();
            if($code===1213){$rows=[];for($i=0;$i<500;$i++)$rows[]=['company_id'=>1,'item_code'=>'VICTIM-'.$i,'item_name'=>'Synthetic rollback weight'];DB::table('items')->insert($rows);}
            try{
                $worker=$this->worker('sale',$sale);$this->awaitFinancialWait($worker);
                if($code===1213)DB::table('items')->where('id',1)->lockForUpdate()->first();
                else $worker->wait();
            }finally{DB::rollBack();if($worker)$worker->wait();}
            self::assertSame(1,$worker->getExitCode(),$worker->getOutput());
            self::assertStringContainsString((string)$code,$worker->getOutput());
            self::assertStringContainsString('"level":0,"pdo":false',$worker->getOutput());
            self::assertSame($baseline,$this->financialState());
        }
        app(EnterpriseInvoiceService::class)->post('SALE',1,$sale,1,1);
        self::assertSame('7.000000',DB::table('inventory_lots')->value('remaining_base_qty'));
    }
}
