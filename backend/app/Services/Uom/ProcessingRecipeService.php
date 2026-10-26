<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Auth\SessionContextService;

/** Explicit approved recipes only. No measured or dimensional conversion is inferred. */
final class ProcessingRecipeService
{
    private function actor(int $company,int $branch,string $permission):int
    {
        $id=(int)Auth::id();$user=DB::table('users')->where('id',$id)->where('company_id',$company)->where('is_active',1)->first();
        if(!$user||request()->attributes->get('is_support_mode',false)||!DB::table('branches')->where('company_id',$company)->where('id',$branch)->where('is_active',1)->exists()||($user->branch_id!==null&&(int)$user->branch_id!==$branch))throw new \RuntimeException('UOM_RECIPE_ACTOR_SCOPE');
        if(!app(SessionContextService::class)->permissionsForUser($id,$company)->contains($permission))throw new \RuntimeException('UOM_RECIPE_PERMISSION');
        return $id;
    }
    public function create(int $company,int $branch,array $input):int
    {
        $user=$this->actor($company,$branch,'inventory.process');
        if(!DB::getSchemaBuilder()->hasTable('uom_processing_recipes'))throw new \RuntimeException('UOM_RECIPE_SCHEMA_REQUIRED');
        $code=trim((string)($input['recipe_code']??''));if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$code))throw new \RuntimeException('UOM_RECIPE_CODE');
        $type=(string)($input['operation_type']??'');if(!in_array($type,['CONVERSION','SORTING','CLEANING','STRIPPING','CUTTING','DISASSEMBLY','RECLASSIFICATION','MIXING','ASSEMBLY','SCRAP'],true))throw new \RuntimeException('UOM_RECIPE_OPERATION');
        $reason=trim((string)($input['yield_notes']??''));if(mb_strlen($reason)<5)throw new \RuntimeException('UOM_RECIPE_YIELD_DESCRIPTION_REQUIRED');
        return DB::transaction(function()use($company,$branch,$input,$user,$code,$type,$reason){
            OperationalDocument::ready();$payload=['operation_type'=>$type,'yield_notes'=>$reason,'from'=>[],'to'=>[]];
            $ids=[];foreach(['from','to'] as $side)foreach(($input[$side]??[]) as $line)$ids[]=(int)($line['item_id']??0);
            \App\Services\InventoryPostingLocks::lockItems($company,$ids);
            foreach(['from','to'] as $side){
                if($side==='to'&&$type==='SCRAP'&&($input[$side]??[])===[])continue;
                if(!is_array($input[$side]??null)||count($input[$side])<1||count($input[$side])>100)throw new \RuntimeException('UOM_RECIPE_LINES_REQUIRED');
                $seen=[];foreach($input[$side] as $line){$item=DB::table('items')->where('company_id',$company)->where('id',(int)($line['item_id']??0))->where('is_active',1)->first();
                    if(!$item||isset($seen[$item->id]))throw new \RuntimeException('UOM_RECIPE_ITEM_SCOPE');$seen[$item->id]=true;
                    if(($item->inventory_quantity_version??'LEGACY_KG')==='BASE_V1'){
                        $p=OperationalDocument::prepare($company,$item,array_replace($line,['unit_price'=>'0']),false,true);
                        $payload[$side][]=['item_id'=>(int)$item->id,'quantity'=>$p['quantity'],'unit_code'=>$p['unit_code'],...$p['columns']];
                    }else{
                        if(($item->item_type??'STOCK')!=='STOCK'||!(bool)($item->track_inventory??true))throw new \RuntimeException('UOM_RECIPE_ITEM_SCOPE');
                        $unit=(string)($line['unit_code']??'');if(!in_array($unit,['KG','TON'],true))throw new \RuntimeException('UOM_RECIPE_LEGACY_WEIGHT_UNIT_REQUIRED');
                        $quantity=DecimalQuantity::decimal((string)($line['quantity']??''));$factor=$unit==='TON'?'1000.000000':'1.000000';
                        $payload[$side][]=['item_id'=>(int)$item->id,'uom_version'=>'LEGACY_KG','quantity'=>$quantity,'unit_code'=>$unit,'uom_factor_snapshot'=>$factor,'uom_mapping_id'=>null,'base_uom_id_snapshot'=>null,'base_quantity'=>null,'qty_kg'=>LegacyRecipeInventory::kilograms($quantity,$factor)];
                    }
                }
            }
            // A branch row serializes revisions, including an empty recipe namespace.
            DB::table('branches')->where('company_id',$company)->where('id',$branch)->lockForUpdate()->first();
            $revision=1+(int)DB::table('uom_processing_recipes')->where('company_id',$company)->where('branch_id',$branch)->where('recipe_code',$code)->max('revision');
            $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            return DB::table('uom_processing_recipes')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'recipe_code'=>$code,'revision'=>$revision,'payload'=>$json,'payload_hash'=>hash('sha256',$json),'created_by'=>$user,'created_at'=>now()]);
        });
    }
    public function approve(int $company,int $branch,int $id):void
    {
        $user=$this->actor($company,$branch,'inventory.process.post');
        DB::transaction(function()use($company,$branch,$id,$user){$recipe=$this->load($company,$branch,$id,false);if($recipe->status==='APPROVED')return;
            DB::table('uom_processing_recipes')->where('id',$id)->update(['status'=>'APPROVED','approved_by'=>$user,'approved_at'=>now()]);});
    }
    private function load(int $company,int $branch,int $id,bool $approved=true):object
    {
        $row=DB::table('uom_processing_recipes')->where('company_id',$company)->where('branch_id',$branch)->where('id',$id)->lockForUpdate()->first();
        if(!$row||($approved&&$row->status!=='APPROVED')||!hash_equals($row->payload_hash,hash('sha256',$row->payload)))throw new \RuntimeException('UOM_APPROVED_RECIPE_REQUIRED: يلزم وصفة معتمدة ضمن الشركة والفرع؛ لا يستنتج النظام تحويلًا بين الأبعاد.');
        return $row;
    }
    public function requiredLines(int $company,int $branch,int $id,string $multiplier):array
    {
        if(DB::transactionLevel()<1)throw new \RuntimeException('UOM_RECIPE_ROOT_REQUIRED');
        $factor=DecimalQuantity::decimal($multiplier);$recipe=$this->load($company,$branch,$id);$payload=json_decode($recipe->payload,true,512,JSON_THROW_ON_ERROR);
        foreach(['from','to'] as $side)foreach($payload[$side] as &$line){
            $line['quantity']=DecimalQuantity::convert($line['quantity'],$factor,true);
            if(($line['uom_version']??'')==='LEGACY_KG'){
                LegacyWeightGuard::item($company,$line['item_id']);$line['qty_kg']=LegacyRecipeInventory::kilograms($line['quantity'],$line['uom_factor_snapshot']);continue;
            }
            $unit=DB::table('uom_units')->where('id',$line['base_uom_id_snapshot'])->first();if(!$unit)throw new \RuntimeException('UOM_RECIPE_UNIT_SCOPE');
            $line['base_quantity']=DecimalQuantity::convert($line['quantity'],$line['uom_factor_snapshot'],(bool)$unit->allow_fractional);
        }unset($line);
        return ['recipe_id'=>$id,'recipe_hash'=>$recipe->payload_hash,'multiplier'=>$factor,...$payload];
    }
}
