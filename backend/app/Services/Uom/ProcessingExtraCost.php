<?php
namespace App\Services\Uom;

use Illuminate\Support\Facades\DB;
use App\Services\ShipmentLargestRemainder;

/** Frozen documented operating costs; approval is the existing process-post permission. */
final class ProcessingExtraCost
{
    public static function claim(int $company,int $operation,array $costs):void
    {
        OperationalDocument::ready();
        if(!$costs)return;
        if(!DB::getSchemaBuilder()->hasTable('uom_processing_cost_sources'))throw new \RuntimeException('UOM_PROCESS_COST_SCHEMA_REQUIRED');
        usort($costs,fn($a,$b)=>strcmp($a['source_reference'],$b['source_reference']));
        foreach($costs as $cost){
            // A prior command read may have established an old RR view. This must be a current read.
            if(DB::table('uom_processing_cost_sources')->where('company_id',$company)->where('source_reference',$cost['source_reference'])->lockForUpdate()->first(['id']))throw new \RuntimeException('UOM_PROCESS_COST_SOURCE_ALREADY_USED');
            DB::table('uom_processing_cost_sources')->insert(['company_id'=>$company,'operation_id'=>$operation,'source_reference'=>$cost['source_reference'],'base_amount'=>$cost['base_amount'],'payload_hash'=>hash('sha256',json_encode($cost,JSON_THROW_ON_ERROR)),'created_at'=>now()]);
        }
    }
    public static function capture(int $company,int $branch,array $rows):array
    {
        if(count($rows)>50)throw new \RuntimeException('UOM_PROCESS_COST_LIMIT');$out=[];$math=new ShipmentLargestRemainder;
        $base=DB::table('company_settings')->where('company_id',$company)->value('base_currency_code');
        foreach($rows as $row){
            $type=trim((string)($row['cost_type']??''));$reference=trim((string)($row['source_reference']??''));
            if($type===''||mb_strlen($type)>60||mb_strlen($reference)<3||mb_strlen($reference)>160||!is_string($row['amount']??null))throw new \RuntimeException('UOM_PROCESS_COST_SOURCE_REQUIRED: أدخل نوع التكلفة ومرجع مستندها ومبلغًا عشريًا موثقًا.');
            if(!preg_match('/^\d{1,15}(?:\.\d{1,3})?$/D',$row['amount']))throw new \RuntimeException('UOM_PROCESS_COST_AMOUNT');$amount=$math->decimal($row['amount']);if(bccomp($amount,'0',3)<=0)throw new \RuntimeException('UOM_PROCESS_COST_AMOUNT');
            $currency=strtoupper((string)($row['currency_code']??$base));if(!DB::table('company_currencies')->where('company_id',$company)->where('currency_code',$currency)->where('is_active',1)->exists())throw new \RuntimeException('UOM_PROCESS_COST_CURRENCY');
            if($currency!==$base&&(!is_string($row['exchange_rate']??null)||!preg_match('/^\d{1,14}(?:\.\d{1,10})?$/D',$row['exchange_rate'])))throw new \RuntimeException('UOM_PROCESS_COST_RATE');
            $rate=$currency===$base?'1.0000000000':$math->decimal($row['exchange_rate'],10);
            if(bccomp($rate,'0',10)<=0||($currency===$base&&isset($row['exchange_rate'])&&bccomp((string)$row['exchange_rate'],'1',10)!==0))throw new \RuntimeException('UOM_PROCESS_COST_RATE');
            $value=bcadd(bcmul($amount,$rate,13),'0.0005',3);if(bccomp($value,'0',3)<=0)throw new \RuntimeException('UOM_PROCESS_COST_BASE_AMOUNT');
            $payment=(string)($row['payment_status']??'UNPAID');if(!in_array($payment,['PAID','UNPAID'],true))throw new \RuntimeException('UOM_PROCESS_COST_PAYMENT');
            $financial=$payment==='PAID'?(int)($row['financial_account_id']??0):null;
            if($payment==='UNPAID'&&!empty($row['financial_account_id']))throw new \RuntimeException('UOM_PROCESS_COST_PAYMENT');
            if($payment==='PAID'){$fa=self::financial($company,$branch,$financial,$currency,false);$account=(int)$fa->gl_account_id;}
            else $account=(int)DB::table('accounting_settings')->where('company_id',$company)->where('setting_key','ACCRUED_EXPENSE_ACCOUNT')->value('account_id');
            $accountRow=DB::table('accounts')->where('company_id',$company)->where('id',$account)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();
            if(!$accountRow||($payment==='UNPAID'&&$accountRow->account_type!=='LIABILITY')||($payment==='PAID'&&$accountRow->account_type!=='ASSET'))throw new \RuntimeException('UOM_PROCESS_COST_CREDIT_ACCOUNT');
            $out[]=['cost_type'=>$type,'source_reference'=>$reference,'amount'=>$amount,'currency_code'=>$currency,'exchange_rate'=>$rate,'base_amount'=>$value,'payment_status'=>$payment,'financial_account_id'=>$financial,'credit_account_id'=>$account,'notes'=>(string)($row['notes']??''),'base_currency_code'=>$base,'foreign_credit'=>$currency===$base?'0.000':$amount];
        }return $out;
    }
    private static function financial(int $company,int $branch,int $id,string $currency,bool $lock):object
    {
        $query=DB::table('financial_accounts')->where('company_id',$company)->where('id',$id)->where('is_active',1)->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$branch));
        $row=($lock?$query->lockForUpdate():$query)->first();if(!$row||$row->currency_code!==$currency)throw new \RuntimeException('UOM_PROCESS_COST_FINANCIAL_SCOPE');return $row;
    }
    public static function save(int $company,int $branch,int $operation,int $user,array $costs):void
    {
        OperationalDocument::ready();
        foreach($costs as $cost){unset($cost['source_reference'],$cost['credit_account_id'],$cost['base_currency_code'],$cost['foreign_credit']);DB::table('inventory_operation_costs')->insert(['company_id'=>$company,'branch_id'=>$branch,'operation_id'=>$operation,...$cost,'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()]);}
    }
    /** Run before LOT/item locks; validate the captured payment destination again. */
    public static function verify(int $company,int $branch,int $operation,array $costs,bool $posted=false):array
    {
        OperationalDocument::ready();
        $ids=array_unique(array_filter(array_column($costs,'financial_account_id')));sort($ids,SORT_NUMERIC);
        foreach($ids as $id){$cost=collect($costs)->firstWhere('financial_account_id',$id);$fa=self::financial($company,$branch,(int)$id,$cost['currency_code'],true);if((int)$fa->gl_account_id!==$cost['credit_account_id'])throw new \RuntimeException('UOM_PROCESS_COST_ACCOUNT_CHANGED');}
        $actual=DB::table('inventory_operation_costs')->where('company_id',$company)->where('branch_id',$branch)->where('operation_id',$operation)->orderBy('id')->lockForUpdate()->get();
        if($actual->count()!==count($costs))throw new \RuntimeException('UOM_PROCESS_COST_SNAPSHOT_CHANGED');$sum='0.000';$credits=[];
        foreach($costs as $i=>$cost){if(mb_strlen(trim((string)($cost['source_reference']??'')))<3)throw new \RuntimeException('UOM_PROCESS_COST_SOURCE_REQUIRED');$row=$actual[$i];foreach(['cost_type','amount','currency_code','exchange_rate','base_amount','payment_status','financial_account_id','notes'] as $key){$same=in_array($key,['amount','exchange_rate','base_amount'],true)?bccomp((string)$row->$key,$cost[$key],$key==='exchange_rate'?10:3)===0:(string)$row->$key===(string)$cost[$key];if(!$same)throw new \RuntimeException('UOM_PROCESS_COST_SNAPSHOT_CHANGED');}
            if(!$posted&&($row->posted_at||$row->journal_entry_id))throw new \RuntimeException('UOM_PROCESS_COST_ALREADY_POSTED');
            $sum=bcadd($sum,$cost['base_amount'],3);$credits[]=['account_id'=>$cost['credit_account_id'],'debit'=>0,'credit'=>$cost['base_amount'],'financial_account_id'=>$cost['financial_account_id'],'currency_code'=>$cost['currency_code'],'exchange_rate'=>$cost['exchange_rate'],'foreign_credit'=>$cost['foreign_credit']??$cost['amount'],'description'=>'تكلفة معالجة — '.$cost['source_reference']];
        }return ['amount'=>$sum,'credits'=>$credits];
    }
    public static function posted(int $company,int $operation,int $user,?int $journal):void
    {
        OperationalDocument::ready();
        DB::table('inventory_operation_costs')->where('company_id',$company)->where('operation_id',$operation)->update(['journal_entry_id'=>$journal,'posted_by'=>$user,'posted_at'=>now(),'updated_at'=>now()]);
    }
    public static function audit(int $company,int $operation,array $costs,array $result):void
    {
        if(!$costs)return;$sum='0.000';
        $claims=DB::table('uom_processing_cost_sources')->where('company_id',$company)->where('operation_id',$operation)->get();
        $rows=DB::table('inventory_operation_costs')->where('company_id',$company)->where('operation_id',$operation)->orderBy('id')->get();
        if($claims->count()!==count($costs)||$rows->count()!==count($costs))throw new \RuntimeException('UOM_PROCESS_COST_HISTORY_REQUIRED');
        foreach($costs as $i=>$cost){$claim=$claims->firstWhere('source_reference',$cost['source_reference']);$row=$rows[$i];
            if(!$claim||!hash_equals($claim->payload_hash,hash('sha256',json_encode($cost,JSON_THROW_ON_ERROR)))||bccomp($claim->base_amount,$cost['base_amount'],3)||(int)$row->journal_entry_id!==(int)$result['journal_entry_id']||!$row->posted_at||bccomp($row->base_amount,$cost['base_amount'],3))throw new \RuntimeException('UOM_PROCESS_COST_HISTORY_REQUIRED');$sum=bcadd($sum,$cost['base_amount'],3);
        }
        if(bccomp($result['operation_overhead_cost']??'-1',$sum,3)||bccomp($result['total_output_cost']??'-1',bcadd($result['input_inventory_cost'],$sum,3),3))throw new \RuntimeException('UOM_PROCESS_COST_HISTORY_AMOUNT');
    }
}
