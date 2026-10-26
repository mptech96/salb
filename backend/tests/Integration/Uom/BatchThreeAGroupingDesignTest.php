<?php
namespace Tests\Integration\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\EnterpriseInvoiceService;
use Tests\Support\ShipmentPurchaseLockFixture as F;

/** Test-only grouping feasibility; no operational allocator or proposed schema applied. */
class BatchThreeAGroupingDesignTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach(['UomStageAProof','UomOperationalLockFixture','ShipmentPurchaseLockFixture'] as $name)require_once __DIR__.'/../../Support/'.$name.'.php';
        \Tests\Support\UomStageAProof::guard();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(DB::select('SHOW TABLES') as $row)DB::unprepared('DROP TABLE `'.array_values((array)$row)[0].'`');DB::statement('SET FOREIGN_KEY_CHECKS=1');F::install();
        $m=require base_path('../docs/erp-baseline/proposed-migrations/stock_movements_company_source_index.php');$m->up();
    }

    // Exact positive integer milli-unit proof, explicit business keys supplied ONLY by synthetic cases.
    private function lr(string $units,array $weights): array
    {
        $total='0';foreach($weights as $w)$total=bcadd($total,$w,0);
        if(bccomp($total,'0',0)<=0)throw new \RuntimeException('Invalid proof basis');
        $out=[];$fractions=[];$used='0';
        foreach($weights as $key=>$w){$product=bcmul($units,$w,0);$out[$key]=bcdiv($product,$total,0);$fractions[$key]=bcmod($product,$total,0);$used=bcadd($used,$out[$key],0);}
        $keys=array_keys($weights);usort($keys,fn($a,$b)=>-bccomp($fractions[$a],$fractions[$b],0)?:strcmp((string)$a,(string)$b));
        $left=bcsub($units,$used,0);foreach($keys as $key){if(bccomp($left,'0',0)<=0)break;$out[$key]=bcadd($out[$key],'1',0);$left=bcsub($left,'1',0);}
        if($left!=='0')throw new \RuntimeException('Proof remainder not conserved');ksort($out,SORT_STRING);return $out;
    }

    public function test_grouping_reconciles_a_pool_but_cannot_equally_split_an_odd_quantum_between_identical_lots(): void
    {
        $group=$this->lr('20001',['company1/branch1/item1'=>'100']);self::assertSame('20001',$group['company1/branch1/item1']);
        $floor=bcdiv($group['company1/branch1/item1'],'2',0);self::assertSame('10000',$floor);
        self::assertSame('1',bcsub($group['company1/branch1/item1'],bcmul($floor,'2',0),0));
        // Both labelled splits conserve the group and GL; without business identity neither label wins.
        self::assertSame('20001',bcadd('10000','10001',0));
        self::assertNotSame(['10000','10001'],['10001','10000']);
        fwrite(STDOUT,"GROUPING_ONLY_SOLVES_POOL_NOT_LOT_IDENTITY\n");
    }

    public function test_hierarchical_item_grouping_is_not_associative_with_line_largest_remainder(): void
    {
        $lines=$this->lr('2',['source-A/line1'=>'1','source-A/line2'=>'1','source-B/line1'=>'1']);
        $groups=$this->lr('2',['source-A'=>'2','source-B'=>'1']);
        self::assertSame('2',bcadd($lines['source-A/line1'],$lines['source-A/line2'],0));
        self::assertSame('1',$groups['source-A']);self::assertSame('1',$groups['source-B']);
        self::assertNotSame($groups['source-A'],bcadd($lines['source-A/line1'],$lines['source-A/line2'],0));
        fwrite(STDOUT,'GROUPING_CHANGES_BENEFICIARY_GRANULARITY '.json_encode(['line_units'=>$lines,'group_units'=>$groups])."\n");
    }

    public function test_explicit_synthetic_business_identity_makes_per_cost_lr_order_independent(): void
    {
        $weights=['supplier-line-A'=>'50000','supplier-line-B'=>'50000','supplier-line-C'=>'100000'];
        $costs=['original-cost-A'=>'20001','original-cost-B'=>'7123'];$run=function($ws,$cs){$result=array_fill_keys(array_keys($ws),'0');foreach($cs as $amount){$shares=$this->lr($amount,$ws);$sum='0';foreach($shares as $key=>$value){$sum=bcadd($sum,$value,0);$result[$key]=bcadd($result[$key],$value,0);}self::assertSame($amount,$sum);}ksort($result,SORT_STRING);return $result;};
        self::assertSame($run($weights,$costs),$run(array_reverse($weights,true),array_reverse($costs,true)));
        // These source references are proof inputs, NOT a claim current shipment schema stores them.
    }

    public static function duplicateCases(): array {return [['50.000'],['75.000']];}

    #[\PHPUnit\Framework\Attributes\DataProvider('duplicateCases')]
    public function test_actual_same_item_lines_remain_distinct_lots_and_balanced_finance(string $secondBase): void
    {
        $s=F::purchase();$original=(array)DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->first();unset($original['id']);
        DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$s['invoice'])->delete();DB::table('shipment_items')->where('shipment_id',$s['shipment'])->delete();
        foreach(['50.000',$secondBase] as $i=>$base){
            $row=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$s['shipment'],'item_id'=>1,'qty_kg'=>5,'accepted_qty_kg'=>5,'remaining_qty_kg'=>5,'base_cost'=>$base,'sorting_order'=>$i+1]);
            $line=$original;foreach(['total_before_vat','total_after_vat','line_total','base_total_before_vat','base_total_after_vat'] as $field)$line[$field]=$base;
            $line['shipment_item_id']=$row;$line['qty_kg']=5;$line['quantity']=5;$line['unit_price']=bcdiv($base,'5',3);DB::table('purchase_invoice_lines')->insert($line);
        }
        $total=bcadd('50.000',$secondBase,3);DB::table('purchase_invoices')->where('id',$s['invoice'])->update(['total_before_vat'=>$total,'total_amount'=>$total,'base_total_before_vat'=>$total,'base_total_amount'=>$total]);
        DB::table('shipment_costs')->where('id',$s['cost'])->update(['amount'=>'20.001','foreign_amount'=>'20.001']);
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);
        $lots=DB::table('inventory_lots')->orderBy('id')->get();self::assertCount(2,$lots);self::assertSame($lots[0]->item_id,$lots[1]->item_id);self::assertNotSame($lots[0]->id,$lots[1]->id);self::assertNotSame($lots[0]->purchase_invoice_line_id,$lots[1]->purchase_invoice_line_id);
        self::assertSame(bcadd($total,'20.001',3),bcadd((string)DB::table('inventory_lots')->sum('total_cost'),'0',3));
        foreach(DB::table('journal_entries')->pluck('id') as $j)self::assertEquals(0,DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit')));
        $costJ=DB::table('shipment_costs')->where('id',$s['cost'])->value('journal_entry_id');self::assertSame('20.001',bcadd((string)DB::table('journal_entry_lines')->where('journal_entry_id',$costJ)->where('account_id',11)->sum('debit'),'0',3));
        $before=serialize(DB::table('inventory_lots')->orderBy('id')->get());$count=DB::table('journal_entries')->count();
        app(EnterpriseInvoiceService::class)->post('PURCHASE',1,$s['invoice'],1,1);self::assertSame($count,DB::table('journal_entries')->count());
        DB::beginTransaction();try{$consumed=app(\App\Services\InventoryLotService::class)->consumeFifo(1,1,1,5,'GROUPING_PROOF',1,null,1);self::assertEquals($lots[0]->total_cost,$consumed['total_cost']);}finally{DB::rollBack();}
        self::assertSame($before,serialize(DB::table('inventory_lots')->orderBy('id')->get()));
        self::assertSame(0,DB::table('inventory_lots')->where('company_id',2)->count());
        fwrite(STDOUT,'REAL_DUPLICATE_ITEM_LOTS '.json_encode(['second_base'=>$secondBase,'lots'=>$lots->map(fn($l)=>['id'=>$l->id,'source_line'=>$l->purchase_invoice_line_id,'base'=>$l->base_cost,'allocated'=>$l->allocated_cost])->all(),'pool_gl'=>'20.001','fifo_rollback_exact'=>true])."\n");
    }
}
