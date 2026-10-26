<?php
namespace App\Services;

/** Exact per-cost milli-unit allocator. Routing IDs NEVER rank a remainder tie. */
final class ShipmentLargestRemainder
{
    public function decimal(string $value,int $scale=3): string
    {
        if(!preg_match('/^\d+(?:\.\d+)?$/D',$value)||bccomp($value,bcadd($value,'0',$scale),max($scale,strlen(explode('.',$value,2)[1]??'')))!==0)throw new \RuntimeException('ALLOCATION_PRECISION');
        return bcadd($value,'0',$scale);
    }
    public function allocate(string $amount,array $rows): array
    {
        $amount=$this->decimal($amount);$units=bcmul($amount,'1000',0);$total='0';
        foreach($rows as $r){$this->decimal($r['basis'],6);if(!is_string($r['item_code'])||trim($r['item_code'])==='')throw new \RuntimeException('ALLOCATION_BUSINESS_CODE_REQUIRED');$total=bcadd($total,$r['basis'],6);}
        if(bccomp($total,'0',6)<=0)throw new \RuntimeException('ALLOCATION_ZERO_BASIS');
        $den=bcmul($total,'1000000',0);$out=[];$remainders=[];$used='0';
        foreach($rows as $key=>$r){
            $n=bcmul($units,bcmul($r['basis'],'1000000',0),0);
            $floor=bcdiv($n,$den,0);$remainders[$key]=bcmod($n,$den,0);$used=bcadd($used,$floor,0);
            $out[$key]=['units'=>$floor,'exact_numerator'=>$n,'exact_denominator'=>$den];
        }
        $keys=array_keys($rows);
        usort($keys,fn($a,$b)=>-bccomp($remainders[$a],$remainders[$b],0)
            ?:strcmp($rows[$a]['item_code'],$rows[$b]['item_code'])
            ?:-bccomp($rows[$a]['basis'],$rows[$b]['basis'],6));
        $left=(int)bcsub($units,$used,0); // Bounded by recipient count, NOT monetary amount.
        if($left>0&&$left<count($keys)){
            $a=$keys[$left-1];$b=$keys[$left];
            if(bccomp($remainders[$a],$remainders[$b],0)===0&&$rows[$a]['item_code']===$rows[$b]['item_code']
                &&bccomp($rows[$a]['basis'],$rows[$b]['basis'],6)===0)throw new \RuntimeException('UNRESOLVED_ECONOMIC_TIE');
        }
        for($i=0;$i<$left;$i++)$out[$keys[$i]]['units']=bcadd($out[$keys[$i]]['units'],'1',0);
        $sum='0';foreach($out as &$v){$v['amount']=bcdiv($v['units'],'1000',3);$v['rounding_delta']=bcsub($v['amount'],bcdiv($v['exact_numerator'],bcmul($den,'1000',0),18),18);$sum=bcadd($sum,$v['amount'],3);}unset($v);
        if(bccomp($sum,$amount,3)!==0)throw new \RuntimeException('ALLOCATION_SUM_MISMATCH');
        return $out;
    }
}
