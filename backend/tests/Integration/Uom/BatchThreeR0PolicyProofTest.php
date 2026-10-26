<?php
namespace Tests\Integration\Uom;

/** Pure design proof ONLY. Not bound into an operational service. Integers are milli-units. */
final class BatchThreeR0PolicyProofTest extends \PHPUnit\Framework\TestCase
{
    private function allocate(string $units,array $rows): array
    {
        if(!preg_match('/^[0-9]+$/D',$units))throw new \RuntimeException('NEGATIVE_OR_NONCANONICAL_NEW_COST');
        $total='0';foreach($rows as $r){if(!preg_match('/^[0-9]+$/D',$r['basis']))throw new \RuntimeException('INVALID_BASIS');$total=bcadd($total,$r['basis'],0);}
        if(bccomp($total,'0',0)<=0)throw new \RuntimeException('ZERO_BASIS');
        $out=[];$remainders=[];$used='0';
        foreach($rows as $route=>$r){$n=bcmul($units,$r['basis'],0);$out[$route]=bcdiv($n,$total,0);$remainders[$route]=bcmod($n,$total,0);$used=bcadd($used,$out[$route],0);}
        $keys=array_keys($rows);
        usort($keys,fn($a,$b)=>-bccomp($remainders[$a],$remainders[$b],0)?:strcmp($rows[$a]['economic_key'],$rows[$b]['economic_key']));
        $left=(int)bcsub($units,$used,0); // Number of recipients bounds residual count, not monetary magnitude.
        if($left>0 && $left<count($keys)){
            $a=$keys[$left-1];$b=$keys[$left];
            if(bccomp($remainders[$a],$remainders[$b],0)===0 && $rows[$a]['economic_key']===$rows[$b]['economic_key'])throw new \RuntimeException('UNRESOLVED_ECONOMIC_TIE');
        }
        for($i=0;$i<$left;$i++)$out[$keys[$i]]=bcadd($out[$keys[$i]],'1',0);
        ksort($out,SORT_STRING);return $out;
    }
    private function sum(array $v): string {return array_reduce($v,fn($s,$x)=>bcadd($s,$x,0),'0');}

    public function test_a_different_shares_have_exact_largest_remainder_and_permutation_invariance(): void
    {
        $r=['lot-a'=>['basis'=>'1','economic_key'=>'ITEM-A'],'lot-b'=>['basis'=>'2','economic_key'=>'ITEM-B'],'lot-c'=>['basis'=>'4','economic_key'=>'ITEM-C']];
        $v=$this->allocate('20001',$r);self::assertSame(['lot-a'=>'2857','lot-b'=>'5715','lot-c'=>'11429'],$v);self::assertSame('20001',$this->sum($v));self::assertSame($v,$this->allocate('20001',array_reverse($r,true)));
    }
    public function test_b_equal_distinct_items_require_explicit_proposed_business_code_convention(): void
    {
        $r=['random-ref-Z'=>['basis'=>'1','economic_key'=>'ST-A'],'random-ref-A'=>['basis'=>'1','economic_key'=>'ST-B']];
        $v=$this->allocate('20001',$r);self::assertSame('10001',$v['random-ref-Z']);self::assertSame('10000',$v['random-ref-A']);self::assertSame($v,$this->allocate('20001',array_reverse($r,true)));
        // Routing ref lexical order has no influence; actual economic-code priority needs owner approval.
    }
    public function test_c_identical_lots_even_split_is_possible_but_odd_quantum_is_ambiguous(): void
    {
        $r=['ref-A'=>['basis'=>'1','economic_key'=>'SAME'],'ref-B'=>['basis'=>'1','economic_key'=>'SAME']];
        self::assertSame(['ref-A'=>'10000','ref-B'=>'10000'],$this->allocate('20000',$r));
        foreach([$r,array_reverse($r,true)] as $permutation){try{$this->allocate('20001',$permutation);self::fail('Cannot choose an indistinguishable lot');}catch(\RuntimeException $e){self::assertSame('UNRESOLVED_ECONOMIC_TIE',$e->getMessage());}}
        self::assertSame('1',bcmod('20001','2',0));
    }
    public function test_per_cost_not_pooled_and_gl_is_projection_of_the_same_lot_result(): void
    {
        $rows=['A'=>['basis'=>'1','economic_key'=>'ITEM-A'],'B'=>['basis'=>'1','economic_key'=>'ITEM-B']];
        $run=function($rs,$costs){$tot=['A'=>'0','B'=>'0'];foreach($costs as $amount){$shares=$this->allocate($amount,$rs);self::assertSame($amount,$this->sum($shares));foreach($shares as $k=>$v)$tot[$k]=bcadd($tot[$k],$v,0);}return $tot;};
        $v=$run($rows,['1','1']);self::assertSame(['A'=>'2','B'=>'0'],$v);self::assertNotSame($v,$this->allocate('2',$rows));self::assertSame($v,$run(array_reverse($rows,true),array_reverse(['1','1'])));
        $gl=['inventory-shared'=>$this->sum($v)];self::assertSame('2',$gl['inventory-shared']);self::assertSame($gl['inventory-shared'],$this->sum($v));
        $first=$run($rows,['20001','7123']);self::assertSame($first,$run(array_reverse($rows,true),['7123','20001']));self::assertSame('27124',$this->sum($first));
        // Reversal uses captured original shares, not current settings or a new tie decision.
        $reverse=array_map(fn($x)=>bcsub('0',$x,0),$v);foreach($v as $k=>$x)self::assertSame('0',bcadd($x,$reverse[$k],0));self::assertSame('-2',$this->sum($reverse));
    }
    public function test_changed_settings_do_not_recompute_reversal_and_new_negative_cost_is_rejected(): void
    {
        $v=$this->allocate('20001',['A'=>['basis'=>'1','economic_key'=>'A'],'B'=>['basis'=>'2','economic_key'=>'B']]);
        $reversal=array_map(fn($x)=>bcsub('0',$x,0),$v);self::assertSame('0',bcadd($this->sum($v),$this->sum($reversal),0));
        $this->expectExceptionMessage('NEGATIVE_OR_NONCANONICAL_NEW_COST');$this->allocate('-1',['A'=>['basis'=>'1','economic_key'=>'A']]);
    }
    public function test_group_level_residual_is_not_a_complete_lot_reconciliation(): void
    {
        $gl='20001';$lots=['A'=>'10000','B'=>'10000'];self::assertSame('1',bcsub($gl,$this->sum($lots),0));
        // Parking a milli-unit elsewhere changes the valuation model; no operational pool exists in this proof.
        self::assertNotSame($gl,$this->sum($lots));self::assertSame('10.0005',bcdiv('20.001','2',4));
    }
    public function test_tiny_cost_and_large_integer_amounts_do_not_use_floating_point(): void
    {
        $r=['lot-A'=>['basis'=>'1','economic_key'=>'A'],'lot-B'=>['basis'=>'1','economic_key'=>'B'],'lot-C'=>['basis'=>'1','economic_key'=>'C']];
        self::assertSame(['lot-A'=>'1','lot-B'=>'0','lot-C'=>'0'],$this->allocate('1',$r));
        $u='123456789012345678901';self::assertSame($u,$this->sum($this->allocate($u,$r)));
    }
}
