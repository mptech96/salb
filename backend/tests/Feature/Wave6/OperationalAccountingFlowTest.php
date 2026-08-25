<?php

declare(strict_types=1);

namespace Tests\Feature\Wave6;

use App\Services\InventoryLotService;
use App\Services\TaxEngineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\PlatformControlPlaneTestCase;

class OperationalAccountingFlowTest extends PlatformControlPlaneTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach(['inventory_lot_movements','inventory_lots','tax_codes','company_settings']as$table)Schema::dropIfExists($table);
        Schema::create('inventory_lots',function(Blueprint$t):void{$t->id();foreach(['parent_lot_id','origin_lot_id','company_id','branch_id','item_id','car_id','shipment_id','shipment_item_id','purchase_invoice_id','purchase_invoice_line_id','source_id','inventory_operation_id','created_by']as$c)$t->unsignedBigInteger($c)->nullable();$t->string('lot_number');$t->string('source_type')->nullable();$t->dateTime('received_at');foreach(['qty_received_kg','qty_remaining_kg','qty_sold_kg','base_cost','allocated_cost','total_cost','unit_cost_per_kg']as$c)$t->decimal($c,18,6)->default(0);$t->string('lot_status');$t->text('notes')->nullable();$t->timestamps();});
        Schema::create('inventory_lot_movements',function(Blueprint$t):void{$t->id();foreach(['company_id','branch_id','inventory_lot_id','item_id','source_id','created_by']as$c)$t->unsignedBigInteger($c)->nullable();$t->string('movement_type');$t->string('source_type');$t->dateTime('movement_at');foreach(['qty_kg','unit_cost_per_kg','total_cost']as$c)$t->decimal($c,18,6)->default(0);$t->text('notes')->nullable();$t->timestamps();});
        Schema::create('company_settings',function(Blueprint$t):void{$t->id();$t->unsignedBigInteger('company_id');$t->boolean('tax_inclusive_prices')->default(false);$t->unsignedBigInteger('default_purchase_tax_code_id')->nullable();$t->unsignedBigInteger('default_sales_tax_code_id')->nullable();});
        Schema::create('tax_codes',function(Blueprint$t):void{$t->id();$t->unsignedBigInteger('company_id');$t->string('tax_code');$t->string('tax_name');$t->decimal('rate',8,3);$t->boolean('is_exempt')->default(false);$t->boolean('is_out_of_scope')->default(false);$t->boolean('is_active')->default(true);$t->date('valid_from')->nullable();$t->date('valid_to')->nullable();$t->unsignedBigInteger('purchase_tax_account_id')->nullable();$t->unsignedBigInteger('sales_tax_account_id')->nullable();});
    }

    public function test_fifo_depletes_oldest_lot_and_preserves_exact_remaining_value():void
    {
        $lots=app(InventoryLotService::class);$a=$lots->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>100,'base_cost'=>1000,'received_at'=>'2026-01-01']);$b=$lots->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>100,'base_cost'=>1200,'received_at'=>'2026-01-02']);
        $result=DB::transaction(fn()=>$lots->consumeFifo(1,1,1,150,'SALE',77));
        self::assertSame(1600.0,$result['total_cost']);self::assertSame([100.0,50.0],array_column($result['allocations'],'qty_kg'));
        self::assertSame(0.0,(float)DB::table('inventory_lots')->where('id',$a)->value('qty_remaining_kg'));self::assertSame(50.0,(float)DB::table('inventory_lots')->where('id',$b)->value('qty_remaining_kg'));
        self::assertSame(600.0,round((float)DB::table('inventory_lots')->where('id',$b)->value('qty_remaining_kg')*(float)DB::table('inventory_lots')->where('id',$b)->value('unit_cost_per_kg'),3));
    }

    public function test_insufficient_fifo_stock_rejects_without_partial_consumption():void
    {
        $lots=app(InventoryLotService::class);$id=$lots->createInboundLot(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'qty_kg'=>100,'base_cost'=>1000,'received_at'=>'2026-01-01']);
        try{DB::transaction(fn()=>$lots->consumeFifo(1,1,1,101,'SALE',78));self::fail('Insufficient sale was accepted.');}catch(RuntimeException){self::assertSame(100.0,(float)DB::table('inventory_lots')->where('id',$id)->value('qty_remaining_kg'));self::assertSame(1,DB::table('inventory_lot_movements')->count());}
    }

    public function test_vat_engine_reconciles_exclusive_and_inclusive_fifteen_percent():void
    {
        DB::table('company_settings')->insert(['company_id'=>1,'tax_inclusive_prices'=>0]);$tax=DB::table('tax_codes')->insertGetId(['company_id'=>1,'tax_code'=>'VAT15','tax_name'=>'VAT 15%','rate'=>15,'is_active'=>1]);$engine=app(TaxEngineService::class);
        $exclusive=$engine->line(1,1000,0,$tax,'2026-08-24');self::assertSame(1000.0,$exclusive['total_before_vat']);self::assertSame(150.0,$exclusive['vat_amount']);self::assertSame(1150.0,$exclusive['total_after_vat']);
        DB::table('company_settings')->where('company_id',1)->update(['tax_inclusive_prices'=>1]);$inclusive=$engine->line(1,1150,0,$tax,'2026-08-24');self::assertSame(1000.0,$inclusive['total_before_vat']);self::assertSame(150.0,$inclusive['vat_amount']);
    }

    public function test_purchase_and_sale_posting_are_atomic_locked_and_idempotent():void
    {
        $source=$this->productionSource('app/Services/EnterpriseInvoiceService.php');self::assertStringContainsString('return DB::transaction',$source);self::assertStringContainsString('lockForUpdate()->first()',$source);self::assertStringContainsString("'document_status'=>'POSTED'",$source);self::assertStringContainsString("'message'=>'الفاتورة مرحلة مسبقًا.'",$source);self::assertStringContainsString('postPurchaseInventory',$source);self::assertStringContainsString('postSaleInventory',$source);
    }

    public function test_journal_engine_rejects_imbalance_and_foreign_dimensions():void
    {
        $source=$this->productionSource('app/Domain/Accounting/Services/JournalService.php');self::assertStringContainsString('abs($totalDebit-$totalCredit)>0.0001',$source);self::assertStringContainsString("->where('company_id',\$companyId)",$source);self::assertStringContainsString("'financial_year_id'=>\$fy->id",$source);self::assertStringContainsString('assertBranch($companyId',$source);
    }

    public function test_returns_restore_or_remove_fifo_and_reverse_vat_and_journal():void
    {
        $source=$this->productionSource('app/Services/CommercialReturnService.php');foreach(['restoreSaleLots','removePurchaseLots','عكس ضريبة مخرجات','عكس ضريبة مدخلات','qty_remaining_kg','returnedQty','journals->reverse']as$rule)self::assertStringContainsString($rule,$source);
    }

    public function test_weighbridge_is_physical_evidence_and_latest_non_cancelled_reading_wins():void
    {
        $source=$this->productionSource('app/Services/WeighbridgeService.php');self::assertStringContainsString("whereNull('cancelled_at')",$source);self::assertStringContainsString("orderByDesc('recorded_at')->orderByDesc('id')",$source);self::assertStringContainsString("if(\$card->status!=='OPEN')",$source);self::assertStringNotContainsString('JournalService',$source);self::assertStringNotContainsString('journal_entries',$source);
    }

    public function test_opening_payroll_shipment_and_inventory_operations_are_retry_safe():void
    {
        $opening=$this->productionSource('app/Services/OpeningBalanceService.php');self::assertStringContainsString("if(\$b->status==='POSTED')return",$opening);self::assertStringContainsString('lockForUpdate()->first()',$opening);
        $payroll=$this->productionSource('app/Services/Payroll/PayrollPayment.php');self::assertStringContainsString('lockForUpdate()->first()',$payroll);self::assertStringContainsString('DB::transaction',$payroll);
        foreach(['app/Services/ShipmentService.php','app/Services/ApproveShipmentService.php','app/Services/InventoryOperationService.php']as$file){$source=$this->productionSource($file);self::assertStringContainsString('DB::transaction',$source);self::assertStringContainsString('lockForUpdate()',$source);}
        foreach(['app/Services/Accounting/ExpensePosting.php','app/Services/Accounting/VoucherPosting.php']as$file){$source=$this->productionSource($file);self::assertStringContainsString('DB::transaction',$source);self::assertStringContainsString('lockForUpdate()',$source);self::assertStringContainsString('مرحل مسبقًا',$source);}
    }

    public function test_document_sequences_and_reports_are_company_financial_year_scoped():void
    {
        $numbers=$this->productionSource('app/Services/DocumentNumberService.php');foreach(['company_id','branch_id','document_year','lockForUpdate']as$rule)self::assertStringContainsString($rule,$numbers);
        $reports=$this->productionSource('app/Domain/Accounting/Services/AccountingReportService.php');self::assertStringContainsString("where('l.company_id',\$companyId)",$reports);self::assertStringContainsString("where('e.status','POSTED')",$reports);
    }
}
