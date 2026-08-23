<?php

namespace App\Services;

use App\Services\Accounting\PostingSupport;
use App\Services\Accounting\ItemAccountingResolver;
use Illuminate\Support\Facades\DB;

class ShipmentCostService
{
    public function __construct(private ShipmentCostDistributionService $distribution,private PostingSupport $support,private FinancialAccountService $money,private ItemAccountingResolver $itemAccounts,private SulbDocumentSequenceService $sequences){}

    public function storeDraft(array $d): int
    {
        return DB::transaction(function()use($d){
            $cid=(int)$d['company_id'];$sid=(int)$d['shipment_id'];$bid=(int)$d['branch_id'];
            $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();
            if(!$s||((int)$s->branch_id!==$bid))throw new \RuntimeException('الشحنة غير موجودة ضمن الفرع.');
            if(!in_array(($s->commercial_status??'DRAFT'),['DRAFT','READY'],true))throw new \RuntimeException('لا يمكن إضافة تكلفة لشحنة مفوترة.');
            $type=DB::table('expense_types')->where('id',(int)$d['expense_type_id'])->where('is_active',1)->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->first();
            if(!$type)throw new \RuntimeException('نوع التكلفة غير صالح.');
            $baseCurrency=$this->money->baseCurrency($cid);$currency=strtoupper((string)($d['currency_code']??$baseCurrency));$foreign=round((float)$d['amount'],3);if($foreign<=0)throw new \RuntimeException('مبلغ التكلفة يجب أن يكون أكبر من صفر.');
            $rate=isset($d['exchange_rate'])&&$d['exchange_rate']!==''?(float)$d['exchange_rate']:$this->money->rate($cid,$currency,$d['expense_date']);if($rate<=0)throw new \RuntimeException('سعر الصرف غير صالح.');$base=round($foreign*$rate,3);
            $paid=strtoupper((string)($d['payment_status']??'UNPAID'))==='PAID';$faId=isset($d['financial_account_id'])&&(int)$d['financial_account_id']>0?(int)$d['financial_account_id']:null;
            if($paid&&!$faId)throw new \RuntimeException('اختر الصندوق/البنك عند تسجيل تكلفة مدفوعة.');
            if($faId){$fa=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$faId)->where('is_active',1)->where(fn($q)=>$q->where('branch_id',$bid)->orWhereNull('branch_id'))->first();if(!$fa)throw new \RuntimeException('الحساب المالي غير صالح لهذا الفرع.');$this->money->assertCurrency($fa,$currency);}
            return DB::table('shipment_costs')->insertGetId([
                'company_id'=>$cid,'branch_id'=>$bid,'shipment_id'=>$sid,'expense_type_id'=>(int)$d['expense_type_id'],'expense_date'=>$d['expense_date'],
                'amount'=>$base,'foreign_amount'=>$foreign,'currency_code'=>$currency,'exchange_rate'=>$rate,'payment_status'=>$paid?'PAID':'UNPAID','payment_method'=>$d['payment_method']??null,'financial_account_id'=>$faId,
                'capitalizable'=>isset($d['capitalizable'])?(int)(bool)$d['capitalizable']:(int)($type->affects_cost??1),'payee_type'=>$d['payee_type']??null,'payee_id'=>$d['payee_id']??null,'cost_code'=>$type->type_code??null,'cost_status'=>'DRAFT','distributed'=>0,
                'notes'=>$d['notes']??null,'created_by'=>$d['created_by']??null,'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
    }

    public function updateDraft(int $cid,int $id,array $d,?int $branchFilter=null): void
    {
        DB::transaction(function()use($cid,$id,$d,$branchFilter){
            $c=DB::table('shipment_costs')->where('company_id',$cid)->where('id',$id)->lockForUpdate()->first();if(!$c)throw new \RuntimeException('التكلفة غير موجودة.');if($branchFilter!==null&&(int)$c->branch_id!==$branchFilter)throw new \RuntimeException('التكلفة خارج نطاق فرعك.');if(($c->cost_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('التكلفة المرحلة لا تعدل مباشرة.');
            $typeId=(int)($d['expense_type_id']??$c->expense_type_id);$type=DB::table('expense_types')->where('id',$typeId)->where('is_active',1)->where(fn($q)=>$q->where('company_id',$cid)->orWhereNull('company_id'))->first();if(!$type)throw new \RuntimeException('نوع التكلفة غير صالح.');
            $baseCurrency=$this->money->baseCurrency($cid);$currency=strtoupper((string)($d['currency_code']??$c->currency_code??$baseCurrency));$foreign=round((float)($d['amount']??$c->foreign_amount??$c->amount),3);$date=$d['expense_date']??$c->expense_date;$rate=isset($d['exchange_rate'])&&$d['exchange_rate']!==''?(float)$d['exchange_rate']:$this->money->rate($cid,$currency,$date);$base=round($foreign*$rate,3);if($base<=0)throw new \RuntimeException('المبلغ غير صالح.');
            $status=strtoupper((string)($d['payment_status']??$c->payment_status??'UNPAID'));$faId=isset($d['financial_account_id'])&&$d['financial_account_id']!==''?(int)$d['financial_account_id']:(int)($c->financial_account_id??0);if($status==='PAID'&&!$faId)throw new \RuntimeException('اختر الصندوق/البنك عند تسجيل تكلفة مدفوعة.');
            if($faId){$fa=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$faId)->where('is_active',1)->where(fn($q)=>$q->where('branch_id',(int)$c->branch_id)->orWhereNull('branch_id'))->first();if(!$fa)throw new \RuntimeException('الحساب المالي غير صالح لهذا الفرع.');$this->money->assertCurrency($fa,$currency);}
            DB::table('shipment_costs')->where('id',$id)->update(['expense_type_id'=>$typeId,'cost_code'=>$type->type_code??$c->cost_code,'expense_date'=>$date,'amount'=>$base,'foreign_amount'=>$foreign,'currency_code'=>$currency,'exchange_rate'=>$rate,'payment_status'=>$status,'payment_method'=>$d['payment_method']??$c->payment_method,'financial_account_id'=>$faId?:null,'capitalizable'=>isset($d['capitalizable'])?(int)(bool)$d['capitalizable']:$c->capitalizable,'payee_type'=>$d['payee_type']??$c->payee_type,'payee_id'=>$d['payee_id']??$c->payee_id,'notes'=>$d['notes']??$c->notes,'updated_at'=>now()]);
        });
    }

    public function deleteDraft(int $cid,int $id,?int $branchFilter=null): void
    {$c=DB::table('shipment_costs')->where('company_id',$cid)->where('id',$id)->first();if(!$c)throw new \RuntimeException('التكلفة غير موجودة.');if($branchFilter!==null&&(int)$c->branch_id!==$branchFilter)throw new \RuntimeException('التكلفة خارج نطاق فرعك.');if(($c->cost_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('لا يمكن حذف تكلفة مرحلة.');DB::table('shipment_costs')->where('id',$id)->delete();}

    public function postPendingForShipment(int $cid,int $sid,int $uid): array
    {
        return DB::transaction(function()use($cid,$sid,$uid){
            $s=DB::table('shipments')->where('company_id',$cid)->where('id',$sid)->lockForUpdate()->first();if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
            $costs=DB::table('shipment_costs')->where('company_id',$cid)->where('shipment_id',$sid)->where('cost_status','DRAFT')->orderBy('id')->lockForUpdate()->get();$posted=[];
            foreach($costs as$c){
                $base=round((float)$c->amount,3);if($base<=0)continue;$paid=strtoupper((string)$c->payment_status)==='PAID';$fa=null;
                if($paid){$fa=DB::table('financial_accounts')->where('company_id',$cid)->where('id',$c->financial_account_id)->where('is_active',1)->where(fn($q)=>$q->where('branch_id',(int)$s->branch_id)->orWhereNull('branch_id'))->first();if(!$fa)throw new \RuntimeException('الحساب المالي لتكلفة الشحنة غير صالح لهذا الفرع.');$this->money->assertCurrency($fa,(string)($c->currency_code?:$this->money->baseCurrency($cid)));}
                $creditAcc=$paid?(int)$fa->gl_account_id:$this->support->setting($cid,'ACCRUED_EXPENSE_ACCOUNT');$baseCurrency=$this->money->baseCurrency($cid);$foreign=(float)($c->foreign_amount??$c->amount);$currency=$c->currency_code?:$baseCurrency;$rate=(float)($c->exchange_rate?:1);
                $debits=(int)$c->capitalizable===1?$this->capitalizedDebitLines($cid,$sid,$base,$foreign,$currency,$baseCurrency,$rate):[
                    ['account_id'=>(int)(DB::table('expense_types')->where('id',$c->expense_type_id)->value('account_id')?:$this->support->setting($cid,'GENERAL_EXPENSE_ACCOUNT')),'debit'=>$base,'credit'=>0,'description'=>'مصروف تشغيلي للشحنة','currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>$currency!==$baseCurrency?$foreign:0,'foreign_credit'=>0]
                ];
                $credit=['account_id'=>$creditAcc,'debit'=>0,'credit'=>$base,'description'=>$paid?'سداد تكلفة شحنة':'تكلفة شحنة مستحقة','currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>0,'foreign_credit'=>$currency!==$baseCurrency?$foreign:0];if($fa)$credit['financial_account_id']=(int)$fa->id;if($c->payee_type&&$c->payee_id){$credit['party_type']=$c->payee_type;$credit['party_id']=$c->payee_id;}
                $jid=app(AccountingService::class)->post(['company_id'=>$cid,'branch_id'=>(int)$s->branch_id,'entry_date'=>$c->expense_date,'source_type'=>'SHIPMENT_COST','source_id'=>$c->id,'description'=>((int)$c->capitalizable===1?'رسملة':'مصروف').' تكلفة الشحنة '.$s->shipment_number,'currency_code'=>$currency,'exchange_rate'=>$rate,'lines'=>array_merge($debits,[$credit]), 'is_system_generated'=>1,'created_by'=>$uid]);
                $voucher=$paid?$this->voucher((object)$c,$s,$uid,(int)$fa->id,(int)$fa->gl_account_id,$currency,$rate,$foreign):null;
                DB::table('shipment_costs')->where('id',$c->id)->update(['cost_status'=>'POSTED','journal_entry_id'=>$jid,'voucher_id'=>$voucher,'posted_at'=>now(),'posted_by'=>$uid,'distributed'=>(int)$c->capitalizable===1?0:1,'updated_at'=>now()]);$posted[]=['id'=>(int)$c->id,'journal_entry_id'=>$jid,'voucher_id'=>$voucher];
            }
            $dist=$this->distribution->distributeByShipmentId($cid,$sid);
            return ['posted'=>$posted,'distribution'=>$dist];
        });
    }

    public function summary(int $cid,?int $bid,int $sid): array
    {
        $q=DB::table('shipments')->where('company_id',$cid)->where('id',$sid);if($bid!==null)$q->where('branch_id',$bid);$s=$q->first();if(!$s)throw new \RuntimeException('الشحنة غير موجودة.');
        $costs=DB::table('shipment_costs as sc')->leftJoin('expense_types as t','t.id','=','sc.expense_type_id')->leftJoin('financial_accounts as fa','fa.id','=','sc.financial_account_id')->where('sc.company_id',$cid)->where('sc.shipment_id',$sid)->select('sc.*','t.type_name','t.type_code','fa.account_name as financial_account_name')->orderByDesc('sc.id')->get();
        $sum=round((float)$costs->sum('amount'),3);$kg=round((float)($s->accepted_weight_kg??0),3);
        return ['shipment'=>$s,'costs'=>$costs,'total_costs'=>$sum,'draft_costs'=>round((float)$costs->where('cost_status','DRAFT')->sum('amount'),3),'posted_costs'=>round((float)$costs->where('cost_status','POSTED')->sum('amount'),3),'total_weight_kg'=>$kg,'cost_per_kg'=>$kg>0?round($sum/$kg,6):0,'cost_per_ton'=>$kg>0?round(($sum/$kg)*1000,3):0];
    }

    private function capitalizedDebitLines(int $companyId,int $shipmentId,float $base,float $foreign,string $currency,string $baseCurrency,float $rate): array
    {
        $shipment=DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->first();
        $rows=DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->where('accepted_qty_kg','>',0)->get(['item_id','accepted_qty_kg','base_cost','cost_share_percent','manual_allocated_cost']);
        if(!$shipment||$rows->isEmpty())throw new \RuntimeException('لا يمكن رسملة تكلفة الشحنة قبل تحديد أصنافها وكمياتها المقبولة.');
        $method=strtoupper((string)($shipment->cost_allocation_method??'WEIGHT'));$raw=[];$rawTotal=0.0;
        foreach($rows as$r){$v=match($method){'RELATIVE_VALUE'=>(float)($r->base_cost??0),'MANUAL_PERCENT'=>(float)($r->cost_share_percent??0),'MANUAL_COST'=>(float)($r->manual_allocated_cost??0),default=>(float)($r->accepted_qty_kg??0)};$v=max(0,$v);$raw[]=['row'=>$r,'basis'=>$v];$rawTotal+=$v;}
        if($rawTotal<=0){$rawTotal=0;foreach($raw as&$x){$x['basis']=max(0,(float)$x['row']->accepted_qty_kg);$rawTotal+=$x['basis'];}unset($x);}
        if($rawTotal<=0)throw new \RuntimeException('لا يوجد أساس صالح لتوزيع تكلفة الشحنة على حسابات المخزون.');

        // First distribute exactly like ShipmentCostDistributionService, then aggregate by resolved inventory account.
        $byAccount=[];$remainBase=$base;$remainForeign=$foreign;$lastIndex=count($raw)-1;
        foreach($raw as$i=>$x){$b=$i===$lastIndex?$remainBase:round($base*($x['basis']/$rawTotal),3);$f=$i===$lastIndex?$remainForeign:round($foreign*($x['basis']/$rawTotal),3);$remainBase=round($remainBase-$b,3);$remainForeign=round($remainForeign-$f,3);$acc=$this->itemAccounts->inventory($companyId,(int)$x['row']->item_id);if(!isset($byAccount[$acc]))$byAccount[$acc]=['base'=>0.0,'foreign'=>0.0];$byAccount[$acc]['base']=round($byAccount[$acc]['base']+$b,3);$byAccount[$acc]['foreign']=round($byAccount[$acc]['foreign']+$f,3);}
        $lines=[];foreach($byAccount as$acc=>$x)$lines[]=['account_id'=>(int)$acc,'debit'=>$x['base'],'credit'=>0,'description'=>'رسملة تكلفة مباشرة على مخزون الأصناف — '.$method,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>$currency!==$baseCurrency?$x['foreign']:0,'foreign_credit'=>0];
        return $lines;
    }

    private function voucher(object $c,object $s,int $uid,int $faId,int $glId,string $currency,float $rate,float $foreign): int
    {
        $voucherTypeId=DB::table('voucher_types')->where('type_code','PAYMENT')->value('id')?:DB::table('voucher_types')->where('id',2)->value('id');if(!$voucherTypeId)throw new \RuntimeException('نوع سند الصرف غير معرف.');$voucherNo=$this->sequences->next((int)$c->company_id,(int)$s->branch_id,'PAYMENT_VOUCHER',(string)$c->expense_date,'PAY');
        return DB::table('vouchers')->insertGetId(['company_id'=>$c->company_id,'branch_id'=>$s->branch_id,'voucher_type_id'=>$voucherTypeId,'voucher_number'=>$voucherNo,'voucher_date'=>$c->expense_date,'reference_type'=>'SHIPMENT_COST','reference_id'=>$c->id,'amount'=>$c->amount,'foreign_amount'=>$foreign,'payment_method'=>$c->payment_method,'financial_account_id'=>$faId,'cash_account_id'=>$glId,'currency_code'=>$currency,'exchange_rate'=>$rate,'notes'=>'تكلفة الشحنة '.$s->shipment_number,'created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);
    }
}
