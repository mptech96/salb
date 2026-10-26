<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
final class ShipmentPurchaseLockFixture
{
 public static function install():void{
  UomStageAProof::guard();UomOperationalLockFixture::install();
  DB::table('accounts')->insert([['id'=>14,'company_id'=>1,'account_code'=>'BANK','account_name'=>'Synthetic bank','account_type'=>'ASSET','normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0],['id'=>15,'company_id'=>1,'account_code'=>'EXP','account_name'=>'Synthetic expense','account_type'=>'EXPENSE','normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0]]);
  DB::table('accounting_settings')->insert([['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>12],['company_id'=>1,'setting_key'=>'GENERAL_EXPENSE_ACCOUNT','account_id'=>15]]);
  DB::table('financial_accounts')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'account_code'=>'BANK','account_name'=>'Synthetic bank','account_type'=>'BANK','gl_account_id'=>14,'currency_code'=>'SAR']);
  DB::table('expense_types')->insert(['id'=>1,'company_id'=>1,'type_name'=>'Synthetic freight','type_code'=>'FREIGHT','account_id'=>15,'usage_type'=>'SHIPMENT','affects_cost'=>1]);
  DB::table('voucher_types')->insert(['id'=>2,'type_code'=>'PAYMENT','type_name'=>'Payment']);
 }
 public static function shipment(bool $capitalizable=true,bool $paid=true,int $item=1):array{
  $sid=DB::table('shipments')->insertGetId(['company_id'=>1,'branch_id'=>1,'supplier_id'=>1,'shipment_number'=>'SHIP-'.bin2hex(random_bytes(4)),'shipment_date'=>'2026-10-14','commercial_status'=>'READY','cost_allocation_method'=>'RELATIVE_VALUE','accepted_weight_kg'=>10,'currency_code'=>'SAR']);
  $line=DB::table('shipment_items')->insertGetId(['company_id'=>1,'shipment_id'=>$sid,'item_id'=>$item,'qty_kg'=>10,'accepted_qty_kg'=>10,'base_cost'=>100,'remaining_qty_kg'=>10]);
  $cost=app(\App\Services\ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>$sid,'expense_type_id'=>1,'expense_date'=>'2026-10-14','amount'=>20,'currency_code'=>'SAR','exchange_rate'=>1,'payment_status'=>$paid?'PAID':'UNPAID','financial_account_id'=>$paid?1:null,'capitalizable'=>$capitalizable,'created_by'=>1]);
  return ['shipment'=>$sid,'line'=>$line,'cost'=>$cost];
 }
 public static function purchase():array{
  $s=self::shipment();$id=UomOperationalLockFixture::purchase(1,1);
  DB::table('purchase_invoice_lines')->where('purchase_invoice_id',$id)->update(['shipment_id'=>$s['shipment'],'shipment_item_id'=>$s['line']]);
  DB::table('invoice_shipment_links')->insert(['company_id'=>1,'branch_id'=>1,'invoice_type'=>'PURCHASE','invoice_id'=>$id,'shipment_id'=>$s['shipment'],'allocated_qty_kg'=>10,'allocated_amount'=>100]);
  return $s+['invoice'=>$id];
 }
}
