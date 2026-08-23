<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(int $companyId,int $branchId,string $family,string $documentType,string $date,?string $preferredPrefix=null): string
    {
        $family=strtoupper(trim($family));$documentType=strtoupper(trim($documentType));$year=(int)date('Y',strtotime($date));
        $branch=DB::table('branches')->where('company_id',$companyId)->where('id',$branchId)->first();
        if(!$branch)throw new \RuntimeException('الفرع المحدد غير صالح لترقيم المستند.');
        $prefix=$this->clean($preferredPrefix ?: match($family){'SALE'=>'SAL','PURCHASE'=>'PUR',default=>$family});
        $branchCode=$this->clean((string)($branch->branch_code ?: 'B'.$branchId));
        DB::table('document_sequences')->updateOrInsert(
            ['company_id'=>$companyId,'branch_id'=>$branchId,'document_family'=>$family,'document_type'=>$documentType,'document_year'=>$year],
            ['prefix'=>$prefix,'created_at'=>now(),'updated_at'=>now()]
        );
        $seq=DB::table('document_sequences')->where('company_id',$companyId)->where('branch_id',$branchId)->where('document_family',$family)->where('document_type',$documentType)->where('document_year',$year)->lockForUpdate()->first();
        $number=max(1,(int)$seq->next_number);
        DB::table('document_sequences')->where('id',$seq->id)->update(['prefix'=>$prefix,'next_number'=>$number+1,'updated_at'=>now()]);
        return sprintf('%s-%s-%04d-%06d',$prefix,$branchCode,$year,$number);
    }

    public function assertManualUnique(int $companyId,string $table,string $number,?int $ignoreId=null): void
    {
        $number=trim($number);if($number==='')return;
        $q=DB::table($table)->where('company_id',$companyId)->where('invoice_number',$number);if($ignoreId)$q->where('id','<>',$ignoreId);
        if($q->exists())throw new \RuntimeException('رقم الفاتورة مستخدم مسبقًا داخل الشركة.');
    }

    private function clean(string $v): string
    {
        $v=strtoupper(trim($v));$v=preg_replace('/[^A-Z0-9\x{0600}-\x{06FF}]+/u','-',$v)?:'';$v=trim($v,'-');return mb_substr($v?:'DOC',0,30);
    }
}
