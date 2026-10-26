<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
/** Separate contract ledger. Never creates lots, stock movements, invoices or GL. */
final class BaseCostContract
{
 private static function money(string $v):string{if(!preg_match('/^\d+(?:\.\d{1,3})?$/D',$v)||strlen(ltrim(explode('.',$v)[0],'0'))>15)throw new \RuntimeException('UOM_COST_PRECISION');return bcadd($v,'0',3);}
 public function create(int $company,int $snapshot,string $value):int{
  ContractTestingGuard::assert();
  UomAuthorization::actor($company,true);$value=self::money($value);$s=(new DocumentSnapshotService)->get($company,$snapshot);
  if(json_decode($s->payload,true)['side']!=='PURCHASE')throw new \RuntimeException('UOM_PURCHASE_COST_REQUIRED');
  $payload=json_decode($s->payload,true,512,JSON_THROW_ON_ERROR);$expected=bcadd(bcmul($payload['quantity'],$payload['unit_price'],12),'0.0005',3);
  if(bccomp($expected,$value,3)!==0)throw new \RuntimeException('UOM_COST_SOURCE_VALUE_MISMATCH');
  return DB::transaction(function()use($company,$snapshot,$value,$s){
   DB::table('uom_document_snapshots')->where('id',$snapshot)->lockForUpdate()->first();$old=DB::table('uom_cost_contracts')->where('company_id',$company)->where('snapshot_id',$snapshot)->first();if($old){if(bccomp($old->original_value,$value,3)!==0)throw new \RuntimeException('UOM_COST_CONFLICT');return(int)$old->id;}
   return DB::table('uom_cost_contracts')->insertGetId(['company_id'=>$company,'snapshot_id'=>$snapshot,'original_quantity'=>$s->base_quantity,'remaining_quantity'=>$s->base_quantity,'original_value'=>$value,'remaining_value'=>$value]);
  });
 }
 public function issue(int $company,int $contract,string $quantity,string $key):object{
  ContractTestingGuard::assert();
  UomAuthorization::actor($company,true);$quantity=DecimalQuantity::decimal($quantity,18);
  if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$key))throw new \RuntimeException('UOM_OPERATION_KEY');
  return DB::transaction(function()use($company,$contract,$quantity,$key){
   $c=DB::table('uom_cost_contracts')->where('company_id',$company)->where('id',$contract)->lockForUpdate()->first();if(!$c)throw new \RuntimeException('UOM_COST_SCOPE');$source=(new DocumentSnapshotService)->get($company,(int)$c->snapshot_id);$payload=json_decode($source->payload,true);$fractional=DB::table('uom_units')->where('id',$payload['base_unit_id'])->value('allow_fractional');if(!$fractional&&bccomp($quantity,bcadd($quantity,'0',0),6)!==0)throw new \RuntimeException('UOM_INDIVISIBLE_BASE');
   $old=DB::table('uom_cost_contract_issues')->where('company_id',$company)->where('operation_key',$key)->first();if($old){if((int)$old->contract_id!==$contract||bccomp($old->quantity,$quantity,6))throw new \RuntimeException('UOM_IDEMPOTENCY_CONFLICT');return $old;}
   if(bccomp($quantity,$c->remaining_quantity,6)>0)throw new \RuntimeException('UOM_COST_SHORTAGE');
   $full=bccomp($quantity,$c->remaining_quantity,6)===0;
   $exact=bcdiv(bcmul($c->original_value,$quantity,18),$c->original_quantity,18);
   $take=$full?$c->remaining_value:bcadd($exact,'0.0005',3);
   if(bccomp($take,$c->remaining_value,3)>0)throw new \RuntimeException('UOM_COST_ROUNDING_REVIEW');
   $rq=bcsub($c->remaining_quantity,$quantity,6);$rv=bcsub($c->remaining_value,$take,3);
   $id=DB::table('uom_cost_contract_issues')->insertGetId(['company_id'=>$company,'contract_id'=>$contract,'operation_key'=>$key,'quantity'=>$quantity,'value'=>$take,'remaining_quantity'=>$rq,'remaining_value'=>$rv]);return DB::table('uom_cost_contract_issues')->where('id',$id)->first();
  });
 }
}
