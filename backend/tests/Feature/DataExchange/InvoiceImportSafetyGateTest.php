<?php

namespace Tests\Feature\DataExchange;

use App\Services\DefaultPartyService;
use App\Services\EnterpriseInvoiceService;
use App\Services\MigrationCenterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InvoiceImportSafetyGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('data_migration_batches', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('entity_code'); $t->string('file_name')->nullable(); $t->string('source_system')->nullable();
            $t->string('import_mode'); $t->string('posting_mode'); $t->string('status');
            $t->unsignedInteger('total_rows')->default(0); $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('imported_rows')->default(0); $t->unsignedInteger('skipped_rows')->default(0);
            $t->unsignedInteger('failed_rows')->default(0); $t->text('options_json')->nullable();
            $t->unsignedBigInteger('started_by')->nullable(); $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable(); $t->timestamps();
        });
        Schema::create('data_migration_row_logs', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('batch_id');
            $t->unsignedInteger('row_number')->nullable(); $t->string('external_key')->nullable();
            $t->string('row_status'); $t->text('message')->nullable(); $t->text('payload_json')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('branches', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('branch_code'); $t->string('branch_name')->nullable(); $t->string('city')->nullable(); $t->boolean('is_active')->default(true);
        });
        Schema::create('items', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('item_code'); $t->string('item_name');
            $t->string('item_type')->default('STOCK'); $t->boolean('is_active')->default(true);
        });
        Schema::create('accounts', function (Blueprint $t): void {
            $t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('account_code');$t->string('account_name');
            $t->string('account_type');$t->string('normal_side');$t->unsignedInteger('account_level')->default(1);$t->boolean('is_group')->default(false);
            $t->boolean('allow_posting')->default(true);$t->boolean('allow_cost_center')->default(false);$t->boolean('is_active')->default(true);$t->text('notes')->nullable();$t->timestamps();
        });
        foreach (['sales_invoices', 'purchase_invoices'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id');
                $t->string('invoice_number'); $t->string('document_status')->default('DRAFT');
            });
        }
        foreach (['customers', 'suppliers'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id(); $t->unsignedBigInteger('company_id');
                $t->string($table === 'customers' ? 'customer_code' : 'supplier_code')->nullable();
                $t->string($table === 'customers' ? 'customer_name' : 'supplier_name');
            });
        }
        foreach (['journal_entries', 'stock_movements', 'sales_line_lot_sources', 'inventory_lot_movements'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
        }
        DB::table('branches')->insert([
            ['id'=>1,'company_id'=>10,'branch_code'=>'MAIN','branch_name'=>'الرئيسي','is_active'=>1],
            ['id'=>2,'company_id'=>10,'branch_code'=>'OTHER','branch_name'=>'الآخر','is_active'=>1],
            ['id'=>3,'company_id'=>20,'branch_code'=>'FOREIGN','branch_name'=>'أجنبي','is_active'=>1],
        ]);
        DB::table('items')->insert(['id'=>1,'company_id'=>10,'item_code'=>'IRON','item_name'=>'حديد','item_type'=>'STOCK','is_active'=>1]);
        DB::table('accounts')->insert(['id'=>1,'company_id'=>10,'account_code'=>'1000','account_name'=>'الأصول','account_type'=>'ASSET','normal_side'=>'DEBIT','account_level'=>1,'is_group'=>1,'allow_posting'=>0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('customers')->insert(['id'=>1,'company_id'=>10,'customer_code'=>'C1','customer_name'=>'عميل اختبار']);
        DB::table('suppliers')->insert(['id'=>1,'company_id'=>10,'supplier_code'=>'S1','supplier_name'=>'مورد اختبار']);
    }

    public function test_post_mode_is_rejected_before_any_batch_or_financial_write(): void
    {
        $service=$this->service();
        try {
            $service->import(10,1,7,'sales_invoices',$this->parsed('S-1'),$this->mapping('sales_invoices'),['posting_mode'=>'POST'],['name'=>'uat.csv']);
            self::fail('POST import must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('مسودات فقط',$e->getMessage());
        }
        self::assertSame(0,DB::table('data_migration_batches')->count());
        $this->assertNoPostingEffects();
    }

    public function test_purchase_and_sales_import_create_draft_only_without_posting_effects(): void
    {
        foreach ([['purchase_invoices','P-1'],['sales_invoices','S-1']] as [$entity,$number]) {
            $invoice=Mockery::mock(EnterpriseInvoiceService::class);
            $invoice->shouldReceive('saveDraft')->once()->andReturn(100);
            $invoice->shouldNotReceive('post');
            $service=$this->service($invoice);
            $result=$service->import(10,1,7,$entity,$this->parsed($number),$this->mapping($entity),['posting_mode'=>'DRAFT','existing_draft_policy'=>'SKIP_EXISTING'],['name'=>'uat.csv']);
            self::assertSame(1,$result['stats']['imported']);
        }
        self::assertSame(2,DB::table('data_migration_batches')->where('posting_mode','DRAFT')->count());
        $this->assertNoPostingEffects();
    }

    public function test_existing_draft_and_posted_invoices_are_never_overwritten(): void
    {
        DB::table('sales_invoices')->insert([
            ['company_id'=>10,'branch_id'=>1,'invoice_number'=>'DRAFT-1','document_status'=>'DRAFT'],
            ['company_id'=>10,'branch_id'=>1,'invoice_number'=>'POSTED-1','document_status'=>'POSTED'],
        ]);
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);$invoice->shouldNotReceive('saveDraft');$invoice->shouldNotReceive('post');
        foreach (['DRAFT-1','POSTED-1'] as $number) {
            $result=$this->service($invoice)->import(10,1,7,'sales_invoices',$this->parsed($number),$this->mapping('sales_invoices'),['posting_mode'=>'DRAFT'],['name'=>'retry.csv']);
            self::assertSame(1,$result['stats']['skipped']);
        }
        self::assertSame(2,DB::table('sales_invoices')->count());
        $this->assertNoPostingEffects();
    }

    public function test_branch_limited_import_cannot_select_another_company_branch(): void
    {
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);$invoice->shouldNotReceive('saveDraft');
        $parsed=$this->parsed('S-2','OTHER');
        try{$this->service($invoice)->import(10,1,7,'sales_invoices',$parsed,$this->mapping('sales_invoices'),['posting_mode'=>'DRAFT'],['name'=>'branch.csv']);self::fail('Foreign branch must fail preflight.');}
        catch(\RuntimeException $e){self::assertStringContainsString('فشل فحص الملف',$e->getMessage());}
        self::assertSame(0,DB::table('data_migration_batches')->count());
    }

    public function test_preview_validates_entire_file_and_performs_no_write(): void
    {
        $parsed=$this->parsed('S-3');
        $parsed['rows'][]=['row_number'=>3,'data'=>array_merge($parsed['rows'][0]['data'],['invoice_number'=>'S-4','item_code'=>'MISSING'])];
        $preview=$this->service()->preview(10,'sales_invoices',$parsed,$this->mapping('sales_invoices'),1);
        self::assertTrue($preview['preview_read_only']);
        self::assertSame(2,$preview['validated_rows']);
        self::assertSame(1,$preview['sample_invalid']);
        self::assertSame(0,DB::table('data_migration_batches')->count());
    }

    public function test_exact_duplicate_invoice_line_is_rejected_without_creating_a_document(): void
    {
        $parsed=$this->parsed('S-DUP');$parsed['rows'][]=['row_number'=>3,'data'=>$parsed['rows'][0]['data']];
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);$invoice->shouldNotReceive('saveDraft');$invoice->shouldNotReceive('post');
        $preview=$this->service($invoice)->preview(10,'sales_invoices',$parsed,$this->mapping('sales_invoices'),1);
        self::assertSame(1,$preview['sample_invalid']);
        try{$this->service($invoice)->import(10,1,7,'sales_invoices',$parsed,$this->mapping('sales_invoices'),['posting_mode'=>'DRAFT'],['name'=>'duplicate.csv']);self::fail('Invalid file must fail before batch creation.');}
        catch(\RuntimeException $e){self::assertStringContainsString('فشل فحص الملف',$e->getMessage());}
        self::assertSame(0,DB::table('data_migration_batches')->count());
        $this->assertNoPostingEffects();
    }

    public function test_multi_line_invoice_is_created_as_one_draft_with_all_lines(): void
    {
        $parsed=$this->parsed('S-MULTI');
        $second=$parsed['rows'][0]['data'];$second['quantity']='20';$parsed['rows'][]=['row_number'=>3,'data'=>$second];
        $invoice=Mockery::mock(EnterpriseInvoiceService::class);
        $invoice->shouldReceive('saveDraft')->once()->withArgs(function($mode,$payload,$cid,$bid,$uid,$existing): bool {
            return $mode==='SALE'&&$cid===10&&$bid===1&&$existing===null&&count($payload['items'])===2;
        })->andReturn(501);
        $invoice->shouldNotReceive('post');
        $result=$this->service($invoice)->import(10,1,7,'sales_invoices',$parsed,$this->mapping('sales_invoices'),['posting_mode'=>'DRAFT'],['name'=>'multi.csv']);
        self::assertSame(1,$result['stats']['imported']);
        $this->assertNoPostingEffects();
    }

    public function test_invalid_numeric_and_cross_company_references_are_rejected_in_read_only_preview(): void
    {
        DB::table('items')->insert(['id'=>2,'company_id'=>20,'item_code'=>'FOREIGN-ITEM','item_name'=>'أجنبي','item_type'=>'STOCK','is_active'=>1]);
        $parsed=$this->parsed('S-BAD');$parsed['rows'][0]['data']['quantity']='not-a-number';$parsed['rows'][0]['data']['item_code']='FOREIGN-ITEM';
        $preview=$this->service()->preview(10,'sales_invoices',$parsed,$this->mapping('sales_invoices'),1);
        self::assertSame('ERROR',$preview['sample'][0]['status']);
        self::assertStringContainsString('رقمًا',implode(' | ',$preview['sample'][0]['errors']));
        self::assertStringContainsString('الصنف غير موجود',implode(' | ',$preview['sample'][0]['errors']));
        self::assertSame(0,DB::table('data_migration_batches')->count());
    }

    public function test_csv_parser_is_preserved_and_export_respects_company_and_branch_scope(): void
    {
        $parsed=$this->service()->parseRaw("invoice_number,invoice_date,branch_code,customer_code,item_code,quantity,unit_price\nS-CSV,2026-08-27,MAIN,C1,IRON,10,2\n",'safe.csv');
        self::assertSame(1,count($parsed['rows']));
        $rows=$this->service()->exportRows(10,'branches_export',['branch_id'=>1]);
        self::assertCount(1,$rows);self::assertSame('MAIN',$rows[0]['branch_code']);
        self::assertNotContains('FOREIGN',array_column($rows,'branch_code'));
    }

    public function test_account_import_skips_existing_and_creates_only_new_company_scoped_account(): void
    {
        $mapping=['account_code'=>'account_code','account_name'=>'account_name','parent_account_code'=>'parent_account_code','account_type'=>'account_type','normal_side'=>'normal_side','is_group'=>'is_group'];
        $parsed=['headers'=>array_values($mapping),'rows'=>[
            ['row_number'=>2,'data'=>['account_code'=>'1000','account_name'=>'اسم لا يجب استبداله','parent_account_code'=>'','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>'1']],
            ['row_number'=>3,'data'=>['account_code'=>'1100','account_name'=>'النقدية','parent_account_code'=>'1000','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>'0']],
        ]];
        $result=$this->service()->import(10,1,7,'accounts',$parsed,$mapping,['posting_mode'=>'DRAFT','existing_draft_policy'=>'SKIP_EXISTING'],['name'=>'accounts.csv']);
        self::assertSame(1,$result['stats']['skipped']);self::assertSame(1,$result['stats']['imported']);
        self::assertSame('الأصول',DB::table('accounts')->where('company_id',10)->where('account_code','1000')->value('account_name'));
        self::assertSame(1,(int)DB::table('accounts')->where('company_id',10)->where('account_code','1100')->value('parent_id'));
        self::assertSame(0,DB::table('accounts')->where('company_id',20)->count());
    }

    public function test_routes_keep_permission_entitlement_tenant_and_support_guards(): void
    {
        $routes=file_get_contents(base_path('routes/api.php'));
        $permissions=file_get_contents(app_path('Http/Middleware/EnsureRoutePermission.php'));
        self::assertStringContainsString("Route::post('/imports/{entity}'",$routes);
        self::assertStringContainsString("'imports.execute'",$permissions);
        self::assertStringContainsString("'imports.view'",$permissions);
        self::assertStringContainsString("'support.access'",$routes);
        self::assertStringContainsString("'feature.entitlement'",$routes);
        self::assertStringContainsString("'tenant.scope'",$routes);
    }

    private function service(?EnterpriseInvoiceService $invoice=null): MigrationCenterService
    {
        $invoice??=Mockery::mock(EnterpriseInvoiceService::class);
        $defaults=Mockery::mock(DefaultPartyService::class);
        $defaults->shouldReceive('ensure')->zeroOrMoreTimes()->andReturn(['default_customer_id'=>1,'default_supplier_id'=>1]);
        return new MigrationCenterService($invoice,$defaults);
    }

    private function parsed(string $number,string $branch='MAIN'): array
    {
        return ['headers'=>array_values($this->mapping('sales_invoices')),'rows'=>[['row_number'=>2,'data'=>[
            'invoice_number'=>$number,'invoice_date'=>'2026-08-27','branch_code'=>$branch,
            'customer_code'=>'C1','supplier_code'=>'S1','item_code'=>'IRON','quantity'=>'10','unit_price'=>'2',
        ]]]];
    }

    private function mapping(string $entity): array
    {
        return ['invoice_number'=>'invoice_number','invoice_date'=>'invoice_date','branch_code'=>'branch_code',
            ($entity==='sales_invoices'?'customer_code':'supplier_code')=>($entity==='sales_invoices'?'customer_code':'supplier_code'),
            'item_code'=>'item_code','quantity'=>'quantity','unit_price'=>'unit_price'];
    }

    private function assertNoPostingEffects(): void
    {
        self::assertSame(0,DB::table('journal_entries')->count(),'No GL journal may be created by DRAFT import.');
        self::assertSame(0,DB::table('stock_movements')->count(),'No stock movement may be created by DRAFT import.');
        self::assertSame(0,DB::table('sales_line_lot_sources')->count(),'No FIFO/COGS allocation may be created by DRAFT import.');
        self::assertSame(0,DB::table('inventory_lot_movements')->count(),'No lot movement may be created by DRAFT import.');
    }
}
