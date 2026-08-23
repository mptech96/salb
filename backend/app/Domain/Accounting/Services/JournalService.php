<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Repositories\JournalRepository;
use App\Services\Accounting\PostingSupport;
use Illuminate\Support\Facades\DB;

class JournalService
{
    public function __construct(private JournalRepository $journals,private PostingSupport $support){}

    public function post(array $data): int
    {
        return DB::transaction(function()use($data){
            $companyId=(int)($data['company_id']??0);$branchId=isset($data['branch_id'])&&(int)$data['branch_id']>0?(int)$data['branch_id']:null;
            $allowCompanyLevel=(bool)($data['allow_company_level']??false);$date=trim((string)($data['entry_date']??''));$lines=$data['lines']??[];$description=trim((string)($data['description']??''));
            if(!$companyId||!$date)throw new \RuntimeException('الشركة وتاريخ القيد مطلوبان.');
            if(!$branchId&&!$allowCompanyLevel)throw new \RuntimeException('اختر الفرع الذي يخص القيد.');
            if($branchId)$this->assertBranch($companyId,$branchId);
            $fy=DB::table('financial_years')->where('company_id',$companyId)->whereDate('start_date','<=',$date)->whereDate('end_date','>=',$date)->orderByDesc('start_date')->lockForUpdate()->first();
            if(!$fy)throw new \RuntimeException('لا توجد سنة مالية تغطي تاريخ القيد.');
            if((int)$fy->is_closed===1&&!($data['allow_closed_year']??false))throw new \RuntimeException('السنة المالية مقفلة ولا تقبل قيودًا جديدة.');
            if(count($lines)<2)throw new \RuntimeException('القيد يجب أن يحتوي على طرفين على الأقل.');
            if(count($lines)>100)throw new \RuntimeException('الحد الأعلى للقيد هو 100 سطر.');
            if(($data['source_type']??'MANUAL')==='MANUAL'&&mb_strlen($description)<3)throw new \RuntimeException('اكتب بيانًا واضحًا للقيد.');

            $totalDebit=0.0;$totalCredit=0.0;
            foreach($lines as $i=>&$line){
                $lineBranch=isset($line['branch_id'])&&(int)$line['branch_id']>0?(int)$line['branch_id']:$branchId;
                if($lineBranch)$this->assertBranch($companyId,$lineBranch);
                elseif(!$allowCompanyLevel)throw new \RuntimeException('الفرع غير محدد في السطر رقم '.($i+1).'.');
                $line['branch_id']=$lineBranch;

                $account=DB::table('accounts')->where('id',(int)($line['account_id']??0))->where('company_id',$companyId)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();
                if(!$account)throw new \RuntimeException('الحساب في السطر رقم '.($i+1).' غير صالح للترحيل.');
                $debit=round((float)($line['debit']??0),3);$credit=round((float)($line['credit']??0),3);
                if($debit<0||$credit<0)throw new \RuntimeException('لا تقبل المبالغ السالبة في السطر رقم '.($i+1).'.');
                if($debit>0&&$credit>0)throw new \RuntimeException('السطر رقم '.($i+1).' لا يمكن أن يكون مدينًا ودائنًا معًا.');
                if($debit==0&&$credit==0)throw new \RuntimeException('السطر رقم '.($i+1).' يجب أن يحتوي على مبلغ مدين أو دائن.');

                if((int)$account->allow_cost_center===1){
                    $cc=(int)($line['cost_center_id']??0);if(!$cc&&$lineBranch)$cc=(int)($this->support->branchCostCenter($companyId,$lineBranch)??0);
                    if($cc){$valid=DB::table('cost_centers')->where('id',$cc)->where('company_id',$companyId)->where('is_active',1)->when($lineBranch,fn($q)=>$q->where(function($x)use($lineBranch){$x->whereNull('branch_id')->orWhere('branch_id',$lineBranch);}))->exists();if(!$valid)throw new \RuntimeException('مركز التكلفة غير صالح في السطر رقم '.($i+1).'.');$line['cost_center_id']=$cc;}
                    elseif(!$allowCompanyLevel)throw new \RuntimeException('الحساب في السطر رقم '.($i+1).' يتطلب مركز تكلفة.');
                }else$line['cost_center_id']=null;

                if(!empty($line['party_type'])&&empty($line['party_id']))throw new \RuntimeException('الطرف المحاسبي غير مكتمل في السطر رقم '.($i+1).'.');
                if(!empty($line['financial_account_id'])){
                    $fa=DB::table('financial_accounts')->where('company_id',$companyId)->where('id',(int)$line['financial_account_id'])->where('is_active',1)->first();
                    if(!$fa)throw new \RuntimeException('الخزينة/الحساب المالي في السطر رقم '.($i+1).' غير صالح.');
                    if((int)$fa->gl_account_id!==(int)$account->id)throw new \RuntimeException('الخزينة في السطر رقم '.($i+1).' لا تطابق حساب الأستاذ.');
                    if($fa->branch_id!==null&&$lineBranch&&(int)$fa->branch_id!==$lineBranch)throw new \RuntimeException('الخزينة في السطر رقم '.($i+1).' تخص فرعًا آخر.');
                }
                if(!empty($line['counterparty_branch_id']))$this->assertBranch($companyId,(int)$line['counterparty_branch_id']);
                $line['currency_code']=!empty($line['currency_code'])?strtoupper((string)$line['currency_code']):($data['currency_code']??null);
                $line['exchange_rate']=$line['exchange_rate']??($data['exchange_rate']??null);
                $totalDebit+=$debit;$totalCredit+=$credit;
            }unset($line);
            $totalDebit=round($totalDebit,3);$totalCredit=round($totalCredit,3);if($totalDebit<=0)throw new \RuntimeException('قيمة القيد يجب أن تكون أكبر من صفر.');
            if(abs($totalDebit-$totalCredit)>0.0001)throw new \RuntimeException('القيد غير متوازن. المدين: '.number_format($totalDebit,3).'، الدائن: '.number_format($totalCredit,3));

            $defaultCc=$this->support->branchCostCenter($companyId,$branchId);$number=$data['entry_number']??$this->journals->nextEntryNumber($companyId,(int)$fy->id,$date);
            $entryId=$this->journals->createEntry(['company_id'=>$companyId,'branch_id'=>$branchId,'financial_year_id'=>$fy->id,'cost_center_id'=>$data['cost_center_id']??$defaultCc,
                'entry_number'=>$number,'reference_no'=>trim((string)($data['reference_no']??''))?:null,'entry_date'=>$date,'source_type'=>$data['source_type']??'MANUAL','source_id'=>$data['source_id']??null,
                'reversal_of_id'=>$data['reversal_of_id']??null,'description'=>$description?:null,'status'=>'POSTED','currency_code'=>isset($data['currency_code'])?strtoupper((string)$data['currency_code']):null,
                'exchange_rate'=>$data['exchange_rate']??null,'is_closing_entry'=>$data['is_closing_entry']??0,'is_system_generated'=>$data['is_system_generated']??0,
                'created_by'=>$data['created_by']??request()->attributes->get('authenticated_user_id')]);
            $this->journals->createLines($entryId,$companyId,$branchId,(int)$fy->id,$lines);return$entryId;
        });
    }

