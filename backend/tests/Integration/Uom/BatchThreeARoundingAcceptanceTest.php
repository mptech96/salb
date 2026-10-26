<?php
namespace Tests\Integration\Uom;

use App\Services\EnterpriseInvoiceService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Required acceptances: unresolved expectations stay failing, never SKIP. */
class BatchThreeARoundingAcceptanceTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$migration->up();
        // Exercise the new operational protocol, not independent calculator mocks.
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_parent_fk.php');$migration->up();
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_cost_allocation_receipts.php');$migration->up();
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/inventory_lot_value_balances.php');$migration->up();
        config(['shipment_cost_read.enabled'=>true,'shipment_allocation.enabled'=>true,'inventory_value.enabled'=>true]);
    }

    private function purchase(bool $reverse=false,bool $twoCosts=false): array
    {
        $s=F::purchase();
        DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>'20.001','foreign_amount'=>'20.001']);
        DB::table('shipment_items')->where('id',$s['line'])->delete();
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->delete();
        // Stable item/account identity, same economic data; only input order changes.
        $order=$reverse?[4,3,2,1]:[1,2,3,4];
        foreach($order as $position=>$n){
            $item=10+$n;$account=30+$n;
            if(!DB::table('items')->where('id',$item)->exists()){
                DB::table('accounts')->insert(['id'=>$account,'company_id'=>1,'account_code'=>'RND-'.$n,'account_name'=>'Synthetic inventory '.$n,'account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1]);
                DB::table('items')->insert(['id'=>$item,'company_id'=>1,'item_code'=>'RND-'.$n,'item_name'=>'Synthetic '.$n,'inventory_account_id'=>$account]);
            }
            $row=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$s['shipment'],'item_id'=>$item,'qty_kg'=>$n,'accepted_qty_kg'=>$n,'remaining_qty_kg'=>$n,'base_cost'=>100,'sorting_order'=>$position+1]);
            DB::table('purchase_invoice_lines')->insert(['company_id'=>1,'purchase_invoice_id'=>$s['invoice'],'item_id'=>$item,'shipment_id'=>$s['shipment'],'shipment_item_id'=>$row,'qty_kg'=>$n,'quantity'=>$n,'unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>100/$n,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
        }
        DB::table('purchase_invoices')->where('id',$s['invoice'])->update(['total_before_vat'=>400,'total_amount'=>400,'base_total_before_vat'=>400,'base_total_amount'=>400]);
        DB::table('invoice_shipment_links')->where('invoice_id',$s['invoice'])->update(['allocated_amount'=>400]);
        if($twoCosts)app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$s['shipment'],'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>'20.001','currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>true,'created_by'=>1]);
        return $s;
    }

    private function values(array $s): array
    {
        $journals=DB::table('shipment_costs')->where('shipment_id',$s['shipment'])->pluck('journal_entry_id');$values=[];
        foreach(DB::table('shipment_items')->where('shipment_id',$s['shipment'])->orderBy('item_id')->get() as $r){
            $account=DB::table('items')->where('id',$r->item_id)->value('inventory_account_id');
            $values[$r->item_id]=['gl'=>bcadd((string)DB::table('journal_entry_lines')->whereIn('journal_entry_id',$journals)->where('account_id',$account)->sum('debit'),'0',3),'lot'=>bcadd((string)DB::table('inventory_lots')->where('id',$r->inventory_lot_id)->value('allocated_cost'),'0',3),'distribution'=>$r->allocated_cost];
        }
        fwrite(STDOUT,'B3A_VALUES '.json_encode($values)."\n");return $values;
    }

    public function test_single_item_control_reconciles(): void
    {
        $s=F::purchase();app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));
        self::assertEquals(20,DB::table('journal_entry_lines')->where('journal_entry_id',DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id'))->where('account_id',11)->sum('debit'));
    }

    public function test_public_shipment_save_allows_identical_rows_without_a_unique_economic_key(): void
    {
        // Schema/identity proof, NOT acceptance of a rounding fix or full shipment E2E.
        $line=['item_id'=>1,'gross_qty_kg'=>5,'accepted_qty_kg'=>5,'unit_price'=>10,'price_unit'=>'KG','vat_percent'=>0];
        $id=app(\App\Services\ShipmentService::class)->save(['shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-14','supplier_id'=>1,'currency_code'=>'SAR','exchange_rate'=>1,'items'=>[$line,$line]],1,1,1);
        $rows=DB::table('shipment_items')->where('company_id',1)->where('shipment_id',$id)->orderBy('id')->get();
        self::assertCount(2,$rows);
        $signatures=[];
        foreach($rows as $row){
            self::assertNull($row->purchase_line_id);self::assertNull($row->inventory_lot_id);
            $signatures[]=json_encode([$row->company_id,$row->shipment_id,$row->item_id,$row->accepted_qty_kg,$row->base_cost,$row->cost_share_percent,$row->manual_allocated_cost,$row->currency_code,$row->exchange_rate]);
        }
        self::assertSame($signatures[0],$signatures[1]);
        self::assertNotSame($rows[0]->id,$rows[1]->id);
        // Approved quantum is compatible with storage; item identity is NOT row-unique.
        foreach(['shipment_costs'=>'amount','inventory_lots'=>'allocated_cost','journal_entry_lines'=>'debit'] as $table=>$column){
            $scale=DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())->where('TABLE_NAME',$table)->where('COLUMN_NAME',$column)->value('NUMERIC_SCALE');
            self::assertSame(3,(int)$scale);
        }
        $units='20001';$weight='50000';$totalWeight='100000';
        $product=bcmul($units,$weight,0);$floor=bcdiv($product,$totalWeight,0);$remainder=bcmod($product,$totalWeight,0);
        self::assertSame('10000',$floor);self::assertSame('50000',$remainder);
        self::assertSame('1',bcsub($units,bcmul($floor,'2',0),0));
        fwrite(STDOUT,'B3A_ECONOMIC_KEY_BLOCKER '.json_encode(['duplicate_item_rows'=>2,'same_economic_signature'=>true,'source_line_and_lot_before_post'=>null,'floor_units_each'=>$floor,'fraction_numerator_each'=>$remainder,'unassigned_units'=>1,'money_scale'=>3])."\n");
    }

    public function test_batch3_reordered_rows_must_reconcile_gl_with_lots(): void
    {
        $s=$this->purchase();
        // Preserve insertion order while reversing presentation order, original B3-001.
        foreach(DB::table('shipment_items')->where('shipment_id',$s['shipment'])->get() as $r)DB::table('shipment_items')->where('id',$r->id)->update(['sorting_order'=>5-$r->sorting_order]);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        foreach($this->values($s) as $value){self::assertSame($value['lot'],$value['distribution']);self::assertSame($value['gl'],$value['lot']);}
    }

    public function test_batch3_multiple_costs_must_reconcile_gl_with_lots(): void
    {
        $s=$this->purchase(false,true);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        foreach($this->values($s) as $value)self::assertSame($value['gl'],$value['lot']);
    }

    public function test_same_economic_data_must_not_depend_on_input_order(): void
    {
        $a=$this->purchase();app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$a['invoice'],1,1);$va=$this->values($a);
        $b=$this->purchase(true);app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$b['invoice'],1,1);$vb=$this->values($b);
        // Both GL and LOT agree within each shipment, but input order changes the recipient.
        foreach([$va,$vb] as $set)foreach($set as $v)self::assertSame($v['gl'],$v['lot']);
        self::assertSame(array_column($va,'lot'),array_column($vb,'lot'),'Approved Largest Remainder still requires an implemented, unique economic tie-breaker');
    }

    private function makeIdenticalRows(array $s): void
    {
        $last=DB::table('shipment_items')->where('shipment_id',$s['shipment'])->orderByDesc('id')->first();
        DB::table('shipment_items')->where('id',$last->id)->update(['item_id'=>11,'qty_kg'=>1,'accepted_qty_kg'=>1,'remaining_qty_kg'=>1]);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->where('shipment_item_id',$last->id)->update(['item_id'=>11,'qty_kg'=>1,'quantity'=>1,'unit_price'=>100]);
    }

    public function test_unresolved_identical_lot_tie_is_rejected_before_any_financial_or_inventory_write(): void
    {
        $s=$this->purchase(); $this->makeIdenticalRows($s); $writes=[]; $active=true;
        DB::listen(function($event)use(&$writes,&$active){if($active&&preg_match('/^(insert|update|delete) /i',$event->sql))$writes[]=$event->sql;});
        try { app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1); self::fail('Unresolved tie posted'); }
        catch (\RuntimeException $error) { self::assertSame('UNRESOLVED_ECONOMIC_TIE',$error->getMessage()); }
        finally { $active=false; }
        self::assertSame([],$writes); self::assertSame(0,DB::table('inventory_lots')->count()); self::assertSame(0,DB::table('journal_entries')->count());
        self::assertSame(0,DB::table('vouchers')->count()); self::assertSame(0,DB::table('shipment_cost_allocation_receipts')->count());
        self::assertSame(0,DB::transactionLevel()); self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_identical_lots_with_divisible_cost_are_allowed(): void
    {
        $s=$this->purchase(); $this->makeIdenticalRows($s);
        DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>'20.000','foreign_amount'=>'20.000']);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        self::assertSame(4,DB::table('inventory_lots')->count());
        self::assertEquals(20,DB::table('inventory_lots')->sum('allocated_cost'));
        self::assertEquals(10,DB::table('inventory_lots')->where('item_id',11)->sum('allocated_cost'));
        self::assertSame(1,DB::table('shipment_cost_allocation_receipts')->count());
    }

    public function test_tiny_cost_preserves_zero_shares_in_receipt_without_zero_gl_lines(): void
    {
        $s=$this->purchase(); DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>'0.003','foreign_amount'=>'0.003']);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $receipt=DB::table('shipment_cost_allocation_receipts')->first(); $payload=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR);
        self::assertCount(4,$payload['recipients']); self::assertSame(1,count(array_filter($payload['recipients'],fn($row)=>$row['amount']==='0.000')));
        self::assertSame(hash('sha256',$receipt->payload),$receipt->payload_hash);
        self::assertSame('0.003',bcadd((string)DB::table('inventory_lots')->sum('allocated_cost'),'0',3));
        self::assertSame('0.003',bcadd((string)DB::table('journal_entry_lines')->where('journal_entry_id',$receipt->journal_entry_id)->sum('debit'),'0',3));
        self::assertSame(4,DB::table('journal_entry_lines')->where('journal_entry_id',$receipt->journal_entry_id)->count());
    }

    public function test_receipt_survives_duplicate_post_distribution_and_purchase_void_without_recalculation(): void
    {
        $s=$this->purchase(false,true); $service=app(EnterpriseInvoiceService::class);
        $service->post('PURCHASE',1,$s['invoice'],1,1);
        $before=DB::table('shipment_cost_allocation_receipts')->orderBy('id')->get()->toJson();
        $journals=DB::table('journal_entries')->count(); $vouchers=DB::table('vouchers')->count();
        $service->post('PURCHASE',1,$s['invoice'],1,1);
        app(\App\Services\ShipmentCostDistributionService::class)->distributeByShipmentId(1,$s['shipment']);
        self::assertSame($journals,DB::table('journal_entries')->count()); self::assertSame($vouchers,DB::table('vouchers')->count());
        self::assertSame($before,DB::table('shipment_cost_allocation_receipts')->orderBy('id')->get()->toJson());
        $service->void('PURCHASE',1,$s['invoice'],1,'Isolated allocation reversal',1);
        self::assertSame($before,DB::table('shipment_cost_allocation_receipts')->orderBy('id')->get()->toJson());
        self::assertEquals(0,DB::table('inventory_lots')->sum('qty_remaining_kg'));
        foreach(DB::table('journal_entries')->pluck('id') as $journal)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->sum(DB::raw('debit-credit')));
    }

    public function test_changed_plan_inputs_roll_back_everything_instead_of_recomputing(): void
    {
        $s=$this->purchase(); $active=true;
        DB::listen(function($event)use(&$active,$s){if($active&&str_starts_with($event->sql,'insert into `inventory_lots`')){$active=false;DB::table('shipments')->where('id',$s['shipment'])->update(['cost_allocation_method'=>'WEIGHT']);}});
        try { app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1); self::fail('Stale plan used'); }
        catch (\RuntimeException $error) { self::assertSame('ALLOCATION_INPUTS_CHANGED',$error->getMessage()); }
        finally { $active=false; }
        self::assertSame(0,DB::table('inventory_lots')->count()); self::assertSame(0,DB::table('journal_entries')->count()); self::assertSame(0,DB::table('vouchers')->count());
        self::assertSame(0,DB::table('shipment_cost_allocation_receipts')->count()); self::assertSame(0,DB::transactionLevel()); self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_cost_creation_order_does_not_change_item_totals(): void
    {
        $results=[];
        foreach([['7.001','3.002'],['3.002','7.001']] as $amounts){
            $s=$this->purchase(); DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>$amounts[0],'foreign_amount'=>$amounts[0]]);
            app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$s['shipment'],'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>$amounts[1],'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>'PAID','financial_account_id'=>1,'capitalizable'=>true,'created_by'=>1]);
            app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
            $values=$this->values($s); foreach($values as $value)self::assertSame($value['gl'],$value['lot']);
            $results[]=array_column($values,'lot');
            self::assertSame(2,DB::table('shipment_cost_allocation_receipts')->where('shipment_id',$s['shipment'])->count());
        }
        self::assertSame($results[0],$results[1]);
    }

    public function test_fifo_issue_values_must_conserve_the_capitalized_lot_total(): void
    {
        $s=$this->purchase(); app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $lot=DB::table('inventory_lots')->where('item_id',11)->first(); $issued='0.000';
        foreach([1,2] as $request){
            $result=DB::transaction(fn()=>app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,11,0.5,'SALE',900+$request,null,1));
            $issued=bcadd($issued,(string)$result['total_cost'],3);
        }
        self::assertSame('0.000',bcadd((string)DB::table('inventory_lots')->where('id',$lot->id)->value('qty_remaining_kg'),'0',3));
        self::assertSame(bcadd((string)$lot->total_cost,'0',3),$issued,'Final FIFO issue must not duplicate a milli-unit of LOT cost');
    }

    public function test_tracked_fifo_sale_return_void_and_report_conserve_source_values(): void
    {
        $s=$this->purchase();$service=app(EnterpriseInvoiceService::class);$service->post('PURCHASE',1,$s['invoice'],1,1);
        foreach([16=>['ASSET','DEBIT'],17=>['REVENUE','CREDIT'],18=>['EXPENSE','DEBIT'],19=>['REVENUE','DEBIT']] as $id=>[$type,$side])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'VALUE-'.$id,'account_name'=>'Synthetic value proof','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1]);
        foreach(['CUSTOMER_ACCOUNT'=>16,'SALES_ACCOUNT'=>17,'COGS_ACCOUNT'=>18,'SALES_RETURN_ACCOUNT'=>19] as $key=>$id)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$id]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Synthetic value customer','ledger_account_id'=>16,'scope_all_branches'=>1]);
        $sales=[];$costs=[];
        foreach([1,2] as $n){
            $id=DB::table('sales_invoices')->insertGetId(['company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'VALUE-SALE-'.$n,'invoice_date'=>'2026-10-14','currency_code'=>'SAR','exchange_rate'=>1,'total_before_vat'=>100,'total_amount'=>100,'base_total_before_vat'=>100,'base_total_amount'=>100,'created_by'=>1]);
            $line=DB::table('sales_invoice_lines')->insertGetId(['company_id'=>1,'sales_invoice_id'=>$id,'item_id'=>11,'qty_kg'=>'0.5','quantity'=>'0.5','unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>200,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
            $service->post('SALE',1,$id,1,1);$sales[]=[$id,$line];
            $costs[]=bcadd((string)DB::table('sales_line_lot_sources')->where('sales_invoice_line_id',$line)->sum('total_cost'),'0',3);
            if($n===1){
                $summary=app(\App\Services\InventoryLotService::class)->summary(1,1)->firstWhere('item_id',11);self::assertEqualsWithDelta(52.500,(float)$summary->stock_value,0.0000001);
                $report=app(\App\Services\ReportCenterService::class)->run(1,1,'inventory');
                self::assertEqualsWithDelta(52.500,(float)$report['rows']->firstWhere('item_id',11)->stock_value,0.0000001);
            }
        }
        self::assertSame(['52.501','52.500'],$costs);
        $returns=app(\App\Services\CommercialReturnService::class);
        $return=$returns->saveDraft(1,1,1,['return_type'=>'SALES_RETURN','return_date'=>'2026-10-14','source_invoice_id'=>$sales[1][0],'lines'=>[['source_invoice_line_id'=>$sales[1][1],'quantity'=>'0.5']]]);
        $returns->post(1,$return,1,1);
        self::assertSame('52.500',bcadd((string)DB::table('commercial_return_lot_sources')->sum('total_cost'),'0',3));
        $returns->void(1,$return,1,'Isolated tracked return reversal',1);
        $service->void('SALE',1,$sales[1][0],1,'Isolated tracked sale reversal',1);
        $service->void('SALE',1,$sales[0][0],1,'Isolated tracked sale reversal',1);
        $lot=DB::table('inventory_lots')->where('item_id',11)->first();
        $balance=DB::table('inventory_lot_value_balances')->where('inventory_lot_id',$lot->id)->first();
        self::assertSame('105.001',$balance->remaining_value);self::assertSame('1.000',$balance->remaining_quantity);
        foreach(DB::table('journal_entries')->pluck('id') as $journal)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->sum(DB::raw('debit-credit')));
        self::assertSame(0,DB::transactionLevel());self::assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_approved_same_item_larger_basis_receives_last_milli_unit():void
    {
        $s=$this->purchase();$rows=DB::table('shipment_items')->where('shipment_id',$s['shipment'])->orderBy('id')->get();
        foreach($rows as $index=>$row){
            if($index>1){DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->where('shipment_item_id',$row->id)->delete();DB::table('shipment_items')->where('id',$row->id)->delete();continue;}
            $basis=$index===0?'1.000':'3.000';
            DB::table('shipment_items')->where('id',$row->id)->update(['item_id'=>11,'qty_kg'=>1,'accepted_qty_kg'=>1,'remaining_qty_kg'=>1,'base_cost'=>$basis]);
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->where('shipment_item_id',$row->id)->update(['item_id'=>11,'qty_kg'=>1,'quantity'=>1,'unit_price'=>$basis,'total_before_vat'=>$basis,'total_after_vat'=>$basis,'line_total'=>$basis,'base_total_before_vat'=>$basis,'base_total_after_vat'=>$basis]);
        }
        DB::table('purchase_invoices')->where('id',$s['invoice'])->update(['total_before_vat'=>4,'total_amount'=>4,'base_total_before_vat'=>4,'base_total_amount'=>4]);
        DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>'0.002','foreign_amount'=>'0.002']);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        self::assertSame('0.000',DB::table('inventory_lots')->where('shipment_item_id',$rows[0]->id)->value('allocated_cost'));
        self::assertSame('0.002',DB::table('inventory_lots')->where('shipment_item_id',$rows[1]->id)->value('allocated_cost'));
        $receipt=DB::table('shipment_cost_allocation_receipts')->first();
        self::assertSame('0.002',bcadd((string)DB::table('journal_entry_lines')->where('journal_entry_id',$receipt->journal_entry_id)->sum('debit'),'0',3));
    }
}
