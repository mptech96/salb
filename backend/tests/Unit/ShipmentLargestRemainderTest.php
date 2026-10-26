<?php
namespace Tests\Unit;
use App\Services\ShipmentLargestRemainder as LR;
final class ShipmentLargestRemainderTest extends \PHPUnit\Framework\TestCase
{
    private function rows():array{return ['route-Z'=>['basis'=>'1','item_code'=>'ITEM-A'],'route-A'=>['basis'=>'1','item_code'=>'ITEM-B']];}
    public function test_business_code_not_random_route_wins_equal_remainder():void{
        $lr=new LR;$r=$this->rows();$v=$lr->allocate('20.001',$r);self::assertSame('10.001',$v['route-Z']['amount']);self::assertSame('10.000',$v['route-A']['amount']);self::assertEquals($v,$lr->allocate('20.001',array_reverse($r,true)));
    }
    public function test_separate_original_costs_not_sum_before_allocation():void{
        $lr=new LR;$a=$lr->allocate('0.001',$this->rows());$pool=$lr->allocate('0.002',$this->rows());self::assertSame('0.001',$a['route-Z']['amount']);self::assertSame('0.001',$pool['route-Z']['amount']);self::assertSame('0.002',bcadd($a['route-Z']['amount'],$a['route-Z']['amount'],3));
    }
    public function test_identical_even_split_allowed_and_odd_boundary_rejected():void{
        $lr=new LR;$r=$this->rows();$r['route-A']['item_code']='ITEM-A';$v=$lr->allocate('20.000',$r);self::assertSame('10.000',$v['route-A']['amount']);$this->expectExceptionMessage('UNRESOLVED_ECONOMIC_TIE');$lr->allocate('20.001',$r);
    }
    public function test_different_shares_preserve_exact_rational_and_total():void{
        $lr=new LR;$r=$this->rows();$r['route-A']['basis']='2';$v=$lr->allocate('20.001',$r);self::assertSame('20.001',bcadd($v['route-A']['amount'],$v['route-Z']['amount'],3));self::assertSame('20001000000',$v['route-Z']['exact_numerator']);self::assertSame('3000000',$v['route-Z']['exact_denominator']);
    }
    public function test_precision_loss_is_rejected_even_beyond_eighteen_decimal_places():void{
        $this->expectExceptionMessage('ALLOCATION_PRECISION');(new LR)->allocate('0.0000000000000000001',$this->rows());
    }
    public function test_negative_new_cost_is_not_a_new_reversal_policy():void{
        $this->expectExceptionMessage('ALLOCATION_PRECISION');(new LR)->allocate('-1.000',$this->rows());
    }
    public function test_approved_larger_basis_breaks_same_item_remainder_tie_without_route_priority():void{
        $rows=['technical-Z'=>['basis'=>'1','item_code'=>'SAME'],'technical-A'=>['basis'=>'3','item_code'=>'SAME']];
        $lr=new LR;$result=$lr->allocate('0.002',$rows);
        self::assertSame('0.000',$result['technical-Z']['amount']);self::assertSame('0.002',$result['technical-A']['amount']);
        self::assertEquals($result,$lr->allocate('0.002',array_reverse($rows,true)));
        self::assertSame('0.002',bcadd($result['technical-Z']['amount'],$result['technical-A']['amount'],3));
    }
}
