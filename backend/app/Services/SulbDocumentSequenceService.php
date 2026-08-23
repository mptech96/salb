<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class SulbDocumentSequenceService
{
    public function next(int $companyId, ?int $branchId, string $type, string $date, string $prefix): string
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
