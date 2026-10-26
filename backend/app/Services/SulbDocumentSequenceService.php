<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class SulbDocumentSequenceService
{
    public function next(int $companyId, ?int $branchId, string $type, string $date, string $prefix): string
    {
        $type=strtoupper($type);
        // Older callers of next() must join the same reservation protocol.
        if(in_array($type,['RECEIPT_VOUCHER','PAYMENT_VOUCHER'],true)){
            return $this->reserveVoucher($companyId,$type==='RECEIPT_VOUCHER'?'RECEIPT':'PAYMENT',$date);
        }
        return $this->advance($companyId,$branchId,$type,$date,$prefix);
    }

    public function lockVoucherCompany(int $companyId): void
    {
        if(DB::connection()->transactionLevel()<1)throw new \RuntimeException('Voucher reservation requires the enclosing financial transaction.');
        if($companyId<=0||!DB::table('companies')->where('id',$companyId)->lockForUpdate()->first())throw new \RuntimeException('Valid voucher company required.');
    }

    /** Reservation, insertion and posting must commit or roll back together. */
    public function reserveVoucher(int $companyId,string $type,string $date,?string $manualNumber=null): string
    {
        $this->lockVoucherCompany($companyId);
        $type=strtoupper($type);
        if(!in_array($type,['RECEIPT','PAYMENT'],true))throw new \RuntimeException('Receipt or payment voucher required.');
        // Preserve Laravel's existing `date` contract (including dated timestamps).
        if(!validator(['date'=>$date],['date'=>'required|date'])->passes())throw new \RuntimeException('Valid voucher date required.');
        $date=(new \DateTimeImmutable($date))->format('Y-m-d');
        if($manualNumber!==null&&trim($manualNumber)!==''){
            if(mb_strlen($manualNumber)>100||DB::table('vouchers')->where('company_id',$companyId)->where('voucher_number',$manualNumber)->exists()){
                throw \Illuminate\Validation\ValidationException::withMessages(['voucher_number'=>'رقم السند مستخدم داخل الشركة أو غير صالح.']);
            }
            return $manualNumber;
        }
        for($attempt=0;$attempt<10000;$attempt++){
            $number=$this->advance($companyId,null,$type.'_VOUCHER',$date,$type==='RECEIPT'?'REC':'PAY');
            // This check is protected by the shared company lock, not check-then-insert alone.
            if(!DB::table('vouchers')->where('company_id',$companyId)->where('voucher_number',$number)->exists())return $number;
        }
        throw new \RuntimeException('Existing voucher numbers require sequence reconciliation.');
    }

    private function advance(int $companyId,?int $branchId,string $type,string $date,string $prefix): string
    {
        $year=(int)date('Y',strtotime($date));$bid=$branchId?:0;$type=strtoupper($type);
        return DB::transaction(function()use($companyId,$bid,$type,$year,$prefix){
            $row=DB::table('sulb_document_sequences')->where('company_id',$companyId)->where('branch_id',$bid)->where('document_type',$type)->where('document_year',$year)->lockForUpdate()->first();
            if(!$row){
                try{DB::table('sulb_document_sequences')->insert(['company_id'=>$companyId,'branch_id'=>$bid,'document_type'=>$type,'document_year'=>$year,'next_number'=>2,'created_at'=>now(),'updated_at'=>now()]);$n=1;}
                catch(\Throwable $e){$row=DB::table('sulb_document_sequences')->where('company_id',$companyId)->where('branch_id',$bid)->where('document_type',$type)->where('document_year',$year)->lockForUpdate()->first();if(!$row)throw $e;$n=(int)$row->next_number;DB::table('sulb_document_sequences')->where('id',$row->id)->update(['next_number'=>$n+1,'updated_at'=>now()]);}
            }else{$n=(int)$row->next_number;DB::table('sulb_document_sequences')->where('id',$row->id)->update(['next_number'=>$n+1,'updated_at'=>now()]);}
            return strtoupper($prefix).'-'.$year.'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);
        },3);
    }
}
