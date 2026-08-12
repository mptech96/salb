<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreShipmentRequest extends FormRequest
{
 public function authorize():bool{return true;}
 public function rules():array{return ['branch_id'=>'nullable|integer','shipment_date'=>'required|date','shipment_number'=>'nullable|string|max:100','supplier_id'=>'required|integer','driver_id'=>'nullable|integer','car_id'=>'required|integer','vat_percent'=>'nullable|numeric|min:0|max:100','cost_allocation_method'=>'nullable|in:RELATIVE_VALUE,WEIGHT,MANUAL_PERCENT,MANUAL_COST','notes'=>'nullable|string','items'=>'nullable|array','items.*.item_id'=>'required_with:items|integer','items.*.qty_kg'=>'nullable|numeric|min:0','items.*.unit_price'=>'nullable|numeric|min:0','items.*.discount_amount'=>'nullable|numeric|min:0','items.*.vat_percent'=>'nullable|numeric|min:0|max:100','items.*.cost_share_percent'=>'nullable|numeric|min:0|max:100','items.*.manual_allocated_cost'=>'nullable|numeric|min:0','items.*.notes'=>'nullable|string'];}
}
