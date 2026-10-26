<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
final class UnitPriceQuote
{
 public function calculate(int $company,array $snapshot,string $discount,?int $taxCode,string $date):array{
  UomAuthorization::actor($company);if(DB::table('company_settings')->where('company_id',$company)->value('currency_decimal_places')!=3)throw new \RuntimeException('UOM_MONEY_SCALE_REVIEW');
  $gross=bcadd(bcmul($snapshot['quantity'],$snapshot['unit_price'],12),'0.0005',3);$discount=DecimalQuantity::decimal($discount,15,true);if(bccomp($discount,bcadd($discount,'0',3),6)!==0||bccomp($discount,$gross,6)>0)throw new \RuntimeException('UOM_DISCOUNT');
  $net=bcsub($gross,$discount,3);$tax=app(\App\Services\TaxEngineService::class)->resolve($company,$taxCode,$date,$snapshot['side']==='SALE'?'SALES':'PURCHASE');$rate=$tax&& !$tax->is_exempt&&!$tax->is_out_of_scope?(string)$tax->rate:'0';
  $inclusive=DB::table('company_settings')->where('company_id',$company)->value('tax_inclusive_prices');
  if($inclusive&&bccomp($rate,'0',6)>0){$before=bcadd(bcdiv($net,bcadd('1',bcdiv($rate,'100',12),12),12),'0.0005',3);$vat=bcsub($net,$before,3);$total=$net;}
  else{$before=$net;$vat=bcadd(bcdiv(bcmul($net,$rate,12),'100',12),'0.0005',3);$total=bcadd($net,$vat,3);}
  return ['gross'=>$gross,'discount'=>bcadd($discount,'0',3),'taxable'=>$before,'vat'=>$vat,'total'=>$total,'rate'=>$rate,'tax_code_id'=>$tax?->id];
 }
}
