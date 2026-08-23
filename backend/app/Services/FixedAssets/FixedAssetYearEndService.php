<?php
namespace App\Services\FixedAssets;

use App\Models\FixedAsset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetYearEndService
{
    public function __construct(private FixedAssetDepreciationService $depreciation){}

    public function missingCount(int $companyId,string $from,string $to): int
    {
        $count=0;$startFy=Carbon::parse($from)->startOfMonth();$endFy=Carbon::parse($to)->startOfMonth();
        $assets=FixedAsset::query()->where('company_id',$companyId)->where('is_active',true)->where('asset_status','ACTIVE')->where('depreciation_method','!=','NO_DEPRECIATION')->whereNotNull('depreciation_start_date')->get();
        foreach($assets as$a){if((float)$a->current_book_value<=(float)$a->salvage_value+0.0001)continue;$m=Carbon::parse($a->depreciation_start_date)->startOfMonth();if($m->lt($startFy))$m=$startFy->copy();for(;$m->lte($endFy);$m->addMonth()){$exists=DB::table('fixed_asset_depreciation')->where('company_id',$companyId)->where('asset_id',$a->id)->whereDate('depreciation_month',$m->toDateString())->whereIn('status',['DRAFT','POSTED'])->exists();if(!$exists)$count++;}}
        return$count;
    }

    public function complete(int $companyId,string $from,string $to,?int $userId=null): array
    {
        $posted=[];$skipped=[];$startFy=Carbon::parse($from)->startOfMonth();$endFy=Carbon::parse($to)->startOfMonth();
        $assets=FixedAsset::query()->where('company_id',$companyId)->where('is_active',true)->where('asset_status','ACTIVE')->where('depreciation_method','!=','NO_DEPRECIATION')->whereNotNull('depreciation_start_date')->orderBy('id')->get();
        foreach($assets as$a){$m=Carbon::parse($a->depreciation_start_date)->startOfMonth();if($m->lt($startFy))$m=$startFy->copy();for(;$m->lte($endFy);$m->addMonth()){
            $exists=DB::table('fixed_asset_depreciation')->where('company_id',$companyId)->where('asset_id',$a->id)->whereDate('depreciation_month',$m->toDateString())->whereIn('status',['DRAFT','POSTED'])->exists();if($exists){$skipped[]=['asset_id'=>$a->id,'month'=>$m->format('Y-m'),'reason'=>'POSTED'];continue;}
            try{$posted[]=$this->depreciation->depreciate((int)$a->id,$m->toDateString(),['company_id'=>$companyId,'created_by'=>$userId]);}
            catch(\Throwable$e){$msg=$e->getMessage();if(str_contains($msg,'القيمة المتبقية')||str_contains($msg,'تساوي صفرًا')){$skipped[]=['asset_id'=>$a->id,'month'=>$m->format('Y-m'),'reason'=>'FULLY_DEPRECIATED'];break;}throw new \RuntimeException('تعذر إكمال إهلاك الأصل '.$a->asset_code.' عن '.$m->format('Y-m').': '.$msg,0,$e);}
        }}
        return['posted_count'=>count($posted),'skipped_count'=>count($skipped),'total_depreciation'=>round((float)collect($posted)->sum(fn($x)=>(float)($x['depreciation_amount']??0)),3),'posted'=>$posted,'skipped'=>$skipped];
    }
}
