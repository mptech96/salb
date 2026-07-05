<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipment_date' => 'required|date',
            'shipment_number' => 'nullable|string|max:100',

            'supplier_id' => 'required|integer',
            'driver_id' => 'nullable|integer',
            'car_id' => 'nullable|integer',

            'plate_number' => 'nullable|string|max:100',
            'weight_card_number' => 'nullable|string|max:100',

            'transport_cost' => 'nullable|numeric|min:0',
            'extra_cost' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'vat_percent' => 'nullable|numeric|min:0|max:100',

            'notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.gross_weight' => 'nullable|numeric|min:0',
            'items.*.tare_weight' => 'nullable|numeric|min:0',
            'items.*.deduction_weight' => 'nullable|numeric|min:0',
            'items.*.net_weight' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'shipment_date.required' => 'تاريخ الحمولة مطلوب',
            'supplier_id.required' => 'المورد مطلوب',
            'items.required' => 'يجب إضافة صنف واحد على الأقل',
            'items.min' => 'يجب إضافة صنف واحد على الأقل',
            'items.*.item_id.required' => 'الصنف مطلوب في كل سطر',
        ];
    }
}