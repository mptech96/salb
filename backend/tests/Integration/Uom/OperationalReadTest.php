<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use App\Services\Uom\ItemUnitService;

/** New read/report acceptance only; does not inherit or rerun the closed cycle tests. */
final class OperationalReadTest extends \Tests\TestCase
{
    protected function tearDown():void
    {DB::disconnect();parent::tearDown();gc_collect_cycles();}
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


    private function buy(int $item,string $quantity,string $unit,string $price):int
    {
        $id=app(EnterpriseInvoiceService::class)->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>$item,'unit_code'=>$unit,'quantity'=>$quantity,'unit_price'=>$price,'price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$id,1,1);
        return $id;
    }
    public function test_mixed_base_legacy_weight_and_service_invoice_reports_preserve_each_contract():void
    {
        $this->buy(1,'1','CARTON','120');$service=app(EnterpriseInvoiceService::class);
        $purchase=\Tests\Support\UomOperationalLockFixture::purchase(1,2);$service->post('PURCHASE',1,$purchase,1,1);
        DB::table('items')->where('id',2)->update(['sales_account_id'=>31,'cogs_account_id'=>32]);
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'QA-HOUR','item_name'=>'Synthetic service','item_type'=>'SERVICE','track_inventory'=>0,'base_unit_code'=>'UNIT','sales_account_id'=>31]);
        $sale=$service->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[
            ['item_id'=>1,'unit_code'=>'PCS','quantity'=>'1','unit_price'=>'15','price_unit'=>'UNIT','vat_percent'=>0],
            ['item_id'=>2,'qty_kg'=>2,'unit_price'=>15,'price_unit'=>'KG','vat_percent'=>0],
            ['item_id'=>4,'unit_code'=>'HOUR','quantity'=>'1.25','unit_price'=>'20','price_unit'=>'UNIT','vat_percent'=>0],
        ]],1,1,1);$service->post('SALE',1,$sale,1,1);
        $reports=app(\App\Services\ReportCenterService::class);$rows=$reports->run(1,1,'sales-items')['rows']->keyBy('item_code');
        self::assertSame('PCS',$rows['LOCK-1']->display_unit);self::assertSame('1.000000',$rows['LOCK-1']->display_quantity);
        self::assertSame('TON',$rows['LOCK-2']->display_unit);self::assertSame('0.002',$rows['LOCK-2']->display_quantity);
        self::assertSame('HOUR',$rows['QA-HOUR']->display_unit);self::assertSame('1.250000',$rows['QA-HOUR']->display_quantity);
        self::assertSame('1.250000 HOUR / 2.000000 KG / 1.000000 PCS',$reports->run(1,1,'sales')['rows']->sole()->quantity_summary);
        $profit=$reports->run(1,1,'item-profit')['rows']->keyBy('item_code');
        self::assertSame('HOUR',$profit['QA-HOUR']->base_unit_code);self::assertSame('1.250000',$profit['QA-HOUR']->sold_base_quantity);
        self::assertSame('KG',$profit['LOCK-2']->base_unit_code);self::assertSame('2.000000',$profit['LOCK-2']->sold_base_quantity);
        self::assertSame('10.000',DB::table('sales_line_lot_sources')->where('sales_invoice_line_id',DB::table('sales_invoice_lines')->where('sales_invoice_id',$sale)->where('item_id',1)->value('id'))->value('total_cost'));
        self::assertCount(0,$reports->run(2,2,'sales')['rows']);
    }

    public function test_invoice_item_reports_keep_original_unit_quantity_and_price_after_mapping_revision():void
    {
        $this->buy(1,'1','CARTON','120');
        $sale=app(EnterpriseInvoiceService::class)->saveDraft('SALE',['customer_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'PCS','quantity'=>'5','unit_price'=>'12.123456','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
        app(EnterpriseInvoiceService::class)->post('SALE',1,$sale,1,1);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $reports=app(\App\Services\ReportCenterService::class);
        $purchase=$reports->run(1,1,'purchase-items')['rows']->sole();
        self::assertSame('1.000000',$purchase->display_quantity);
        self::assertSame('CARTON',$purchase->display_unit);
        self::assertSame('120.000000',$purchase->display_unit_price);
        $sold=$reports->run(1,1,'sales-items')['rows']->sole();
        self::assertSame('5.000000',$sold->display_quantity);
        self::assertSame('PCS',$sold->display_unit);
        self::assertSame('12.123456',$sold->display_unit_price);
        $profit=$reports->run(1,1,'item-profit')['rows']->sole();
        self::assertSame('5.000000',$profit->sold_base_quantity);self::assertSame('PCS',$profit->base_unit_code);
        self::assertSame('0.000',$profit->sold_kg);self::assertSame(50.0,(float)$profit->cost_total);
        self::assertCount(0,$reports->run(2,2,'item-profit')['rows']);
        self::assertCount(0,$reports->run(2,2,'sales-items')['rows']);
        self::assertCount(0,$reports->run(2,2,'purchase-items')['rows']);
        self::assertSame('7.000000',DB::table('inventory_lots')->where('company_id',1)->value('remaining_base_qty'));
        $exports=app(\App\Services\MigrationCenterService::class);
        $purchaseExport=$exports->exportRows(1,'purchase_invoices')[0];
        self::assertSame('1.000000',$purchaseExport['quantity']);self::assertSame('CARTON',$purchaseExport['unit_code']);self::assertSame('120.000000',$purchaseExport['unit_price']);
        $saleExport=$exports->exportRows(1,'sales_invoices')[0];
        self::assertSame('5.000000',$saleExport['quantity']);self::assertSame('PCS',$saleExport['unit_code']);self::assertSame('12.123456',$saleExport['unit_price']);
        $balance=$exports->exportRows(1,'inventory_balances')[0];
        self::assertSame('7.000000',$balance['quantity']);self::assertSame('PCS',$balance['unit_code']);self::assertSame(0,bccomp('70.000',$balance['remaining_value'],3));
        self::assertSame([],$exports->exportRows(2,'sales_invoices'));
    }

    public function test_transfer_metadata_uses_company_scoped_current_mapping():void
    {
        $units=app(ItemUnitService::class);
        $units->reviseMapping(1,1,2,'24');
        $items=DB::table('items')->where('company_id',1)->get(['id','base_unit_code']);
        \App\Services\Uom\OperationalDocument::enrichItems(1,$items,false,true);
        $first=$items->firstWhere('id',1);
        self::assertSame('BASE_V1',$first->inventory_quantity_version);
        self::assertSame(['CARTON','PCS'],array_column($first->uom_units,'code'));
        self::assertSame('24.000000',$first->uom_units[0]->factor_to_base);
        self::assertNotContains(3,$items->pluck('id')->all());
        self::assertSame(0,DB::table('inventory_operation_lines')->count());
    }

    public function test_transfer_controller_accepts_selected_carton_snapshot_and_preserves_value():void
    {
        config(['inventory_transfer.root_protocol_enabled'=>true]);
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA UOM destination']);
        foreach(['INTERBRANCH_DUE_FROM_ACCOUNT'=>33,'INTERBRANCH_DUE_TO_ACCOUNT'=>12] as $key=>$account)DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$account]);
        $this->buy(1,'2','CARTON','120');
        $request=\Illuminate\Http\Request::create('/api/inventory-operations','POST',[
            'operation_type'=>'TRANSFER','from_branch_id'=>1,'to_branch_id'=>3,'operation_date'=>'2026-10-24',
            'from_lines'=>[['item_id'=>1,'unit_code'=>'CARTON','quantity'=>'1']],
        ]);
        $request->setUserResolver(fn()=>\Illuminate\Support\Facades\Auth::user());
        $request->attributes->set('tenant_company_id',1);
        $request->attributes->set('tenant_branch_id',1);
        $request->attributes->set('effective_role_code','MANAGER');
        $service=app(\App\Services\InventoryOperationService::class);
        $response=app(\App\Http\Controllers\Api\InventoryOperationController::class)->store($request,$service,app(\App\Services\Accounting\AccountingContext::class));
        self::assertSame(201,$response->getStatusCode(),$response->getContent());
        $id=$response->getData(true)['id'];
        $line=DB::table('inventory_operation_lines')->where('operation_id',$id)->sole();
        self::assertSame('1.000000',$line->quantity);
        self::assertSame('12.000000',$line->base_quantity);
        self::assertSame('CARTON',$line->unit_code);
        app(ItemUnitService::class)->reviseMapping(1,1,2,'24');
        $service->approve(1,$id,1,1);
        self::assertSame('12.000000',DB::table('inventory_lots')->where('branch_id',1)->value('remaining_base_qty'));
        self::assertSame('12.000000',DB::table('inventory_lots')->where('branch_id',3)->value('remaining_base_qty'));
        self::assertSame('120.000',DB::table('inventory_lots')->where('branch_id',3)->value('uom_remaining_value'));
        self::assertSame('240.000',DB::table('inventory_lots')->selectRaw('SUM(uom_remaining_value) v')->first()->v);
        $report=app(\App\Services\ReportCenterService::class)->run(1,1,'inventory-operations')['rows']->sole();
        self::assertSame('12.000000 PCS',$report->quantity_summary);
        self::assertCount(0,app(\App\Services\ReportCenterService::class)->run(2,2,'inventory-operations')['rows']);
        self::assertSame(0,DB::transactionLevel());
    }

    public function test_mixed_unit_summary_reports_and_valuation_do_not_drop_tiny_nonweight_lots():void
    {
        $this->buy(1,'1','CARTON','120');
        app(ItemUnitService::class)->registerNew(1,2,3);
        app(ItemUnitService::class)->reviseMapping(1,2,3,'1');
        $this->buy(2,'0.000001','M','1000000');
        DB::table('items')->insert(['id'=>4,'company_id'=>1,'item_code'=>'OLD-KG','item_name'=>'Historical kilogram contract','inventory_account_id'=>11]);
        $legacy=\Tests\Support\UomOperationalLockFixture::purchase(1,4);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$legacy,1,1);
        $foreign=\Tests\Support\UomOperationalLockFixture::purchase(2,3);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',2,$foreign,2,2);
        $summary=app(\App\Services\InventoryLotService::class)->summary(1,1)->keyBy('item_id');
        self::assertCount(3,$summary);
        self::assertSame('12.000000',$summary[1]->balance_base_quantity);
        self::assertSame('PCS',$summary[1]->base_unit_code);
        self::assertSame('0.000001',$summary[2]->balance_base_quantity);
        self::assertSame(1,(int)$summary[2]->open_lots);
        self::assertSame('M',$summary[2]->base_unit_code);
        self::assertEquals(10,$summary[4]->balance_kg);
        self::assertEquals(0,$summary[1]->balance_kg);
        $reports=app(\App\Services\ReportCenterService::class);
        $inventory=$reports->run(1,1,'inventory');
        self::assertCount(3,$inventory['rows']);
        self::assertEquals(221,$inventory['summary']['stock_value']);
        self::assertEquals(10,$inventory['summary']['balance_kg']);
        $lots=$reports->run(1,1,'inventory-lots');
        self::assertCount(3,$lots['rows']);
        self::assertSame('0.000001',$lots['rows']->firstWhere('item_code','LOCK-2')->remaining_base_quantity);
        $movements=$reports->run(1,1,'inventory-movements');
        self::assertSame('0.000001',$movements['rows']->firstWhere('base_unit_code','M')->movement_base_quantity);
        $request=\Illuminate\Http\Request::create('/api/inventory/valuation');
        $request->attributes->set('tenant_company_id',1);
        $request->attributes->set('tenant_branch_id',1);
        $request->attributes->set('effective_role_code','MANAGER');
        $response=app(\App\Http\Controllers\Api\InventoryController::class)->valuation($request,app(\App\Services\Accounting\AccountingContext::class))->getData(true)['data'];
        self::assertEquals(221,$response['stock_value']);
        self::assertSame(3,$response['open_lots']);
        $groups=collect($response['quantities_by_unit'])->keyBy('base_unit_code');
        self::assertSame('12.000000',$groups['PCS']['base_quantity']);
        self::assertSame('0.000001',$groups['M']['base_quantity']);
        self::assertSame('10.000000',$groups['KG']['base_quantity']);
        self::assertStringNotContainsString('UNKNOWN',json_encode($response));
        $mixed=app(EnterpriseInvoiceService::class)->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[
            ['item_id'=>1,'unit_code'=>'PCS','quantity'=>'2','unit_price'=>'10','price_unit'=>'UNIT','vat_percent'=>0],
            ['item_id'=>2,'unit_code'=>'M','quantity'=>'0.000001','unit_price'=>'1000000','price_unit'=>'UNIT','vat_percent'=>0],
        ]],1,1,1);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$mixed,1,1);
        $document=$reports->run(1,1,'purchases')['rows']->firstWhere('id',$mixed);
        self::assertSame('0.000001 M / 2.000000 PCS',$document->quantity_summary);
        $executive=$reports->run(1,1,'executive')['rows']->keyBy('indicator');
        self::assertSame('14.000000 PCS',$executive['رصيد المخزون PCS']['value']);
        self::assertSame('0.000002 M',$executive['رصيد المخزون M']['value']);
        self::assertSame('10.000000 KG',$executive['رصيد المخزون KG']['value']);
        $export=collect(app(\App\Services\MigrationCenterService::class)->exportRows(1,'inventory_balances'))->firstWhere('item_code','LOCK-2');
        self::assertSame('0.000001',$export['quantity']);self::assertSame('M',$export['unit_code']);
        self::assertSame(0,bccomp('1.000',$export['remaining_value'],3));
    }
}
