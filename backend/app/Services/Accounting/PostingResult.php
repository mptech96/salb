<?php
namespace App\Services\Accounting;
class PostingResult
{
    public function __construct(public bool $success,public string $message,public ?int $journalEntryId=null,public ?int $voucherId=null){}
    public static function success(string $m,?int $j=null,?int $v=null):self{return new self(true,$m,$j,$v);}
    public static function error(string $m):self{return new self(false,$m);}
}
