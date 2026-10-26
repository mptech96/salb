<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
use App\Services\ShipmentLargestRemainder;
use App\Services\Accounting\ItemAccountingResolver;

/** One exact, captured allocation is shared by purchase LOTs and the invoice GL. */
final class BaseInvoiceHeaderCost
{
    private static function amount(object $invoice):string
    {return bcadd(bcmul(bcadd((string)($invoice->transport_cost??'0'),(string)($invoice->extra_cost??'0'),3),(string)($invoice->exchange_rate?:'1'),13),'0.0005',3);}
    public static function prepare(int $company,object $invoice,$lines):array
    {
        OperationalDocument::ready();$amount=self::amount($invoice);if(bccomp($amount,'0',3)===0)return [];
        if((int)$invoice->company_id!==$company||!DB::getSchemaBuilder()->hasTable('uom_invoice_header_cost_receipts'))throw new \RuntimeException('UOM_HEADER_COST_SCHEMA_OR_SCOPE');
        $rows=[];$captured=[];$math=new ShipmentLargestRemainder;
        foreach($lines as $line){if((int)$line->company_id!==$company||(int)$line->purchase_invoice_id!==(int)$invoice->id)throw new \RuntimeException('UOM_HEADER_COST_SOURCE_SCOPE');
            $item=DB::table('items')->where('company_id',$company)->where('id',$line->item_id)->first();if(!$item)throw new \RuntimeException('UOM_HEADER_COST_SOURCE_SCOPE');
            $rows[$line->id]=['basis'=>$math->decimal((string)$line->base_total_before_vat,6),'item_code'=>$item->item_code];$captured[$line->id]=['line_id'=>(int)$line->id,'item_id'=>(int)$line->item_id,'item_code'=>$item->item_code,'account_id'=>app(ItemAccountingResolver::class)->inventory($company,(int)$line->item_id),'original_base'=>$math->decimal((string)$line->base_total_before_vat)];
        }
        $allocation=$math->allocate($amount,$rows);$shares=[];foreach($allocation as $id=>$row){$captured[$id]+=$row;$captured[$id]['expected_lot_value']=bcadd($captured[$id]['original_base'],$row['amount'],3);$shares[$id]=$row['amount'];}
        $payload=json_encode(['policy'=>'LR_MILLI_V1','currency'=>$invoice->currency_code,'rate'=>(string)$invoice->exchange_rate,'transport'=>(string)$invoice->transport_cost,'extra'=>(string)$invoice->extra_cost,'recipients'=>$captured],JSON_THROW_ON_ERROR);$hash=hash('sha256',$payload);
        $old=DB::table('uom_invoice_header_cost_receipts')->where('company_id',$company)->where('invoice_id',$invoice->id)->first();if($old){if(!hash_equals($old->payload_hash,$hash))throw new \RuntimeException('UOM_HEADER_COST_CONFLICT');return $shares;}
        DB::table('uom_invoice_header_cost_receipts')->insert(['company_id'=>$company,'invoice_id'=>$invoice->id,'amount'=>$amount,'payload'=>$payload,'payload_hash'=>$hash,'created_at'=>now()]);return $shares;
    }
    private static function receipt(int $company,object $invoice):object
    {
        $row=DB::table('uom_invoice_header_cost_receipts')->where('company_id',$company)->where('invoice_id',$invoice->id)->first();
        if(!$row||!hash_equals($row->payload_hash,hash('sha256',$row->payload))||bccomp($row->amount,self::amount($invoice),3)!==0)throw new \RuntimeException('UOM_HEADER_COST_RECEIPT_REQUIRED');return $row;
    }
    public static function accountShares(int $company,object $invoice):array
    {
        $receipt=self::receipt($company,$invoice);$p=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR);$accounts=[];$sum='0';
        foreach($p['recipients'] as $row){$sum=bcadd($sum,$row['amount'],3);$accounts[$row['account_id']]=bcadd($accounts[$row['account_id']]??'0',$row['amount'],3);}
        if(bccomp($sum,$receipt->amount,3))throw new \RuntimeException('UOM_HEADER_COST_SUM');return $accounts;
    }
    public static function markPosted(int $company,object $invoice,int $journal):void
    {
        if(bccomp(self::amount($invoice),'0',3)===0)return;$row=self::receipt($company,$invoice);if($row->journal_entry_id){if((int)$row->journal_entry_id!==$journal)throw new \RuntimeException('UOM_HEADER_COST_JOURNAL_CHANGED');return;}
        $p=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);$expected=[];
        foreach($p['recipients'] as $recipient){$lots=DB::table('inventory_lots')->where('company_id',$company)->where('purchase_invoice_id',$invoice->id)->where('purchase_invoice_line_id',$recipient['line_id'])->get();if($lots->count()!==1||bccomp($lots[0]->base_cost,$recipient['expected_lot_value'],3))throw new \RuntimeException('UOM_HEADER_COST_LOT_VALUE');$account=$recipient['account_id'];$expected[$account]=bcadd($expected[$account]??'0',$recipient['expected_lot_value'],3);}
        foreach($expected as $account=>$value){$actual=DB::table('journal_entry_lines')->where('company_id',$company)->where('journal_entry_id',$journal)->where('account_id',$account)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v;if(bccomp($actual,$value,3))throw new \RuntimeException('UOM_HEADER_COST_GL_VALUE');}
        DB::table('uom_invoice_header_cost_receipts')->where('company_id',$company)->where('id',$row->id)->update(['journal_entry_id'=>$journal]);
    }
    public static function auditLot(int $company,object $invoice,object $line,object $lot):void
    {
        $exists=DB::getSchemaBuilder()->hasTable('uom_invoice_header_cost_receipts')&&DB::table('uom_invoice_header_cost_receipts')->where('company_id',$company)->where('invoice_id',$invoice->id)->exists();
        if(!$exists){if(bccomp($lot->base_cost,(string)$line->base_total_before_vat,3)!==0)throw new \RuntimeException('UOM_HEADER_COST_HISTORY_REVIEW_REQUIRED');return;}
        $row=self::receipt($company,$invoice);if((int)$row->journal_entry_id!==(int)$invoice->journal_entry_id)throw new \RuntimeException('UOM_HEADER_COST_HISTORY_REVIEW_REQUIRED');
        $p=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);$recipient=collect($p['recipients'])->first(fn($r)=>(int)$r['line_id']===(int)$line->id);
        if(!$recipient||(int)$recipient['item_id']!==(int)$lot->item_id||bccomp($recipient['original_base'],$line->base_total_before_vat,3)||bccomp($recipient['expected_lot_value'],$lot->base_cost,3))throw new \RuntimeException('UOM_HEADER_COST_HISTORY_REVIEW_REQUIRED');
    }
}
