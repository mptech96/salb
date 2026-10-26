<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
/** Proposed coordinator only. Not a binding, patch or guarantee against unwrapped producers. */
final class UomPostingCoordinatorProof
{
 public static function post(string $kind,int $company,int $id):mixed{
  UomStageAProof::guard();
  return DB::transaction(function()use($kind,$company,$id){
   $purchase=$kind==='purchase';$header=$purchase?'purchase_invoices':'opening_balance_batches';$lines=$purchase?'purchase_invoice_lines':'opening_inventory_lines';$fk=$purchase?'purchase_invoice_id':'batch_id';
   if(!DB::table($header)->where('company_id',$company)->where('id',$id)->lockForUpdate()->first())throw new \RuntimeException('SOURCE_SCOPE');
   $items=DB::table($lines)->where('company_id',$company)->where($fk,$id)->orderBy('item_id')->distinct()->pluck('item_id');
   foreach($items as $item)if(!DB::table('items')->where('company_id',$company)->where('id',$item)->lockForUpdate()->first())throw new \RuntimeException('ITEM_SCOPE');
   return $purchase?app(\App\Services\EnterpriseInvoiceService::class)->post('PURCHASE',$company,$id,$company,$company):app(\App\Services\OpeningBalanceService::class)->post($company,$id,$company,true,$company);
  }); // Success is returned only after the outer commit.
 }
}
