<?php
namespace App\Services\Accounting;

/** Exact 3-decimal ledger amounts. No silent truncation of an input payment. */
final class HistoricalDecimal
{
    public static function units(mixed $value): int
    {
        $text=(string)$value;
        if(!preg_match('/^(-?)([0-9]{1,15})(?:\.([0-9]{1,3}))?$/D',$text,$m))throw new \RuntimeException('Amount must be a finite decimal with at most 3 fractional digits.');
        return ($m[1]==='-'?-1:1)*((int)$m[2]*1000+(int)str_pad($m[3]??'',3,'0'));
    }
    public static function format(int $units): string
    {
        return ($units<0?'-':'').intdiv(abs($units),1000).'.'.str_pad((string)(abs($units)%1000),3,'0',STR_PAD_LEFT);
    }
    public static function proportion(mixed $amount,mixed $quantity,mixed $original): string
    {
        if(!extension_loaded('bcmath'))throw new \RuntimeException('BCMath required for isolated historical precision.');
        if(bccomp((string)$original,'0',6)<=0||bccomp((string)$quantity,'0',6)<=0)throw new \RuntimeException('Positive historical quantity required.');
        $raw=bcdiv(bcmul((string)$amount,(string)$quantity,12),(string)$original,12);
        return bcadd($raw,'0.0005',3);
    }
}
