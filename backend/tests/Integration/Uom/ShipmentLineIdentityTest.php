<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ShipmentPurchaseLockFixture as F;
use Tests\Support\ShipmentLineIdentityPrototype as Identity;

class ShipmentLineIdentityTest extends \Tests\TestCase
{
    private array $legacy=[];
    private array $legacyRow=[];
    private string $financialBefore='';
    protected function setUp(): void
    {
        parent::setUp();foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture','ShipmentLineIdentityPrototype'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        $this->legacy=F::shipment();
        if($this->name()==='test_historical_null_is_readable_unchanged_and_cannot_be_backfilled'){
            $this->legacy=F::purchase();app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$this->legacy['invoice'],1,1);
        }
        $this->legacyRow=(array)DB::table('shipment_items')->where('id',$this->legacy['line'])->first();
        $this->financialBefore=$this->finance();
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_line_identity.php');$migration->up();
        DB::table('roles')->insert(['id'=>99,'role_code'=>'MANAGER','role_name'=>'Synthetic manager']);
        DB::table('plans')->insert(['id'=>99,'plan_name'=>'Synthetic identity','plan_code'=>'IDENTITY-TEST']);
        foreach([1,2] as $c){DB::table('user_roles')->insert(['user_id'=>$c,'company_id'=>$c,'role_id'=>99,'is_active'=>1]);DB::table('subscriptions')->insert(['company_id'=>$c,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);}
        foreach(array_keys(config('sulb_features.features')) as $feature)DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
        $middleware=['auth:sanctum','auth.context','company.context','support.access','subscription.access','tenant.scope','feature.entitlement','usage.limit','route.permission'];
        Route::middleware($middleware)->post('/api/shipments/{shipment}/identity-lines',function(\Illuminate\Http\Request $r,int $shipment){try{return response()->json(app(Identity::class)->create($r,$shipment),201);}catch(\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}catch(\Throwable $e){return response()->json(['error'=>$e->getMessage()],422);}});
        Route::middleware($middleware)->patch('/api/shipments/{shipment}/identity-lines/{line}',function(\Illuminate\Http\Request $r,int $shipment,int $line){try{return response()->json(app(Identity::class)->update($r,$shipment,$line));}catch(\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}catch(\Throwable $e){return response()->json(['error'=>$e->getMessage()],422);}});
        Sanctum::actingAs(\App\Models\User::findOrFail(1),['*']);
    }
    private function shipment(int $company=1): int
    {
        return DB::table('shipments')->insertGetId(['company_id'=>$company,'branch_id'=>$company,'supplier_id'=>$company,'shipment_type'=>'PURCHASE','shipment_number'=>'IDENTITY-'.bin2hex(random_bytes(4)),'shipment_date'=>'2026-10-14','commercial_status'=>'DRAFT','currency_code'=>'SAR','exchange_rate'=>1]);
    }
    private function finance(): string
    {
        $out=[];foreach(['inventory_lots','inventory_lot_movements','stock_movements','journal_entries','journal_entry_lines','vouchers','sulb_document_sequences'] as $table)$out[$table]=DB::table($table)->orderBy('id')->get();return serialize($out);
    }
    private function create(int $s,string $business='SUP-100/LINE-A',int $item=1): array
    {
        $response=$this->postJson('/api/shipments/'.$s.'/identity-lines',['business_line_ref'=>$business,'item_id'=>$item,'qty_kg'=>5,'unit_price_per_kg'=>10]);self::assertSame(201,$response->getStatusCode(),$response->getContent());return $response->json();
    }
    private function rejects(callable $fn,string $fragment): void
    {
        try{$fn();self::fail('Expected identity constraint rejection');}catch(\Illuminate\Database\QueryException|\RuntimeException $e){self::assertStringContainsString($fragment,$e->getMessage());}
    }
    public function test_create_identical_lines_copy_and_edit_preserve_their_business_identity(): void
    {
        $s=$this->shipment();$a=$this->create($s);$b=$this->create($s,'SUP-100/LINE-B');
        self::assertNotSame($a['allocation_line_ref'],$b['allocation_line_ref']);
        $this->patchJson("/api/shipments/$s/identity-lines/{$a['id']}",['sorting_order'=>20,'qty_kg'=>6,'unit_price_per_kg'=>12])->assertOk();
        $this->patchJson("/api/shipments/$s/identity-lines/{$b['id']}",['sorting_order'=>1])->assertOk();
        $rows=app(\App\Services\ShipmentService::class)->details(1,$s,1)['items'];
        self::assertSame($a['allocation_line_ref'],$rows->firstWhere('id',$a['id'])->allocation_line_ref);
        self::assertSame($a['id'],(int)$rows->firstWhere('id',$a['id'])->id);self::assertEquals(72,$rows->firstWhere('id',$a['id'])->base_cost);
        // Copy economic values as a NEW business line: explicit new source reference, not old ref.
        $copy=$this->create($s,'SUP-100/COPY-A');self::assertNotSame($copy['allocation_line_ref'],$a['allocation_line_ref']);
        DB::table('shipments')->where('id',$s)->update(['commercial_status'=>'READY']);app(\App\Services\ShipmentService::class)->reopen(1,$s,1);
        self::assertSame($a['allocation_line_ref'],DB::table('shipment_items')->where('id',$a['id'])->value('allocation_line_ref'));
    }
    public function test_api_cannot_replace_ref_or_forge_scope_and_duplicate_is_atomic(): void
    {
        $s=$this->shipment();$a=$this->create($s);$path="/api/shipments/$s/identity-lines/{$a['id']}";
        $this->patchJson($path,['allocation_line_ref'=>'BL:FORGED'])->assertStatus(422);
        $this->patchJson($path,['business_line_ref'=>'FORGED'])->assertStatus(422);
        $this->postJson("/api/shipments/$s/identity-lines",['business_line_ref'=>'SUP-100/LINE-A','item_id'=>1,'qty_kg'=>5,'unit_price_per_kg'=>10])->assertStatus(422);
        // Existing tenant middleware overwrites forged company input with authenticated context.
        $this->postJson("/api/shipments/$s/identity-lines",['business_line_ref'=>'SUP-100/LINE-C','item_id'=>1,'qty_kg'=>5,'unit_price_per_kg'=>10,'company_id'=>2])->assertCreated()->assertJsonPath('company_id',1);
        self::assertSame(2,DB::table('shipment_items')->where('shipment_id',$s)->count());self::assertSame($a['allocation_line_ref'],DB::table('shipment_items')->where('id',$a['id'])->value('allocation_line_ref'));self::assertSame(0,DB::transactionLevel());
    }
    public function test_database_enforces_new_refs_unique_scope_and_immutable_evidence(): void
    {
        $s=$this->shipment();$a=$this->create($s);
        foreach([null,'',' ','BL:lower','BL:A ',"BL:A\n","BL:A\t"] as $ref)$this->rejects(fn()=>DB::table('shipment_items')->insert(['company_id'=>1,'shipment_id'=>$s,'item_id'=>1,'allocation_line_ref'=>$ref]),'SLI_NEW_REFERENCE_REQUIRED');
        $this->rejects(fn()=>DB::table('shipment_items')->insert(['company_id'=>1,'shipment_id'=>$s,'item_id'=>1,'allocation_line_ref'=>$a['allocation_line_ref']]),'Duplicate entry');
        $this->rejects(fn()=>DB::table('shipment_items')->where('id',$a['id'])->update(['allocation_line_ref'=>'BL:OTHER']),'SLI_IMMUTABLE_REFERENCE');
        $other=$this->shipment();$b=$this->create($other);self::assertSame($a['allocation_line_ref'],$b['allocation_line_ref']);
        $this->rejects(fn()=>DB::table('shipment_items')->where('id',$a['id'])->update(['shipment_id'=>$other]),'SLI_IMMUTABLE_SCOPE');
        $this->rejects(fn()=>DB::table('shipment_items')->insert(['company_id'=>2,'shipment_id'=>$s,'item_id'=>3,'allocation_line_ref'=>'BL:OTHER']),'SLI_COMPANY_MISMATCH');
    }
    public function test_historical_null_is_readable_unchanged_and_cannot_be_backfilled(): void
    {
        $line=$this->legacy['line'];$r=DB::table('shipment_items')->where('id',$line)->first();self::assertNull($r->allocation_line_ref);
        $withoutRef=(array)$r;unset($withoutRef['allocation_line_ref']);self::assertSame($this->legacyRow,$withoutRef);self::assertSame($this->financialBefore,$this->finance());
        self::assertEquals(120,DB::table('inventory_lots')->sum('total_cost'));self::assertSame(1,DB::table('vouchers')->count());
        self::assertNotNull(app(\App\Services\ShipmentService::class)->details(1,$this->legacy['shipment'],1));
        $this->rejects(fn()=>DB::table('shipment_items')->where('id',$line)->update(['allocation_line_ref'=>'BL:GUESS']),'SLI_IMMUTABLE_REFERENCE');
        self::assertSame((array)$r,(array)DB::table('shipment_items')->where('id',$line)->first());
    }
    public function test_actual_purchase_links_lot_and_gl_to_same_identity_without_rounding_changes(): void
    {
        $s=$this->shipment();$a=$this->create($s);DB::table('shipments')->where('id',$s)->update(['commercial_status'=>'READY']);
        $p=\Tests\Support\UomOperationalLockFixture::purchase(1,1);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->update(['shipment_id'=>$s,'shipment_item_id'=>$a['id'],'qty_kg'=>5,'quantity'=>5,'total_before_vat'=>50,'total_after_vat'=>50,'line_total'=>50,'base_total_before_vat'=>50,'base_total_after_vat'=>50]);
        DB::table('purchase_invoices')->where('id',$p)->update(['total_before_vat'=>50,'total_amount'=>50,'base_total_before_vat'=>50,'base_total_amount'=>50]);
        DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$p,'shipment_id'=>$s,'allocated_qty_kg'=>5,'allocated_amount'=>50]);
        app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',1,$p,1,1);
        $row=DB::table('shipment_items')->where('id',$a['id'])->first();$lot=DB::table('inventory_lots')->where('id',$row->inventory_lot_id)->first();
        self::assertSame($a['allocation_line_ref'],$row->allocation_line_ref);self::assertSame($a['id'],(int)$lot->shipment_item_id);
        $journal=DB::table('purchase_invoices')->where('id',$p)->value('journal_entry_id');self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->sum(DB::raw('debit-credit')));
        self::assertEquals(50,$lot->total_cost);self::assertEquals(5,$lot->qty_received_kg);
        $this->patchJson("/api/shipments/$s/identity-lines/{$a['id']}",['qty_kg'=>9])->assertStatus(422);
        $this->rejects(fn()=>DB::table('shipment_items')->where('id',$a['id'])->update(['allocation_line_ref'=>'BL:REPLACED']),'SLI_IMMUTABLE_REFERENCE');
        $this->rejects(fn()=>DB::table('shipment_items')->where('id',$a['id'])->delete(),'SLI_FINANCIAL_DELETE_FORBIDDEN');
        $migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_line_identity.php');$this->rejects(fn()=>$migration->down(),'SLI_ROLLBACK_BLOCKED_IDENTITIES_EXIST');
    }
    public function test_other_company_cannot_edit_existing_line_but_may_use_same_local_ref(): void
    {
        $s=$this->shipment();$a=$this->create($s);Sanctum::actingAs(\App\Models\User::findOrFail(2),['*']);
        $this->patchJson("/api/shipments/$s/identity-lines/{$a['id']}",['qty_kg'=>999])->assertNotFound();
        $other=$this->shipment(2);$b=$this->create($other,'SUP-100/LINE-A',3);self::assertSame($a['allocation_line_ref'],$b['allocation_line_ref']);self::assertEquals(5,DB::table('shipment_items')->where('id',$a['id'])->value('qty_kg'));
    }
    public function test_foreign_company_item_and_read_only_actor_do_not_create_identity_rows(): void
    {
        $s=$this->shipment();
        $this->postJson("/api/shipments/$s/identity-lines",['business_line_ref'=>'SUP-100/LINE-A','item_id'=>3,'qty_kg'=>5,'unit_price_per_kg'=>10])->assertNotFound();
        DB::table('roles')->insert(['id'=>100,'role_code'=>'READ_ONLY','role_name'=>'Read only']);DB::table('user_roles')->where('user_id',1)->update(['role_id'=>100]);
        $this->postJson("/api/shipments/$s/identity-lines",['business_line_ref'=>'SUP-100/LINE-A','item_id'=>1,'qty_kg'=>5,'unit_price_per_kg'=>10])->assertForbidden();
        self::assertSame(0,DB::table('shipment_items')->where('shipment_id',$s)->count());
    }
    public function test_unused_additive_schema_can_be_removed_without_touching_legacy_rows(): void
    {
        $before=DB::table('shipment_items')->where('id',$this->legacy['line'])->first();$migration=require base_path('../docs/erp-baseline/proposed-migrations/shipment_line_identity.php');$migration->down();
        $after=(array)DB::table('shipment_items')->where('id',$this->legacy['line'])->first();unset($before->allocation_line_ref);self::assertSame((array)$before,$after);
    }
    public function test_integrated_operational_writer_issues_technical_identity(): void
    {
        $headers=DB::table('shipments')->count();
        $id=app(\App\Services\ShipmentService::class)->save(['shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-14','supplier_id'=>1,'currency_code'=>'SAR','exchange_rate'=>1,'items'=>[['item_id'=>1,'gross_qty_kg'=>5,'accepted_qty_kg'=>5,'unit_price'=>10,'price_unit'=>'KG','vat_percent'=>0]]],1,1,1);
        self::assertSame($headers+1,DB::table('shipments')->count());
        self::assertMatchesRegularExpression('/^BL:[A-F0-9]{32}$/',DB::table('shipment_items')->where('shipment_id',$id)->value('allocation_line_ref'));
        self::assertSame(0,DB::transactionLevel());
    }
}
