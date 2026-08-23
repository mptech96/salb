<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreShipmentCostRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'shipment_id'=>'required|integer','expense_type_id'=>'required|integer','expense_date'=>'required|date','amount'=>'required|numeric|min:0.001',
            'payment_status'=>'nullable|in:PAID,UNPAID','payment_method'=>'nullable|in:CASH,BANK,BANK_TRANSFER,CARD,WALLET',
            'financial_account_id'=>'nullable|integer','currency_code'=>'nullable|string|max:10','exchange_rate'=>'nullable|numeric|min:0.0000000001',
            'beneficiary_type'=>'nullable|in:SUPPLIER,DRIVER,CARRIER,WORKER,OTHER',
            'beneficiary_id'=>'nullable|integer','beneficiary_name'=>'nullable|string|max:255',
            'allocation_method'=>'nullable|in:RELATIVE_VALUE,WEIGHT,MANUAL_PERCENT,MANUAL_COST',
            'notes'=>'nullable|string',
        ];
    }

    public function messages(): array
    {
        return ['shipment_id.required'=>'يجب اختيار الحمولة.','expense_type_id.required'=>'يجب اختيار نوع التكلفة.','expense_date.required'=>'يجب إدخال تاريخ التكلفة.','amount.required'=>'يجب إدخال مبلغ التكلفة.','amount.min'=>'مبلغ التكلفة يجب أن يكون أكبر من صفر.','payment_status.in'=>'حالة الدفع غير صحيحة.','payment_method.in'=>'طريقة الدفع غير صحيحة.'];
    }

    protected function passedValidation(): void
    {
        $companyId=(int)$this->attributes->get('company_id',(int)$this->header('X-Company-ID'));
        $shipment=DB::table('shipments')->where('company_id',$companyId)->where('id',$this->shipment_id)->first();
        if(!$shipment)abort(response()->json(['status'=>false,'message'=>'الحمولة غير موجودة.'],404));
        if($shipment->status!=='APPROVED')abort(response()->json(['status'=>false,'message'=>'لا يمكن إضافة تكلفة إلا على حمولة معتمدة.'],400));
        $expenseType=DB::table('expense_types')->where('id',$this->expense_type_id)->where(fn($q)=>$q->where('company_id',$companyId)->orWhereNull('company_id'))->first();
        if(!$expenseType)abort(response()->json(['status'=>false,'message'=>'نوع التكلفة غير موجود.'],404));
        if(!in_array($expenseType->usage_type??'GENERAL',['SHIPMENT','BOTH'],true))abort(response()->json(['status'=>false,'message'=>'نوع المصروف المختار غير مخصص لتكاليف الحمولات.'],400));
    }
}
