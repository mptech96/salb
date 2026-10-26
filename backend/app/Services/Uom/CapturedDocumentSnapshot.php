<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;

/** Server-loaded approved source only; never constructed from request snapshot fields. */
final class CapturedDocumentSnapshot
{
    private function __construct(private int $company,private int $branch,private bool $sale,private $lines){}

    public static function load(int $company,int $branch,string $type,int $id):self
    {
        $type=strtoupper($type);if(!in_array($type,['QUOTATION','PURCHASE_ORDER'],true))throw new \RuntimeException('UOM_CAPTURE_TYPE');
        if(DB::transactionLevel()<1)throw new \RuntimeException('UOM_CAPTURE_ROOT_REQUIRED');
        $sale=$type==='QUOTATION';$table=$sale?'sales_quotations':'purchase_orders';$fk=$sale?'sales_quotation_id':'purchase_order_id';
        $head=DB::table($table)->where('company_id',$company)->where('branch_id',$branch)->where('id',$id)->lockForUpdate()->first();
        if(!$head||$head->converted_invoice_id||!in_array($head->status,$sale?['ACCEPTED']:['APPROVED','SENT'],true))throw new \RuntimeException('UOM_CAPTURE_SOURCE_REQUIRED');
        $lines=DB::table($sale?'sales_quotation_lines':'purchase_order_lines')->where('company_id',$company)->where($fk,$id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        return new self($company,$branch,$sale,$lines);
    }

    public function prepare(int $company,int $branch,object $item,array $input,bool $sale):array
    {
        $line=$this->lines->get($input['_captured_line_id']??0);
        if($company!==$this->company||$branch!==$this->branch||$sale!==$this->sale||!$line||(int)$line->item_id!==(int)$item->id||!OperationalDocument::isBase($line))throw new \RuntimeException('UOM_CAPTURE_SCOPE');
        return OperationalDocument::prepareSnapshot($company,$item,$line);
    }
}
