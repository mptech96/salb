<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
final class UomOperationalLockFixture
{
 public static function install():void{
  UomStageAProof::guard();DB::unprepared(file_get_contents(base_path('database/schema/mysql-schema.sql')));
  DB::table('currencies')->insert(['currency_code'=>'SAR','currency_name'=>'Test SAR','is_active'=>1]);
  foreach([1,2] as $c){
   DB::table('sulb_document_sequences')->insert(['company_id'=>$c,'branch_id'=>0,'document_type'=>'INVENTORY_LOT','document_year'=>2026,'next_number'=>1]);
   DB::table('companies')->insert(['id'=>$c,'company_name'=>'UOM lock only']);DB::table('branches')->insert(['id'=>$c,'company_id'=>$c,'branch_name'=>'Test']);DB::table('users')->insert(['id'=>$c,'company_id'=>$c,'branch_id'=>$c,'name'=>'Test','username'=>'lock-'.$c,'password'=>'synthetic']);DB::table('financial_years')->insert(['id'=>$c,'company_id'=>$c,'year_name'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
   DB::table('company_settings')->insert(['company_id'=>$c,'base_currency_code'=>'SAR','currency_code'=>'SAR']);DB::table('company_currencies')->insert(['company_id'=>$c,'currency_code'=>'SAR','is_base'=>1,'is_active'=>1]);
   foreach([1=>'ASSET',2=>'LIABILITY',3=>'EQUITY'] as $k=>$type)DB::table('accounts')->insert(['id'=>$c*10+$k,'company_id'=>$c,'account_code'=>(string)$k,'account_name'=>'Test '.$k,'account_type'=>$type,'normal_side'=>$k===1?'DEBIT':'CREDIT','allow_posting'=>1,'allow_cost_center'=>0]);
   foreach(['INVENTORY_ACCOUNT'=>1,'SUPPLIER_ACCOUNT'=>2,'OPENING_BALANCE_ACCOUNT'=>3] as $key=>$a)DB::table('accounting_settings')->insert(['company_id'=>$c,'setting_key'=>$key,'account_id'=>$c*10+$a]);
   DB::table('suppliers')->insert(['id'=>$c,'company_id'=>$c,'supplier_name'=>'Test supplier','ledger_account_id'=>$c*10+2,'scope_all_branches'=>1]);
  }
  foreach([1=>1,2=>1,3=>2] as $id=>$c)DB::table('items')->insert(['id'=>$id,'company_id'=>$c,'item_code'=>'LOCK-'.$id,'item_name'=>'Test item','inventory_account_id'=>$c*10+1]);
 }
 public static function purchase(int $company,int $item):int{
  $id=DB::table('purchase_invoices')->insertGetId(['company_id'=>$company,'branch_id'=>$company,'supplier_id'=>$company,'invoice_number'=>'LOCK-P-'.bin2hex(random_bytes(5)),'invoice_date'=>'2026-10-14','currency_code'=>'SAR','exchange_rate'=>1,'total_before_vat'=>100,'total_amount'=>100,'base_total_before_vat'=>100,'base_total_amount'=>100,'created_by'=>$company]);
  DB::table('purchase_invoice_lines')->insert(['company_id'=>$company,'purchase_invoice_id'=>$id,'item_id'=>$item,'qty_kg'=>10,'quantity'=>10,'unit_code'=>'KG','unit_factor_to_base'=>1,'price_unit'=>'KG','unit_price'=>10,'total_before_vat'=>100,'total_after_vat'=>100,'line_total'=>100,'base_total_before_vat'=>100,'base_total_after_vat'=>100,'item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);return $id;
 }
 public static function opening(int $company,int $item):int{
  return app(\App\Services\OpeningBalanceService::class)->saveDraft($company,['financial_year_id'=>$company,'opening_date'=>'2026-10-14','inventory_lines'=>[['branch_id'=>$company,'item_id'=>$item,'qty_kg'=>10,'total_cost'=>100]]],$company);
 }
}
