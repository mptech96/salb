<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
/** Lock scope only; no posting, numbering, account or valuation rules. */
final class ShipmentPurchasePostingScope
{
 public static function snapshot(int $company,int $invoice,bool $locked=false):array{
  $q=DB::table('invoice_shipment_links')->where('company_id',$company)->where('invoice_type','PURCHASE')->where('invoice_id',$invoice)->orderBy('shipment_id')->orderBy('id');
  if($locked)$q->lockForUpdate();
  return $q->get()->map(fn($r)=>[
   'id'=>(int)$r->id,'branch'=>(int)$r->branch_id,'shipment'=>(int)$r->shipment_id,
   'qty'=>(string)$r->allocated_qty_kg,'amount'=>(string)$r->allocated_amount,
  ])->all();
 }
 public static function assertOuterEntry(array $scope):void{
  if($scope&&DB::connection()->transactionLevel()!==0)throw new \RuntimeException('SHIPMENT_POST_OUTERMOST_REQUIRED: يجب بدء ترحيل مشتريات الشحنة من منسق المعاملة الخارجية قبل أي أقفال أخرى.');
 }
 public static function reserve(int $company,array $scope):void{
  if($scope)app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
 }
 public static function verify(int $company,int $invoice,int $branch,array $scope):void{
  // Normal unlinked entry has no earlier consistent read inside this transaction;
  // source-header ownership protects sanctioned draft-link writers without a gap lock.
  // At the outer entry this is the first consistent read, after the locked
  // header. Avoid next-key link locks that could invert with draft S->link writes.
  $current=self::snapshot($company,$invoice,DB::connection()->transactionLevel()>1);
  if($current!==$scope)throw new \RuntimeException('SHIPMENT_POST_SCOPE_CHANGED: تغير ارتباط الشحنة؛ أُلغيت المحاولة بالكامل. أعد طلب الترحيل.');
  foreach($current as $link)if($link['branch']!==$branch)throw new \RuntimeException('ارتباط الشحنة خارج فرع الفاتورة.');
 }
 public static function assertReady(int $company,int $branch,int $supplier,array $scope):void{
  if(!$scope)return;
  $ids=array_values(array_unique(array_column($scope,'shipment')));sort($ids,SORT_NUMERIC);
  $rows=DB::table('shipments')->where('company_id',$company)->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
  if($rows->count()!==count($ids))throw new \RuntimeException('ارتباط الشحنة لا يخص الشركة أو المصدر غير موجود.');
  foreach($rows as $s){
   // Reapply existing shipmentLines draft eligibility, not a new accounting rule.
   if((int)$s->branch_id!==$branch||(int)$s->supplier_id!==$supplier||strtoupper((string)$s->shipment_type)!=='PURCHASE')throw new \RuntimeException('الشحنة لا تطابق شركة وفرع ومورد الفاتورة.');
   if($s->commercial_status!=='READY')throw new \RuntimeException('SHIPMENT_NOT_READY: الشحنة ليست جاهزة للفوترة أو سبق ترحيلها.');
  }
 }
}
