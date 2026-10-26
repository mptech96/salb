<?php
namespace App\Services\Uom;

final class DecimalQuantity
{
    public static function decimal(string $value,int $integerDigits=12,bool $zero=false):string
    {
        if(!preg_match('/^\d+(?:\.\d{1,6})?$/D',$value)||strlen(ltrim(explode('.',$value)[0],'0'))>$integerDigits||(!$zero&&bccomp($value,'0',6)<=0))throw new \InvalidArgumentException('UOM_DECIMAL_INVALID_OR_OVERFLOW');
        return bcadd($value,'0',6);
    }
    public static function convert(string $quantity,string $factor,bool $fractional):string
    {
        $exact=bcmul(self::decimal($quantity),self::decimal($factor),12);$base=bcadd($exact,'0',6);
        if(bccomp($exact,$base,12)!==0)throw new \InvalidArgumentException('UOM_INEXACT_CONVERSION');
        self::decimal($base,18);
        if(!$fractional&&bccomp($base,bcadd($base,'0',0),6)!==0)throw new \InvalidArgumentException('UOM_INDIVISIBLE_BASE');
        return $base;
    }
}
