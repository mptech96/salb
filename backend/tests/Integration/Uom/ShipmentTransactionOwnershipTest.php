<?php
namespace Tests\Integration\Uom;

use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Middleware\EnsureSupportAccess;
use App\Services\Accounting\AccountingContext;
use App\Services\EnterpriseInvoiceService;
use App\Services\SulbDocumentSequenceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShipmentPurchaseLockFixture as F;
use Tests\Support\UomOperationalLockFixture as Legacy;

/** Actual current service; deliberately no guard bypass or copied posting body. */
class ShipmentTransactionOwnershipTest extends ScopedShipmentLockBatchOneTest
{
    private function evidence(): array
    {
        return [DB::table('inventory_lots')->count(), DB::table('inventory_lot_movements')->count(),
            DB::table('journal_entries')->count(), DB::table('vouchers')->count(),
            DB::table('sulb_document_sequences')->orderBy('id')->get()->toJson()];
    }

    public function test_company_first_outer_owner_is_still_rejected_before_finance(): void
    {
        $s=F::purchase(); $before=$this->evidence();
        try {
            DB::transaction(function () use ($s) {
                app(SulbDocumentSequenceService::class)->lockVoucherCompany(1);
                $this->postInvoice($s['invoice']);
            });
            self::fail('Current contract does not support a trusted outer owner yet.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHIPMENT_POST_OUTERMOST_REQUIRED', $e->getMessage());
        }
        self::assertSame($before,$this->evidence());
        self::assertSame(0,DB::transactionLevel());
        $this->postInvoice($s['invoice']); $this->financial($s);
    }

    public function test_source_first_outer_owner_cannot_add_company_lock_late(): void
    {
        $s=F::purchase(); $before=$this->evidence(); $companyRequests=0;
        DB::connection()->beforeExecuting(function ($sql) use (&$companyRequests) {
            if(str_contains($sql,'from `companies`') && str_contains($sql,'for update')) $companyRequests++;
        });
        try {
            DB::transaction(function () use ($s) {
                DB::table('purchase_invoices')->where('id',$s['invoice'])->lockForUpdate()->first();
                $this->postInvoice($s['invoice']);
            });
            self::fail('Unsafe owner accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHIPMENT_POST_OUTERMOST_REQUIRED',$e->getMessage());
        }
        self::assertSame(0,$companyRequests); self::assertSame($before,$this->evidence());
    }

    public function test_plain_nested_post_is_atomic_with_its_real_outer_owner(): void
    {
        $id=Legacy::purchase(1,1); $before=$this->evidence();
        try {
            DB::transaction(function () use ($id) {
                $this->postInvoice($id);
                self::assertSame(1,DB::transactionLevel());
                self::assertSame('POSTED',DB::table('purchase_invoices')->where('id',$id)->value('document_status'));
                throw new \RuntimeException('OUTER_FAULT_AFTER_REAL_POST');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('OUTER_FAULT_AFTER_REAL_POST',$e->getMessage());
        }
        self::assertSame($before,$this->evidence());
        self::assertSame('DRAFT',DB::table('purchase_invoices')->where('id',$id)->value('document_status'));
        DB::transaction(fn()=>$this->postInvoice($id));
        self::assertSame(1,DB::table('journal_entries')->count());
        self::assertEquals(100,DB::table('inventory_lots')->value('total_cost'));
        $this->balanced();
    }

    public function test_real_support_middleware_and_controller_reject_linked_post(): void
    {
        $s=F::purchase(); $before=$this->evidence();
        $r=Request::create('/api/purchase-invoices/'.$s['invoice'].'/post','POST');
        $route=new Route('POST','api/purchase-invoices/{id}/post',[]);
        $r->setRouteResolver(fn()=>$route);
        $r->attributes->add(['is_support_mode'=>true,'tenant_company_id'=>1,
            'tenant_branch_id'=>1,'authenticated_user_id'=>1,'actual_role_code'=>'SUPER_ADMIN',
            'effective_role_code'=>'SUPER_ADMIN','support_session'=>(object)[
                'company_id'=>1,'support_session_id'=>'OWNERSHIP-PROOF','ticket_reference'=>'LOCAL-ONLY',
                'reason'=>'Isolated transaction ownership proof','access_level'=>'WRITE',
                'capabilities_json'=>json_encode(['POST:api/purchase-invoices/{id}/post'])]]);
        $entered=false;
        $response=app(EnsureSupportAccess::class)->handle($r,function ($r) use ($s,&$entered) {
            $entered=true; self::assertSame(1,DB::transactionLevel());
            return app(PurchaseInvoiceController::class)->post($r,$s['invoice'],app(AccountingContext::class),app(EnterpriseInvoiceService::class));
        });
        self::assertTrue($entered); self::assertSame(422,$response->getStatusCode());
        self::assertStringContainsString('SHIPMENT_POST_OUTERMOST_REQUIRED',$response->getContent());
        self::assertSame($before,$this->evidence()); self::assertSame(0,DB::transactionLevel());
        self::assertSame('FAILED',DB::table('audit_logs')->orderByDesc('id')->value('result'));
        // This is a real middleware/controller slice with supplied trusted context,
        // not a complete Sanctum/subscription HTTP acceptance claim.
    }

    public function test_company_query_performance_groups_on_current_real_services(): void
    {
        foreach (['plain','shipment','independent_cost','two_shipments'] as $group) {
            $times=[]; $operations=0;
            for ($i=0;$i<8;$i++) {
                $workers=[];
                if($group==='plain') $workers[]=$this->worker('purchase',Legacy::purchase(1,1));
                elseif($group==='independent_cost') {
                    $s=F::shipment(false,true,2); $workers[]=$this->worker('cost',$s['shipment']);
                } else {
                    $s=F::purchase(); $workers[]=$this->worker('purchase',$s['invoice']);
                    if($group==='two_shipments') {
                        $other=F::purchase(); $workers[]=$this->worker('purchase',$other['invoice']);
                    }
                }
                foreach($workers as $p) {
                    $p->wait(); self::assertSame(0,$p->getExitCode(),$p->getOutput()); $operations++;
                    preg_match_all('/COMPANY_LOCK_MS ([0-9.]+)/',$p->getOutput(),$m);
                    foreach($m[1] as $time) $times[]=(float)$time;
                }
            }
            sort($times);
            $pct=fn($p)=>$times ? $times[max(0,(int)ceil(count($times)*$p)-1)] : null;
            fwrite(STDOUT,'OWNERSHIP_PERFORMANCE '.json_encode(['group'=>$group,'operations'=>$operations,
                'company_queries'=>count($times),'p50_ms'=>$pct(.5),'p95_ms'=>$pct(.95),
                'measurement'=>'successful query elapsed; not pure lock wait'])."\n");
            if($group==='plain') self::assertSame([],$times); else self::assertNotEmpty($times);
        }
        $this->balanced();
    }
}
