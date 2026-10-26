<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\DB;
final class DocumentSnapshotService
{
 public function save(int $company,int $branch,int $item,int $mapping,int $price,string $quantity,string $side,string $key,string $documentRef,string $lineRef):object{
  ContractTestingGuard::assert();
  $actor=UomAuthorization::actor($company,true,$branch);
  if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$key)||$documentRef===''||$lineRef==='')throw new \RuntimeException('UOM_REFERENCE_REQUIRED');
  $quantity=DecimalQuantity::decimal($quantity);$hash=hash('sha256',json_encode([$company,$branch,$item,$mapping,$price,$quantity,$side,$documentRef,$lineRef],JSON_THROW_ON_ERROR));
  return DB::transaction(function()use($company,$branch,$item,$mapping,$price,$quantity,$side,$key,$documentRef,$lineRef,$hash,$actor){
   DB::table('items')->where('company_id',$company)->where('id',$item)->lockForUpdate()->first();
   $old=DB::table('uom_document_snapshots')->where('company_id',$company)->where('operation_key',$key)->first();if($old){if($old->fingerprint!==$hash)throw new \RuntimeException('UOM_IDEMPOTENCY_CONFLICT');return $old;}
   $snapshot=(new ItemUnitService)->snapshot($company,$item,$mapping,$price,$quantity,$side);
   $currency=DB::table('company_settings')->where('company_id',$company)->value('base_currency_code');if(!$currency||$currency!==$snapshot['currency'])throw new \RuntimeException('UOM_FX_POLICY_NOT_APPROVED');
   if(!DB::table('company_currencies')->where('company_id',$company)->where('currency_code',$currency)->where('is_active',1)->exists())throw new \RuntimeException('UOM_CURRENCY_NOT_ALLOWED');
   $snapshot['quantity_scale']=6;$snapshot['price_scale']=6;$snapshot['money_scale']=3;$snapshot['pricing_unit']=$snapshot['unit'];
   $id=DB::table('uom_document_snapshots')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'item_id'=>$item,'operation_key'=>$key,'document_ref'=>$documentRef,'line_ref'=>$lineRef,'fingerprint'=>$hash,'mapping_id'=>$mapping,'price_id'=>$price,'entered_quantity'=>$quantity,'factor'=>$snapshot['factor'],'base_quantity'=>$snapshot['base_quantity'],'payload'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'created_by'=>$actor->id]);return $this->get($company,$id);
  });
 }
 public function get(int $company,int $id):object{
  UomAuthorization::actor($company);$row=DB::table('uom_document_snapshots')->where('company_id',$company)->where('id',$id)->first();if(!$row)throw new \RuntimeException('UOM_SNAPSHOT_SCOPE');UomAuthorization::actor($company,false,(int)$row->branch_id);return $row;
 }
}