    public function reverse(int $companyId,int $entryId,array $context=[]): int
    {
        return DB::transaction(function()use($companyId,$entryId,$context){
            $data=$this->journals->findWithLines($companyId,$entryId,null);if(!$data)throw new \RuntimeException('القيد المطلوب عكسه غير موجود.');$entry=$data['entry'];
            if($entry->reversed_at)throw new \RuntimeException('تم عكس هذا القيد مسبقًا.');if($entry->reversal_of_id)throw new \RuntimeException('لا يمكن عكس قيد عكسي من هذه الشاشة.');
            $reason=trim((string)($context['reason']??''));if(mb_strlen($reason)<5)throw new \RuntimeException('سبب العكس مطلوب ويجب أن يكون واضحًا.');
            $date=(string)($context['entry_date']??$entry->entry_date);if($date<$entry->entry_date)throw new \RuntimeException('تاريخ القيد العكسي لا يمكن أن يسبق تاريخ القيد الأصلي.');
            $lines=[];foreach($data['lines']as$l)$lines[]=['branch_id'=>$l->branch_id,'account_id'=>$l->account_id,'cost_center_id'=>$l->cost_center_id,'financial_account_id'=>$l->financial_account_id,
                'counterparty_branch_id'=>$l->counterparty_branch_id,'party_type'=>$l->party_type,'party_id'=>$l->party_id,'currency_code'=>$l->currency_code,'foreign_debit'=>(float)$l->foreign_credit,
                'foreign_credit'=>(float)$l->foreign_debit,'exchange_rate'=>$l->exchange_rate,'debit'=>(float)$l->credit,'credit'=>(float)$l->debit,'description'=>'عكس: '.($l->description??'')];
            $rev=$this->post(['company_id'=>$companyId,'branch_id'=>$entry->branch_id,'entry_date'=>$date,'reference_no'=>'REV-'.$entry->entry_number,'source_type'=>$context['source_type']??'REVERSAL',
                'source_id'=>$entryId,'reversal_of_id'=>$entryId,'description'=>'عكس القيد '.$entry->entry_number.' — السبب: '.$reason,'lines'=>$lines,'allow_company_level'=>$entry->branch_id===null,
                'allow_closed_year'=>$context['allow_closed_year']??false,'is_closing_entry'=>$context['is_closing_entry']??$entry->is_closing_entry,'is_system_generated'=>1,'created_by'=>$context['created_by']??null,
                'currency_code'=>$entry->currency_code??null,'exchange_rate'=>$entry->exchange_rate??null]);
            DB::table('journal_entries')->where('company_id',$companyId)->where('id',$entryId)->update(['reversed_by_id'=>$rev,'reversed_at'=>now(),'reversal_reason'=>$reason,'updated_at'=>now()]);return$rev;
        });
    }

    public function show(int $companyId,int $entryId,?int $branchId=null){return$this->journals->findWithLines($companyId,$entryId,$branchId);}
    private function assertBranch(int $companyId,int $branchId): void{if(!DB::table('branches')->where('id',$branchId)->where('company_id',$companyId)->where('is_active',1)->exists())throw new \RuntimeException('الفرع غير موجود أو لا يتبع الشركة.');}
}
