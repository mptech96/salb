<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Tests\Support\UomDesignDecimalProof as Q;

class UomDesignProofTest extends TestCase
{
    protected function setUp():void{require_once __DIR__.'/../Support/UomDesignDecimalProof.php';self::assertTrue(extension_loaded('bcmath'),'BCMath proof dependency required, not skipped');}
    public function test_cartons_have_item_specific_factors_and_stock_values():void
    {
        $a=Q::quantity('3','24',false);$b=Q::quantity('3','12',false);self::assertSame('72.000000',$a);self::assertSame('36.000000',$b);
        self::assertSame('12.000000',Q::quantity('0.5','24',false));
        $remaining=bcsub($a,'5',6);self::assertSame('67.000000',$remaining);$remaining=bcadd($remaining,'2',6);self::assertSame('69.000000',$remaining);
        $source=bcsub($remaining,'10',6);$destination='10.000000';self::assertSame('69.000000',bcadd($source,$destination,6));
        self::assertSame('690.000',bcmul(bcadd($source,$destination,6),'10',3));self::assertSame('10.000',bcmul(bcsub($source,'58',6),'10',3));
    }
    public function test_mass_volume_length_and_snapshot_price_are_exact():void
    {
        self::assertSame('1000.000000',Q::quantity('1','1000'));self::assertSame('0.250000',Q::quantity('250','0.001'));self::assertSame('12.500000',Q::quantity('12.5','1'));
        $snapshot=['quantity'=>'3','factor'=>'24','price'=>'240'];$currentPrice='264';self::assertSame('720.000',bcmul($snapshot['quantity'],$snapshot['price'],3));self::assertSame('792.000',bcmul('3',$currentPrice,3));self::assertSame('72.000000',Q::quantity($snapshot['quantity'],$snapshot['factor'],false));
    }
    public function test_fifo_cost_pool_and_historical_kg_adapter_do_not_double_count():void
    {
        // Planned cost-pool conservation, not execution of ERP FIFO or GL.
        $received='72.000000';$value='720.000';$soldCost='50.000';$returnCost='20.000';$balanceValue=bcadd(bcsub($value,$soldCost,3),$returnCost,3);
        self::assertSame('690.000',$balanceValue);self::assertSame('69.000000',bcadd(bcsub($received,'5',6),'2',6));
        $legacy=['version'=>'LEGACY_KG','qty_kg'=>'125.500','qty_base'=>null];$generic=['version'=>'BASE_V1','qty_kg'=>'0.000','qty_base'=>'72.000000'];
        $read=fn($r)=>$r['version']==='LEGACY_KG'?bcadd($r['qty_kg'],'0',6):$r['qty_base'];self::assertSame('125.500000',$read($legacy));self::assertSame('72.000000',$read($generic));
    }
    public function test_precision_bad_factors_and_dimension_changes_fail_closed():void
    {
        foreach([fn()=>Q::quantity('1','0'),fn()=>Q::quantity('-1','24'),fn()=>Q::quantity('0.000001','0.001'),fn()=>Q::quantity('0.1','1',false),fn()=>Q::quantity('1000000000000','1'),fn()=>Q::sameDimension('LENGTH','MASS')] as $call){try{$call();self::fail('Invalid conversion accepted');}catch(\InvalidArgumentException $e){self::assertNotEmpty($e->getMessage());}}
    }
}
