<?php

namespace App\Services;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\PostingSupport;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    public function __construct(private JournalService $journals, private PostingSupport $posting, private InventoryLotService $lots) {}

    public function index(int $companyId, ?int $branchId=null)
    {
        $q=DB::table('opening_balance_batches as b')->join('financial_years as fy','fy.id','=','b.financial_year_id')->where('b.company_id',$companyId);
        if($branchId!==null)$q->where(function($w)use($branchId){
            $w->whereExists(fn($x)=>$x->selectRaw('1')->from('opening_balance_lines as l')->whereColumn('l.batch_id','b.id')->where('l.branch_id',$branchId))
              ->orWhereExists(fn($x)=>$x->selectRaw('1')->from('opening_inventory_lines as i')->whereColumn('i.batch_id','b.id')->where('i.branch_id',$branchId))
              ->orWhereExists(fn($x)=>$x->selectRaw('1')->from('opening_fixed_asset_lines as a')->whereColumn('a.batch_id','b.id')->where('a.branch_id',$branchId));
        });
        return $q->select('b.*','fy.year_name')->orderByDesc('b.opening_date')->orderByDesc('b.id')->get();
    }

    public function meta(int $companyId, ?int $branchId=null): array
    {
        return [
            'financial_years'=>DB::table('financial_years')->where('company_id',$companyId)->orderByDesc('start_date')->get(),
            'branches'=>DB::table('branches')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where('id',$branchId))->orderBy('branch_name')->get(),
            'accounts'=>DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->orderBy('account_code')->get(),
            'financial_accounts'=>DB::table('financial_accounts')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where(function($x)use($branchId){$x->where('branch_id',$branchId)->orWhereNull('branch_id');}))->orderBy('account_name')->get(),
            'cost_centers'=>DB::table('cost_centers')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where(function($x)use($branchId){$x->where('branch_id',$branchId)->orWhereNull('branch_id');}))->orderBy('cost_center_code')->get(),
            'customers'=>DB::table('customers')->where('company_id',$companyId)->where('is_active',1)->orderBy('customer_name')->get(['id','customer_code','customer_name']),
            'suppliers'=>DB::table('suppliers')->where('company_id',$companyId)->where('is_active',1)->orderBy('supplier_name')->get(['id','supplier_code','supplier_name']),
            'items'=>DB::table('items')->where('company_id',$companyId)->where('is_active',1)->orderBy('item_name')->get(['id','item_code','item_name']),
            'asset_categories'=>DB::table('fixed_asset_categories')->where('company_id',$companyId)->where('is_active',1)->orderBy('category_name')->get(),
            'base_currency'=>strtoupper(trim((string)(DB::table('company_settings')->where('company_id',$companyId)->value('base_currency_code') ?: DB::table('company_settings')->where('company_id',$companyId)->value('currency_code') ?: 'USD'))),
        ];
    }

    public function saveDraft(int $companyId,array $data,?int $userId=null,?int $forcedBranchId=null,?int $batchId=null): int
    {
        return DB::transaction(function()use($companyId,$data,$userId,$forcedBranchId,$batchId){
            $fy=DB::table('financial_years')->where('company_id',$companyId)->where('id',(int)($data['financial_year_id']??0))->first();
            if(!$fy)throw new \RuntimeException('السنة المالية غير موجودة.');
            if((int)$fy->is_closed===1)throw new \RuntimeException('لا يمكن إدخال أرصدة افتتاحية على سنة مقفلة.');
            $date=(string)($data['opening_date']??$fy->start_date);
            if($date<$fy->start_date||$date>$fy->end_date)throw new \RuntimeException('تاريخ الافتتاح يجب أن يقع داخل السنة المالية.');
            $accountLines=$data['account_lines']??[];$inventoryLines=$data['inventory_lines']??[];$assetLines=$data['asset_lines']??[];$baseCurrency=strtoupper(trim((string)(DB::table('company_settings')->where('company_id',$companyId)->value('base_currency_code') ?: DB::table('company_settings')->where('company_id',$companyId)->value('currency_code') ?: 'USD')));
            if(!$accountLines&&!$inventoryLines&&!$assetLines)throw new \RuntimeException('أضف رصيدًا افتتاحيًا واحدًا على الأقل.');

            if($batchId){
                $batch=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$batchId)->lockForUpdate()->first();
                if(!$batch)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة.');
                if($batch->status!=='DRAFT')throw new \RuntimeException('لا يمكن تعديل دفعة مرحلة.');
                DB::table('opening_balance_lines')->where('batch_id',$batchId)->delete();DB::table('opening_inventory_lines')->where('batch_id',$batchId)->delete();DB::table('opening_fixed_asset_lines')->where('batch_id',$batchId)->delete();
                DB::table('opening_balance_batches')->where('id',$batchId)->update(['financial_year_id'=>$fy->id,'opening_date'=>$date,'notes'=>$data['notes']??null,'updated_at'=>now()]);
            }else{
                $n=DB::table('opening_balance_batches')->where('company_id',$companyId)->count()+1;$no='OPEN-'.date('Y',strtotime($date)).'-'.str_pad($n,5,'0',STR_PAD_LEFT);
                while(DB::table('opening_balance_batches')->where('company_id',$companyId)->where('batch_number',$no)->exists()){$n++;$no='OPEN-'.date('Y',strtotime($date)).'-'.str_pad($n,5,'0',STR_PAD_LEFT);}
                $batchId=DB::table('opening_balance_batches')->insertGetId(['company_id'=>$companyId,'financial_year_id'=>$fy->id,'opening_date'=>$date,'batch_number'=>$no,'status'=>'DRAFT','notes'=>$data['notes']??null,'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
            }

            foreach($accountLines as $i=>$r){
                $branch=$this->branch($companyId,$forcedBranchId?:($r['branch_id']??null),false);$account=$this->account($companyId,(int)($r['account_id']??0));
                $d=round((float)($r['debit']??0),3);$c=round((float)($r['credit']??0),3);if(($d<=0&&$c<=0)||($d>0&&$c>0))throw new \RuntimeException('سطر الحساب رقم '.($i+1).' يجب أن يكون مدينًا أو دائنًا فقط.');
                $fa=isset($r['financial_account_id'])&&$r['financial_account_id']?(int)$r['financial_account_id']:null;
                if($fa){$f=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$fa)->where('is_active',1)->first();if(!$f||($branch&&(int)$f->branch_id!==$branch&&$f->branch_id!==null))throw new \RuntimeException('الخزينة في السطر رقم '.($i+1).' غير صالحة.');if((int)$f->gl_account_id!==(int)$account->id)throw new \RuntimeException('حساب الأستاذ لا يطابق الخزينة في السطر رقم '.($i+1).'.');}
                $partyType=!empty($r['party_type'])?strtoupper((string)$r['party_type']):null;$partyId=!empty($r['party_id'])?(int)$r['party_id']:null;$this->assertParty($companyId,$partyType,$partyId);
                $currencyCode=strtoupper(trim((string)($r['currency_code']??'')))?:null;$exchangeRate=isset($r['exchange_rate'])&&$r['exchange_rate']!==''&&$r['exchange_rate']!==null?(float)$r['exchange_rate']:null;if($currencyCode===$baseCurrency)$exchangeRate=1;DB::table('opening_balance_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'account_id'=>$account->id,'financial_account_id'=>$fa,'cost_center_id'=>$r['cost_center_id']??$this->posting->branchCostCenter($companyId,$branch),'party_type'=>$partyType,'party_id'=>$partyId,'debit'=>$d,'credit'=>$c,'currency_code'=>$currencyCode,'foreign_debit'=>round((float)($r['foreign_debit']??0),3),'foreign_credit'=>round((float)($r['foreign_credit']??0),3),'exchange_rate'=>$exchangeRate,'description'=>$r['description']??null,'created_at'=>now(),'updated_at'=>now()]);
            }

            foreach($inventoryLines as $i=>$r){
                $branch=$this->branch($companyId,$forcedBranchId?:($r['branch_id']??null),true);$item=DB::table('items')->where('company_id',$companyId)->where('id',(int)($r['item_id']??0))->where('is_active',1)->first();if(!$item)throw new \RuntimeException('صنف المخزون الافتتاحي رقم '.($i+1).' غير صالح.');
                $kg=round((float)($r['qty_kg']??0),3);$cost=round((float)($r['total_cost']??0),3);if($kg<=0||$cost<0)throw new \RuntimeException('كمية/تكلفة المخزون الافتتاحي غير صحيحة.');
                DB::table('opening_inventory_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'item_id'=>$item->id,'qty_kg'=>$kg,'total_cost'=>$cost,'unit_cost_per_kg'=>$kg>0?round($cost/$kg,6):0,'lot_number'=>$r['lot_number']??null,'notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            }

            foreach($assetLines as $i=>$r){
                $branch=$this->branch($companyId,$forcedBranchId?:($r['branch_id']??null),false);$cat=DB::table('fixed_asset_categories')->where('company_id',$companyId)->where('id',(int)($r['category_id']??0))->where('is_active',1)->first();if(!$cat)throw new \RuntimeException('فئة الأصل الافتتاحي رقم '.($i+1).' غير صالحة.');
                $code=trim((string)($r['asset_code']??''));$name=trim((string)($r['asset_name']??''));$cost=round((float)($r['historical_cost']??0),3);$acc=round((float)($r['opening_accumulated_depreciation']??0),3);
                if($code===''||$name===''||$cost<0||$acc<0||$acc>$cost)throw new \RuntimeException('بيانات الأصل الافتتاحي رقم '.($i+1).' غير صحيحة.');
                if(DB::table('fixed_assets')->where('company_id',$companyId)->where('asset_code',$code)->exists())throw new \RuntimeException('رقم الأصل '.$code.' موجود مسبقًا.');
                DB::table('opening_fixed_asset_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'category_id'=>$cat->id,'asset_code'=>$code,'asset_name'=>$name,'acquisition_date'=>$r['acquisition_date']??null,'depreciation_start_date'=>$r['depreciation_start_date']??($r['acquisition_date']??null),'historical_cost'=>$cost,'opening_accumulated_depreciation'=>$acc,'salvage_value'=>round((float)($r['salvage_value']??0),3),'depreciation_method'=>$r['depreciation_method']??$cat->depreciation_method,'useful_life_months'=>$r['useful_life_months']??$cat->useful_life_months,'annual_depreciation_rate'=>$r['annual_depreciation_rate']??$cat->annual_depreciation_rate,'asset_account_id'=>$r['asset_account_id']??$cat->asset_account_id,'accumulated_account_id'=>$r['accumulated_account_id']??$cat->accumulated_depreciation_account_id,'expense_account_id'=>$r['expense_account_id']??$cat->depreciation_expense_account_id,'notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            }
            return(int)$batchId;
        });
    }

    public function show(int $companyId,int $batchId): array
    {
        $batch=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$batchId)->first();if(!$batch)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة.');
        return ['batch'=>$batch,'account_lines'=>DB::table('opening_balance_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get(),'inventory_lines'=>DB::table('opening_inventory_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get(),'asset_lines'=>DB::table('opening_fixed_asset_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get()];
    }

    public function post(int $companyId,int $batchId,?int $userId=null): array
    {
        return DB::transaction(function()use($companyId,$batchId,$userId){
            $b=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$batchId)->lockForUpdate()->first();if(!$b)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة.');if($b->status==='POSTED')return['batch_id'=>$batchId,'journal_entry_id'=>(int)$b->journal_entry_id,'message'=>'الدفعة مرحلة مسبقًا.'];
            if($b->status!=='DRAFT')throw new \RuntimeException('حالة دفعة الافتتاح لا تسمح بالترحيل.');
            $fy=DB::table('financial_years')->where('company_id',$companyId)->where('id',$b->financial_year_id)->first();if(!$fy||$fy->is_closed)throw new \RuntimeException('السنة المالية غير صالحة للترحيل.');
            $lines=DB::table('opening_balance_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get()->map(fn($r)=>(array)$r)->all();
            $inventory=DB::table('opening_inventory_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get();
            $assets=DB::table('opening_fixed_asset_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get();
            $inventoryAcc=$this->posting->setting($companyId,'INVENTORY_ACCOUNT');
            foreach($inventory as $r)$lines[]=['account_id'=>$inventoryAcc,'branch_id'=>(int)$r->branch_id,'cost_center_id'=>$this->posting->branchCostCenter($companyId,(int)$r->branch_id),'debit'=>round((float)$r->total_cost,3),'credit'=>0,'description'=>'مخزون افتتاحي - '.$r->lot_number];
            foreach($assets as $r){$assetAcc=(int)($r->asset_account_id?:0);$accum=(int)($r->accumulated_account_id?:0);if(!$assetAcc||!$accum)throw new \RuntimeException('حسابات الأصل الافتتاحي '.$r->asset_code.' غير مكتملة.');$lines[]=['account_id'=>$assetAcc,'branch_id'=>$r->branch_id?(int)$r->branch_id:null,'cost_center_id'=>$this->posting->branchCostCenter($companyId,$r->branch_id?(int)$r->branch_id:null),'debit'=>round((float)$r->historical_cost,3),'credit'=>0,'description'=>'تكلفة أصل افتتاحي - '.$r->asset_name];if((float)$r->opening_accumulated_depreciation>0)$lines[]=['account_id'=>$accum,'branch_id'=>$r->branch_id?(int)$r->branch_id:null,'cost_center_id'=>$this->posting->branchCostCenter($companyId,$r->branch_id?(int)$r->branch_id:null),'debit'=>0,'credit'=>round((float)$r->opening_accumulated_depreciation,3),'description'=>'مجمع إهلاك افتتاحي - '.$r->asset_name];}
            $td=round(array_sum(array_map(fn($l)=>(float)($l['debit']??0),$lines)),3);$tc=round(array_sum(array_map(fn($l)=>(float)($l['credit']??0),$lines)),3);$diff=round($td-$tc,3);
            if(abs($diff)>0.0001){$openingAcc=$this->posting->setting($companyId,'OPENING_BALANCE_ACCOUNT');$lines[]=['account_id'=>$openingAcc,'branch_id'=>null,'cost_center_id'=>null,'debit'=>$diff<0?abs($diff):0,'credit'=>$diff>0?$diff:0,'description'=>'موازنة الأرصدة الافتتاحية'];$td=round($td+($diff<0?abs($diff):0),3);$tc=round($tc+($diff>0?$diff:0),3);}
            $journal=$this->journals->post(['company_id'=>$companyId,'branch_id'=>null,'allow_company_level'=>true,'entry_date'=>$b->opening_date,'source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'description'=>'الأرصدة الافتتاحية - '.$b->batch_number,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$userId]);

            foreach($inventory as $r){$lotId=$this->lots->createInboundLot(['company_id'=>$companyId,'branch_id'=>(int)$r->branch_id,'item_id'=>(int)$r->item_id,'qty_kg'=>(float)$r->qty_kg,'base_cost'=>(float)$r->total_cost,'lot_number'=>$r->lot_number?:null,'source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'received_at'=>$b->opening_date.' 00:00:00','notes'=>'رصيد افتتاحي '.$b->batch_number,'created_by'=>$userId]);DB::table('inventory_lots')->where('id',$lotId)->update(['opening_balance_batch_id'=>$batchId,'updated_at'=>now()]);DB::table('opening_inventory_lines')->where('id',$r->id)->update(['inventory_lot_id'=>$lotId,'updated_at'=>now()]);$kg=(float)$r->qty_kg;$cost=(float)$r->total_cost;$unit=$kg>0?round($cost/$kg,6):0;DB::table('stock_movements')->insert(['company_id'=>$companyId,'branch_id'=>$r->branch_id,'item_id'=>$r->item_id,'inventory_lot_id'=>$lotId,'movement_type'=>'IN','source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'journal_entry_id'=>$journal,'movement_date'=>$b->opening_date,'qty'=>round($kg/1000,6),'qty_kg'=>$kg,'unit_cost'=>round($unit*1000,3),'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'notes'=>'رصيد مخزون افتتاحي '.$b->batch_number,'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);}
            foreach($assets as $r){$book=round((float)$r->historical_cost-(float)$r->opening_accumulated_depreciation,3);$assetId=DB::table('fixed_assets')->insertGetId(['company_id'=>$companyId,'branch_id'=>$r->branch_id,'category_id'=>$r->category_id,'asset_code'=>$r->asset_code,'asset_name'=>$r->asset_name,'description'=>$r->notes,'purchase_date'=>$r->acquisition_date,'purchase_cost'=>$r->historical_cost,'salvage_value'=>$r->salvage_value,'current_book_value'=>$book,'depreciation_method'=>$r->depreciation_method,'useful_life_months'=>$r->useful_life_months,'annual_depreciation_rate'=>$r->annual_depreciation_rate,'accumulated_depreciation'=>$r->opening_accumulated_depreciation,'depreciation_start_date'=>$r->depreciation_start_date,'last_depreciation_date'=>null,'asset_account_id'=>$r->asset_account_id,'accumulated_account_id'=>$r->accumulated_account_id,'expense_account_id'=>$r->expense_account_id,'journal_entry_id'=>$journal,'asset_status'=>'ACTIVE','is_active'=>1,'acquisition_type'=>'OPENING_BALANCE','opening_accumulated_depreciation'=>$r->opening_accumulated_depreciation,'opening_balance_batch_id'=>$batchId,'created_by'=>$userId,'updated_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);DB::table('opening_fixed_asset_lines')->where('id',$r->id)->update(['fixed_asset_id'=>$assetId,'updated_at'=>now()]);}
            DB::table('opening_balance_batches')->where('id',$batchId)->update(['status'=>'POSTED','journal_entry_id'=>$journal,'total_debit'=>$td,'total_credit'=>$tc,'posted_by'=>$userId,'posted_at'=>now(),'updated_at'=>now()]);
            return['batch_id'=>$batchId,'journal_entry_id'=>$journal,'total_debit'=>$td,'total_credit'=>$tc,'balancing_amount'=>abs($diff),'message'=>'تم ترحيل الأرصدة الافتتاحية والمخزون والأصول بنجاح.'];
        });
    }

    private function branch(int $companyId,$branchId,bool $required): ?int
    {
        $id=(int)$branchId;if(!$id){if($required)throw new \RuntimeException('الفرع مطلوب لهذه العملية.');return null;}
        if(!DB::table('branches')->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->exists())throw new \RuntimeException('الفرع المحدد غير صالح.');return$id;
    }
    private function account(int $companyId,int $id): object{$a=DB::table('accounts')->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();if(!$a)throw new \RuntimeException('حساب افتتاحي غير صالح.');return$a;}
    private function assertParty(int $companyId,?string $type,?int $id): void
    {
        if(!$type&&!$id)return;if(!$type||!$id)throw new \RuntimeException('بيانات الطرف المحاسبي غير مكتملة.');$table=match($type){'CUSTOMER'=>'customers','SUPPLIER'=>'suppliers','WORKER'=>'workers','DRIVER'=>'drivers',default=>null};if(!$table||!DB::table($table)->where('company_id',$companyId)->where('id',$id)->exists())throw new \RuntimeException('الطرف المحاسبي غير صالح.');
    }
}
