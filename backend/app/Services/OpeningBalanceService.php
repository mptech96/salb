<?php

namespace App\Services;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\PostingSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpeningBalanceService
{
    public function __construct(private JournalService $journals, private PostingSupport $posting, private InventoryLotService $lots, private PartyBranchScopeService $parties) {}

    public function index(int $companyId, ?int $branchId=null, array $filters=[])
    {
        $q=DB::table('opening_balance_batches as b')->join('financial_years as fy','fy.id','=','b.financial_year_id')->where('b.company_id',$companyId);
        if($branchId!==null)$q->where(function($w)use($branchId){
            $w->whereExists(fn($x)=>$x->selectRaw('1')->from('opening_balance_lines as l')->whereColumn('l.batch_id','b.id')->where('l.branch_id',$branchId))
              ->orWhereExists(fn($x)=>$x->selectRaw('1')->from('opening_inventory_lines as i')->whereColumn('i.batch_id','b.id')->where('i.branch_id',$branchId))
              ->orWhereExists(fn($x)=>$x->selectRaw('1')->from('opening_fixed_asset_lines as a')->whereColumn('a.batch_id','b.id')->where('a.branch_id',$branchId));
        });
        $search=trim((string)($filters['search']??''));$q->when($search!=='',function($x)use($search){$like='%'.$search.'%';$x->where(function($w)use($like){$w->where('b.batch_number','like',$like)->orWhere('b.notes','like',$like)->orWhere('fy.year_name','like',$like);});})->when(!empty($filters['status']),fn($x)=>$x->where('b.status',strtoupper((string)$filters['status'])))->when(!empty($filters['financial_year_id']),fn($x)=>$x->where('b.financial_year_id',(int)$filters['financial_year_id']));
        return $q->select('b.*','fy.year_name')->orderByDesc('b.opening_date')->orderByDesc('b.id')->paginate(min(100,max(25,(int)($filters['per_page']??25))),['*'],'page',(int)($filters['page']??1));
    }

