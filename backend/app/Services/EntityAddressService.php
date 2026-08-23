<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EntityAddressService
{
    public function upsertDefault(int $companyId,string $entityType,int $entityId,array $data): void
    {
        $entityType=strtoupper($entityType);
        $address=[
            'country_code'=>$this->clean($data['country_code']??null,2,true),'short_address'=>$this->clean($data['short_address']??null,100),
            'building_no'=>$this->clean($data['building_no']??null,50),'street_name'=>$this->clean($data['street_name']??null,200),
            'district'=>$this->clean($data['district']??null,150),'city'=>$this->clean($data['city']??null,150),
            'state_region'=>$this->clean($data['state_region']??null,150),'postal_code'=>$this->clean($data['postal_code']??null,50),
            'additional_no'=>$this->clean($data['additional_no']??null,50),'unit_no'=>$this->clean($data['unit_no']??null,50),
            'address_line1'=>$this->clean($data['address_line1']??$data['address']??null,500),'address_line2'=>$this->clean($data['address_line2']??null,500),
            'address_type'=>'LEGAL','is_default'=>1,'is_active'=>1,'updated_at'=>now()
        ];
        $existing=DB::table('entity_addresses')->where('company_id',$companyId)->where('entity_type',$entityType)->where('entity_id',$entityId)->where('is_default',1)->first();
        if ($existing) DB::table('entity_addresses')->where('id',$existing->id)->update($address);
        else DB::table('entity_addresses')->insert(['company_id'=>$companyId,'entity_type'=>$entityType,'entity_id'=>$entityId,'created_at'=>now(),...$address]);
    }

    public function getDefault(int $companyId,string $entityType,int $entityId): ?object
    {
        return DB::table('entity_addresses')->where('company_id',$companyId)->where('entity_type',strtoupper($entityType))->where('entity_id',$entityId)->where('is_active',1)->orderByDesc('is_default')->orderByDesc('id')->first();
    }

    public function snapshotCompanyAndBranch(int $companyId, ?int $branchId): array
    {
        $company=DB::table('companies')->where('id',$companyId)->first(); $branch=$branchId?DB::table('branches')->where('company_id',$companyId)->where('id',$branchId)->first():null;
        $settings=DB::table('company_settings')->where('company_id',$companyId)->first();
        return [
            'company_id'=>$companyId,'branch_id'=>$branchId,'company_name'=>$company->legal_name ?: ($company->company_name??null),'trade_name'=>$company->company_name??null,
            'branch_name'=>$branch ? ($branch->legal_name ?: $branch->branch_name) : null,
            'registration_number'=>$branch?($branch->registration_number ?: ($company->registration_number ?: ($settings->commercial_register??null))):($company->registration_number ?: ($settings->commercial_register??null)),
            'tax_number'=>$branch?($branch->tax_number ?: ($company->tax_number ?: ($settings->tax_number??null))):($company->tax_number ?: ($settings->tax_number??null)),
            'phone'=>$branch?($branch->phone ?: ($company->phone??($settings->print_phone??null))):($company->phone??($settings->print_phone??null)),
            'email'=>$branch?($branch->email ?: ($company->email??($settings->print_email??null))):($company->email??($settings->print_email??null)),
            'country_code'=>$branch?($branch->country_code ?: ($company->country_code ?: ($settings->country_code??null))):($company->country_code ?: ($settings->country_code??null)),
            'address'=>$this->toArray($this->getDefault($companyId,$branch?'BRANCH':'COMPANY',$branchId?:$companyId)),
        ];
    }

    public function snapshotParty(int $companyId,string $kind,int $id): array
    {
        $kind=strtoupper($kind); $table=$kind==='SUPPLIER'?'suppliers':'customers'; $name=$kind==='SUPPLIER'?'supplier_name':'customer_name';
        $p=DB::table($table)->where('company_id',$companyId)->where('id',$id)->first(); if(!$p)throw new \RuntimeException(($kind==='SUPPLIER'?'المورد':'العميل').' غير موجود.');
        return ['id'=>$id,'type'=>$kind,'name'=>$p->legal_name ?: $p->{$name},'trade_name'=>$p->{$name},'phone'=>$p->phone??null,'email'=>$p->email??null,
            'registration_number'=>$p->registration_number??null,'tax_number'=>$p->tax_number??null,'country_code'=>$p->country_code??null,'address'=>$this->toArray($this->getDefault($companyId,$kind,$id))];
    }

    private function toArray(?object $row): ?array { return $row ? (array)$row : null; }
    private function clean($v,int $max,bool $upper=false): ?string { $v=trim((string)$v);if($v==='')return null;$v=mb_substr($v,0,$max);return $upper?strtoupper($v):$v; }
}
