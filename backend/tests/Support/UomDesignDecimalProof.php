<?php
namespace Tests\Support;

/** Design arithmetic only. No application binding, DB access, migration or posting service. */
final class UomDesignDecimalProof
{
    public static function quantity(string $entered,string $factor,bool $fractional=true):string
    {
        foreach([$entered,$factor] as $v)if(!preg_match('/^\d+(?:\.\d{1,6})?$/D',$v)||bccomp($v,'0',6)<=0)throw new \InvalidArgumentException('Positive decimal with max six places required');
        foreach([$entered,$factor] as $v)if(strlen(ltrim(explode('.',$v)[0],'0'))>12)throw new \InvalidArgumentException('Entered quantity/factor overflow for DECIMAL(18,6)');
        $exact=bcmul($entered,$factor,12);$base=bcadd($exact,'0',6);
        if(bccomp($base,$exact,12)!==0)throw new \InvalidArgumentException('Conversion not representable at base precision');
        if(strlen(ltrim(explode('.',$base)[0],'0'))>18)throw new \InvalidArgumentException('Base quantity overflow');
        if(!$fractional&&bccomp($base,bcadd($base,'0',0),6)!==0)throw new \InvalidArgumentException('Fractional count rejected');
        return $base;
    }
    public static function sameDimension(string $a,string $b):void
    {if($a!==$b)throw new \InvalidArgumentException('Cross dimension requires separate approved item policy');}
}