    public function meta(int $companyId, ?int $branchId=null): array
    {
        return [
            'financial_years'=>DB::table('financial_years')->where('company_id',$companyId)->orderByDesc('start_date')->get(),
            'branches'=>DB::table('branches')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where('id',$branchId))->orderBy('branch_name')->get(),
            'accounts'=>$this->lookup($companyId,'accounts','',$branchId),
            'financial_accounts'=>$this->lookup($companyId,'financial_accounts','',$branchId),
            'cost_centers'=>$this->lookup($companyId,'cost_centers','',$branchId),
            'customers'=>$this->lookup($companyId,'customers','',$branchId),
            'suppliers'=>$this->lookup($companyId,'suppliers','',$branchId),
            'items'=>$this->lookup($companyId,'items','',$branchId),
            'asset_categories'=>$this->lookup($companyId,'asset_categories','',$branchId),
            'base_currency'=>strtoupper(trim((string)(DB::table('company_settings')->where('company_id',$companyId)->value('base_currency_code') ?: DB::table('company_settings')->where('company_id',$companyId)->value('currency_code') ?: 'USD'))),
        ];
    }

    public function lookup(int $companyId,string $type,string $search='',?int $branchId=null)
    {
        $like='%'.trim($search).'%';
        return match($type){
            'accounts'=>DB::table('accounts')->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('account_code','like',$like)->orWhere('account_name','like',$like)))->orderBy('account_code')->limit(25)->get(),
            'financial_accounts'=>DB::table('financial_accounts')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$branchId)->orWhereNull('branch_id')))->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('account_code','like',$like)->orWhere('account_name','like',$like)))->orderBy('account_name')->limit(25)->get(),
            'cost_centers'=>DB::table('cost_centers')->where('company_id',$companyId)->where('is_active',1)->when($branchId!==null,fn($q)=>$q->where(fn($x)=>$x->where('branch_id',$branchId)->orWhereNull('branch_id')))->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('cost_center_code','like',$like)->orWhere('cost_center_name','like',$like)))->orderBy('cost_center_code')->limit(25)->get(),
            'customers'=>$this->parties->scopeQuery(DB::table('customers as s')->where('s.company_id',$companyId)->where('s.is_active',1),$companyId,'CUSTOMER',$branchId)->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('s.customer_code','like',$like)->orWhere('s.customer_name','like',$like)))->orderBy('s.customer_name')->limit(25)->get(['s.id','s.customer_code','s.customer_name']),
            'suppliers'=>$this->parties->scopeQuery(DB::table('suppliers as s')->where('s.company_id',$companyId)->where('s.is_active',1),$companyId,'SUPPLIER',$branchId)->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('s.supplier_code','like',$like)->orWhere('s.supplier_name','like',$like)))->orderBy('s.supplier_name')->limit(25)->get(['s.id','s.supplier_code','s.supplier_name']),
            'items'=>DB::table('items')->where('company_id',$companyId)->where('is_active',1)->when($search!=='',fn($q)=>$q->where(fn($x)=>$x->where('item_code','like',$like)->orWhere('item_name','like',$like)))->orderBy('item_name')->limit(25)->get(['id','item_code','item_name']),
            'asset_categories'=>DB::table('fixed_asset_categories')->where('company_id',$companyId)->where('is_active',1)->when($search!=='',fn($q)=>$q->where('category_name','like',$like))->orderBy('category_name')->limit(25)->get(),
        };
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
                $this->assertBatchMutationScope($companyId,$batchId,$forcedBranchId);
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
                $partyType=!empty($r['party_type'])?strtoupper((string)$r['party_type']):null;$partyId=!empty($r['party_id'])?(int)$r['party_id']:null;$this->assertParty($companyId,$partyType,$partyId,$branch);
                if($partyType==='CUSTOMER'){if((int)$account->id!==$this->posting->setting($companyId,'CUSTOMER_ACCOUNT')||$d<=0)throw new \RuntimeException('رصيد العميل الافتتاحي يجب أن يكون مدينًا على حساب العملاء المعتمد.');}
                if($partyType==='SUPPLIER'){if((int)$account->id!==$this->posting->setting($companyId,'SUPPLIER_ACCOUNT')||$c<=0)throw new \RuntimeException('رصيد المورد الافتتاحي يجب أن يكون دائنًا على حساب الموردين المعتمد.');}
                $currencyCode=strtoupper(trim((string)($r['currency_code']??'')))?:$baseCurrency;$exchangeRate=isset($r['exchange_rate'])&&$r['exchange_rate']!==''&&$r['exchange_rate']!==null?(float)$r['exchange_rate']:null;$foreignDebit=round((float)($r['foreign_debit']??0),3);$foreignCredit=round((float)($r['foreign_credit']??0),3);$this->assertMoney($companyId,$baseCurrency,$currencyCode,$exchangeRate,$d,$c,$foreignDebit,$foreignCredit,$i);if($currencyCode===$baseCurrency)$exchangeRate=1;DB::table('opening_balance_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'account_id'=>$account->id,'financial_account_id'=>$fa,'cost_center_id'=>$r['cost_center_id']??$this->posting->branchCostCenter($companyId,$branch),'party_type'=>$partyType,'party_id'=>$partyId,'debit'=>$d,'credit'=>$c,'currency_code'=>$currencyCode,'foreign_debit'=>$foreignDebit,'foreign_credit'=>$foreignCredit,'exchange_rate'=>$exchangeRate,'description'=>$r['description']??null,'created_at'=>now(),'updated_at'=>now()]);
            }

            foreach($inventoryLines as $i=>$r){
                $branch=$this->branch($companyId,$forcedBranchId?:($r['branch_id']??null),true);$item=DB::table('items')->where('company_id',$companyId)->where('id',(int)($r['item_id']??0))->where('is_active',1)->first();if(!$item)throw new \RuntimeException('صنف المخزون الافتتاحي رقم '.($i+1).' غير صالح.');
                $kg=round((float)($r['qty_kg']??0),3);$cost=round((float)($r['total_cost']??0),3);if($kg<=0||$cost<0)throw new \RuntimeException('كمية/تكلفة المخزون الافتتاحي غير صحيحة.');
                DB::table('opening_inventory_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'item_id'=>$item->id,'qty_kg'=>$kg,'total_cost'=>$cost,'unit_cost_per_kg'=>$kg>0?round($cost/$kg,6):0,'lot_number'=>$r['lot_number']??null,'notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            }

            foreach($assetLines as $i=>$r){
                $branch=$this->branch($companyId,$forcedBranchId?:($r['branch_id']??null),false);$cat=DB::table('fixed_asset_categories')->where('company_id',$companyId)->where('id',(int)($r['category_id']??0))->where('is_active',1)->first();if(!$cat)throw new \RuntimeException('فئة الأصل الافتتاحي رقم '.($i+1).' غير صالحة.');
                $code=trim((string)($r['asset_code']??''));$name=trim((string)($r['asset_name']??''));$cost=round((float)($r['historical_cost']??0),3);$acc=round((float)($r['opening_accumulated_depreciation']??0),3);$salvage=round((float)($r['salvage_value']??0),3);$method=strtoupper((string)($r['depreciation_method']??$cat->depreciation_method));$life=isset($r['useful_life_months'])?(int)$r['useful_life_months']:(int)($cat->useful_life_months??0);$rate=isset($r['annual_depreciation_rate'])?(float)$r['annual_depreciation_rate']:(float)($cat->annual_depreciation_rate??0);$acquired=$r['acquisition_date']??null;$starts=$r['depreciation_start_date']??$acquired;
                if($code===''||$name===''||$cost<=0||$acc<0||$salvage<0||$salvage>$cost||$acc>$cost-$salvage)throw new \RuntimeException('التكلفة أو المجمع أو القيمة التخريدية للأصل الافتتاحي رقم '.($i+1).' غير صحيحة.');
                if(!in_array($method,['STRAIGHT_LINE','DECLINING_BALANCE','NO_DEPRECIATION'],true)||($method!=='NO_DEPRECIATION'&&$life<1)||$rate<0||$rate>100||($method==='DECLINING_BALANCE'&&$rate<=0)||($method!=='NO_DEPRECIATION'&&!$starts)||($acquired&&$acquired>$date)||($acquired&&$starts&&$starts<$acquired))throw new \RuntimeException('طريقة/مدة/تاريخ إهلاك الأصل الافتتاحي رقم '.($i+1).' غير صحيحة.');
                $assetAccount=$this->assetAccount($companyId,(int)($r['asset_account_id']??$cat->asset_account_id),'ASSET',$branch,'حساب الأصل');$accumulatedAccount=$this->assetAccount($companyId,(int)($r['accumulated_account_id']??$cat->accumulated_depreciation_account_id),'ASSET',$branch,'حساب مجمع الإهلاك');$expenseAccount=$this->assetAccount($companyId,(int)($r['expense_account_id']??$cat->depreciation_expense_account_id),'EXPENSE',$branch,'حساب مصروف الإهلاك');
                if(DB::table('fixed_assets')->where('company_id',$companyId)->where('asset_code',$code)->exists())throw new \RuntimeException('رقم الأصل '.$code.' موجود مسبقًا.');
                DB::table('opening_fixed_asset_lines')->insert(['company_id'=>$companyId,'batch_id'=>$batchId,'branch_id'=>$branch,'category_id'=>$cat->id,'asset_code'=>$code,'asset_name'=>$name,'acquisition_date'=>$acquired,'depreciation_start_date'=>$starts,'historical_cost'=>$cost,'opening_accumulated_depreciation'=>$acc,'salvage_value'=>$salvage,'depreciation_method'=>$method,'useful_life_months'=>$life?:null,'annual_depreciation_rate'=>$rate?:null,'asset_account_id'=>$assetAccount->id,'accumulated_account_id'=>$accumulatedAccount->id,'expense_account_id'=>$expenseAccount->id,'notes'=>$r['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            }
            return(int)$batchId;
        });
    }

    public function show(int $companyId,int $batchId,?int $branchId=null): array
    {
        $batch=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$batchId)->first();if(!$batch)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة.');
        $scope=fn($q)=>$q->where('company_id',$companyId)->where('batch_id',$batchId)->when($branchId!==null,fn($x)=>$x->where('branch_id',$branchId));
        $accountLines=$scope(DB::table('opening_balance_lines'))->get();$inventoryLines=$scope(DB::table('opening_inventory_lines'))->get();$assetLines=$scope(DB::table('opening_fixed_asset_lines'))->get();
        if($branchId!==null&&!$accountLines->count()&&!$inventoryLines->count()&&!$assetLines->count())throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة ضمن نطاق الفرع.');
        if($branchId!==null){$batch->total_debit=round((float)$accountLines->sum('debit')+(float)$inventoryLines->sum('total_cost')+(float)$assetLines->sum('historical_cost'),3);$batch->total_credit=round((float)$accountLines->sum('credit')+(float)$assetLines->sum('opening_accumulated_depreciation'),3);$batch->notes=null;}
        return ['batch'=>$batch,'account_lines'=>$accountLines,'inventory_lines'=>$inventoryLines,'asset_lines'=>$assetLines];
    }

    public function preview(int $companyId,int $batchId,?int $branchId=null): array
    {
        $data=$this->show($companyId,$batchId,$branchId);$lines=$data['account_lines'];$inventory=$data['inventory_lines'];$assets=$data['asset_lines'];
        $debit=round((float)$lines->sum('debit')+(float)$inventory->sum('total_cost')+(float)$assets->sum('historical_cost'),3);$credit=round((float)$lines->sum('credit')+(float)$assets->sum('opening_accumulated_depreciation'),3);$diff=round($debit-$credit,3);$accountId=$this->posting->setting($companyId,'OPENING_BALANCE_ACCOUNT');$account=DB::table('accounts')->where('company_id',$companyId)->where('id',$accountId)->first();
        return['total_debit'=>$debit,'total_credit'=>$credit,'difference'=>abs($diff),'requires_balancing_confirmation'=>abs($diff)>0.0001,'balancing_side'=>$diff>0?'CREDIT':($diff<0?'DEBIT':null),'balancing_account_id'=>$accountId,'balancing_account_code'=>$account?->account_code,'balancing_account_name'=>$account?->account_name];
    }

    public function post(int $companyId,int $batchId,?int $userId=null,bool $confirmBalancing=false,?int $forcedBranchId=null): array
    {
        return DB::transaction(function()use($companyId,$batchId,$userId,$confirmBalancing,$forcedBranchId){
            $this->assertBatchMutationScope($companyId,$batchId,$forcedBranchId);
            $b=DB::table('opening_balance_batches')->where('company_id',$companyId)->where('id',$batchId)->lockForUpdate()->first();if(!$b)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة.');if($b->status==='POSTED')return['batch_id'=>$batchId,'journal_entry_id'=>(int)$b->journal_entry_id,'message'=>'الدفعة مرحلة مسبقًا.'];
            if($b->status!=='DRAFT')throw new \RuntimeException('حالة دفعة الافتتاح لا تسمح بالترحيل.');
            $fy=DB::table('financial_years')->where('company_id',$companyId)->where('id',$b->financial_year_id)->first();if(!$fy||$fy->is_closed)throw new \RuntimeException('السنة المالية غير صالحة للترحيل.');
            $lines=DB::table('opening_balance_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get()->map(fn($r)=>(array)$r)->all();
            $inventory=DB::table('opening_inventory_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get();
            $assets=DB::table('opening_fixed_asset_lines')->where('company_id',$companyId)->where('batch_id',$batchId)->get();
            if($forcedBranchId!==null){foreach([$lines,$inventory->map(fn($x)=>(array)$x)->all(),$assets->map(fn($x)=>(array)$x)->all()]as$set)foreach($set as$row)if((int)($row['branch_id']??0)!==$forcedBranchId)throw new \RuntimeException('تحتوي الدفعة على بيانات خارج نطاق الفرع ولا يمكن ترحيلها بهذا المستخدم.');}
            $inventoryAcc=$this->posting->setting($companyId,'INVENTORY_ACCOUNT');
            foreach($inventory as $r)$lines[]=['account_id'=>$inventoryAcc,'branch_id'=>(int)$r->branch_id,'cost_center_id'=>$this->posting->branchCostCenter($companyId,(int)$r->branch_id),'debit'=>round((float)$r->total_cost,3),'credit'=>0,'description'=>'مخزون افتتاحي - '.$r->lot_number];
            foreach($assets as $r){$assetAcc=(int)($r->asset_account_id?:0);$accum=(int)($r->accumulated_account_id?:0);if(!$assetAcc||!$accum)throw new \RuntimeException('حسابات الأصل الافتتاحي '.$r->asset_code.' غير مكتملة.');$lines[]=['account_id'=>$assetAcc,'branch_id'=>$r->branch_id?(int)$r->branch_id:null,'cost_center_id'=>$this->posting->branchCostCenter($companyId,$r->branch_id?(int)$r->branch_id:null),'debit'=>round((float)$r->historical_cost,3),'credit'=>0,'description'=>'تكلفة أصل افتتاحي - '.$r->asset_name];if((float)$r->opening_accumulated_depreciation>0)$lines[]=['account_id'=>$accum,'branch_id'=>$r->branch_id?(int)$r->branch_id:null,'cost_center_id'=>$this->posting->branchCostCenter($companyId,$r->branch_id?(int)$r->branch_id:null),'debit'=>0,'credit'=>round((float)$r->opening_accumulated_depreciation,3),'description'=>'مجمع إهلاك افتتاحي - '.$r->asset_name];}
            $td=round(array_sum(array_map(fn($l)=>(float)($l['debit']??0),$lines)),3);$tc=round(array_sum(array_map(fn($l)=>(float)($l['credit']??0),$lines)),3);$diff=round($td-$tc,3);
            if(abs($diff)>0.0001){if(!$confirmBalancing)throw new \RuntimeException('يجب تأكيد استخدام حساب موازنة الأرصدة الافتتاحية صراحةً.');$openingAcc=$this->posting->setting($companyId,'OPENING_BALANCE_ACCOUNT');$lines[]=['account_id'=>$openingAcc,'branch_id'=>null,'cost_center_id'=>null,'debit'=>$diff<0?abs($diff):0,'credit'=>$diff>0?$diff:0,'description'=>'موازنة الأرصدة الافتتاحية'];$td=round($td+($diff<0?abs($diff):0),3);$tc=round($tc+($diff>0?$diff:0),3);}
            $journal=$this->journals->post(['company_id'=>$companyId,'branch_id'=>null,'allow_company_level'=>true,'entry_date'=>$b->opening_date,'source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'description'=>'الأرصدة الافتتاحية - '.$b->batch_number,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$userId]);

            foreach($inventory as $r){$lotId=$this->lots->createInboundLot(['company_id'=>$companyId,'branch_id'=>(int)$r->branch_id,'item_id'=>(int)$r->item_id,'qty_kg'=>(float)$r->qty_kg,'base_cost'=>(float)$r->total_cost,'lot_number'=>$r->lot_number?:null,'source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'received_at'=>$b->opening_date.' 00:00:00','notes'=>'رصيد افتتاحي '.$b->batch_number,'created_by'=>$userId]);DB::table('inventory_lots')->where('id',$lotId)->update(['opening_balance_batch_id'=>$batchId,'updated_at'=>now()]);DB::table('opening_inventory_lines')->where('id',$r->id)->update(['inventory_lot_id'=>$lotId,'updated_at'=>now()]);$kg=(float)$r->qty_kg;$cost=(float)$r->total_cost;$unit=$kg>0?round($cost/$kg,6):0;DB::table('stock_movements')->insert(['company_id'=>$companyId,'branch_id'=>$r->branch_id,'item_id'=>$r->item_id,'inventory_lot_id'=>$lotId,'movement_type'=>'IN','source_type'=>'OPENING_BALANCE','source_id'=>$batchId,'journal_entry_id'=>$journal,'movement_date'=>$b->opening_date,'qty'=>round($kg/1000,6),'qty_kg'=>$kg,'unit_cost'=>round($unit*1000,3),'unit_cost_per_kg'=>$unit,'total_cost'=>$cost,'notes'=>'رصيد مخزون افتتاحي '.$b->batch_number,'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);}
            foreach($assets as $r){$book=round((float)$r->historical_cost-(float)$r->opening_accumulated_depreciation,3);$assetId=DB::table('fixed_assets')->insertGetId(['company_id'=>$companyId,'branch_id'=>$r->branch_id,'category_id'=>$r->category_id,'asset_code'=>$r->asset_code,'asset_name'=>$r->asset_name,'description'=>$r->notes,'purchase_date'=>$r->acquisition_date,'purchase_cost'=>$r->historical_cost,'salvage_value'=>$r->salvage_value,'current_book_value'=>$book,'depreciation_method'=>$r->depreciation_method,'useful_life_months'=>$r->useful_life_months,'annual_depreciation_rate'=>$r->annual_depreciation_rate,'accumulated_depreciation'=>$r->opening_accumulated_depreciation,'depreciation_start_date'=>$r->depreciation_start_date,'last_depreciation_date'=>null,'asset_account_id'=>$r->asset_account_id,'accumulated_account_id'=>$r->accumulated_account_id,'expense_account_id'=>$r->expense_account_id,'journal_entry_id'=>$journal,'asset_status'=>'ACTIVE','is_active'=>1,'acquisition_type'=>'OPENING_BALANCE','opening_accumulated_depreciation'=>$r->opening_accumulated_depreciation,'opening_balance_batch_id'=>$batchId,'created_by'=>$userId,'updated_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);DB::table('opening_fixed_asset_lines')->where('id',$r->id)->update(['fixed_asset_id'=>$assetId,'updated_at'=>now()]);}
            DB::table('opening_balance_batches')->where('id',$batchId)->update(['status'=>'POSTED','journal_entry_id'=>$journal,'total_debit'=>$td,'total_credit'=>$tc,'posted_by'=>$userId,'posted_at'=>now(),'updated_at'=>now()]);
            return['batch_id'=>$batchId,'journal_entry_id'=>$journal,'total_debit'=>$td,'total_credit'=>$tc,'balancing_amount'=>abs($diff),'message'=>'تم ترحيل الأرصدة الافتتاحية والمخزون والأصول بنجاح.'];
        });
    }

    private function assertBatchMutationScope(int $companyId,int $batchId,?int $branchId):void
    {
        if($branchId===null)return;
        $hasOwned=false;
        foreach(['opening_balance_lines','opening_inventory_lines','opening_fixed_asset_lines']as$table){
            $q=DB::table($table)->where('company_id',$companyId)->where('batch_id',$batchId);
            if((clone$q)->where(fn($x)=>$x->whereNull('branch_id')->orWhere('branch_id','<>',$branchId))->exists())throw new \RuntimeException('تحتوي الدفعة على بيانات خارج نطاق الفرع ولا يمكن تعديلها أو ترحيلها بهذا المستخدم.');
            $hasOwned=$hasOwned||(clone$q)->where('branch_id',$branchId)->exists();
        }
        if(!$hasOwned)throw new \RuntimeException('دفعة الأرصدة الافتتاحية غير موجودة ضمن نطاق الفرع.');
    }

    private function branch(int $companyId,$branchId,bool $required): ?int
    {
        $id=(int)$branchId;if(!$id){if($required)throw new \RuntimeException('الفرع مطلوب لهذه العملية.');return null;}
        if(!DB::table('branches')->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->exists())throw new \RuntimeException('الفرع المحدد غير صالح.');return$id;
    }
    private function account(int $companyId,int $id): object{$a=DB::table('accounts')->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();if(!$a)throw new \RuntimeException('حساب افتتاحي غير صالح.');return$a;}
    private function assetAccount(int $companyId,int $id,string $type,?int $branchId,string $label): object{$a=DB::table('accounts')->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->where('account_type',$type)->when($branchId!==null&&Schema::hasColumn('accounts','branch_id'),fn($q)=>$q->where(function($x)use($branchId){$x->whereNull('branch_id')->orWhere('branch_id',$branchId);}))->first();if(!$a)throw new \RuntimeException($label.' غير صالح ضمن الشركة/الفرع.');return$a;}
    private function assertParty(int $companyId,?string $type,?int $id,?int $branchId): void
    {
        if(!$type&&!$id)return;if(!$type||!$id)throw new \RuntimeException('بيانات الطرف المحاسبي غير مكتملة.');if(!in_array($type,['CUSTOMER','SUPPLIER'],true))throw new \RuntimeException('نوع الطرف الافتتاحي غير مدعوم.');if($branchId!==null)$this->parties->assertAccessible($companyId,$type,$id,$branchId);else{$table=$type==='CUSTOMER'?'customers':'suppliers';if(!DB::table($table)->where('company_id',$companyId)->where('id',$id)->where('is_active',1)->exists())throw new \RuntimeException('الطرف المحاسبي غير صالح.');}
    }
    private function assertMoney(int $companyId,string $base,string $currency,?float $rate,float $debit,float $credit,float $foreignDebit,float $foreignCredit,int $index): void
    {
        $active=DB::table('company_currencies as cc')->join('currencies as cu','cu.currency_code','=','cc.currency_code')->where('cc.company_id',$companyId)->where('cc.currency_code',$currency)->where('cc.is_active',1)->where('cu.is_active',1)->exists();if(!$active)throw new \RuntimeException('عملة السطر رقم '.($index+1).' غير مفعلة للشركة.');
        if($currency===$base){if($rate!==null&&abs($rate-1)>0.0000001)throw new \RuntimeException('سعر العملة الأساسية يجب أن يساوي 1.');if(abs($foreignDebit)>0.0001||abs($foreignCredit)>0.0001)throw new \RuntimeException('لا تدخل قيمة أجنبية لسطر بالعملة الأساسية.');return;}
        if($rate===null||$rate<=0)throw new \RuntimeException('سعر صرف موجب مطلوب للسطر رقم '.($index+1).'.');if(($debit>0&&$foreignDebit<=0)||($credit>0&&$foreignCredit<=0)||($debit>0&&$foreignCredit>0)||($credit>0&&$foreignDebit>0))throw new \RuntimeException('جهة المبلغ الأجنبي لا تطابق جهة الرصيد الأساسي.');$foreign=$debit>0?$foreignDebit:$foreignCredit;$baseAmount=$debit>0?$debit:$credit;if(abs(round($foreign*$rate,3)-$baseAmount)>0.001)throw new \RuntimeException('المبلغ الأجنبي وسعر الصرف لا يطابقان مبلغ الأساس في السطر رقم '.($index+1).'.');
    }
}
