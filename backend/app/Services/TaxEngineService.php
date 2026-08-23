<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TaxEngineService
{
    public function resolve(int $companyId, ?int $taxCodeId, string $date, string $mode='SALES'): ?object
    {
        if(!$taxCodeId){$key=strtoupper($mode)==='PURCHASE'?'default_purchase_tax_code_id':'default_sales_tax_code_id';$taxCodeId=(int)(DB::table('company_settings')->where('company_id',$companyId)->value($key)??0);}
        if(!$taxCodeId)return null;
        $tax=DB::table('tax_codes')->where('company_id',$companyId)->where('id',$taxCodeId)->where('is_active',1)
            ->where(function($q)use($date){$q->whereNull('valid_from')->orWhereDate('valid_from','<=',$date);})
            ->where(function($q)use($date){$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$date);})->first();
        if(!$tax)throw new \RuntimeException('كود الضريبة غير صالح لتاريخ المستند.');
        return $tax;
    }

    public function line(int $companyId,float $gross,float $discount,?int $taxCodeId,string $date,string $mode='SALES',?float $legacyRate=null): array
    {
        $tax=$this->resolve($companyId,$taxCodeId,$date,$mode); $taxable=max(0,round($gross-$discount,3));
        $inclusive=(bool)(DB::table('company_settings')->where('company_id',$companyId)->value('tax_inclusive_prices')??0);
        if($tax){$rate=($tax->is_exempt||$tax->is_out_of_scope)?0:(float)$tax->rate;$code=$tax->tax_code;$name=$tax->tax_name;$id=(int)$tax->id;}
        else{$rate=max(0,(float)($legacyRate??0));$code=$rate>0?'LEGACY_RATE':'OUT_SCOPE';$name=$rate>0?'ضريبة بالنسبة المخزنة':'خارج النطاق';$id=null;}
        if($inclusive && $rate>0){$before=round($taxable/(1+$rate/100),3);$amount=round($taxable-$before,3);$after=$taxable;}
        else{$before=$taxable;$amount=round($before*$rate/100,3);$after=round($before+$amount,3);}
        return ['tax_code_id'=>$id,'tax_code_snapshot'=>$code,'tax_name_snapshot'=>$name,'tax_rate_snapshot'=>$rate,'total_before_vat'=>$before,'vat_amount'=>$amount,'total_after_vat'=>$after,'line_total'=>$after];
    }

    public function summary(array $lines): array
    {
        $groups=[]; foreach($lines as $line){$code=(string)($line['tax_code_snapshot']??'OUT_SCOPE');if(!isset($groups[$code]))$groups[$code]=['tax_code'=>$code,'tax_name'=>$line['tax_name_snapshot']??$code,'rate'=>(float)($line['tax_rate_snapshot']??0),'taxable'=>0,'tax'=>0];$groups[$code]['taxable']+=round((float)($line['total_before_vat']??0),3);$groups[$code]['tax']+=round((float)($line['vat_amount']??0),3);}
        return array_values(array_map(function($g){$g['taxable']=round($g['taxable'],3);$g['tax']=round($g['tax'],3);return $g;},$groups));
    }

    public function taxAccount(int $companyId, ?int $taxCodeId, string $mode): int
    {
        if($taxCodeId){$col=strtoupper($mode)==='PURCHASE'?'purchase_tax_account_id':'sales_tax_account_id';$id=DB::table('tax_codes')->where('company_id',$companyId)->where('id',$taxCodeId)->value($col);if($id)return(int)$id;}
        $key=strtoupper($mode)==='PURCHASE'?'VAT_INPUT_ACCOUNT':'VAT_OUTPUT_ACCOUNT';$id=DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key',$key)->value('account_id');
        if(!$id)throw new \RuntimeException('حساب الضريبة الافتراضي غير مضبوط.'); return(int)$id;
    }
}
