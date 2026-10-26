export function shipmentIsBase(line:any,item?:any):boolean {
 return (item?.inventory_quantity_version||line?.uom_version)==="BASE_V1";
}
/** Whitelisted business inputs; the server owns factors, stock links and values. */
export function shipmentLinePayload(line:any,item?:any):Record<string,any> {
 const common={...(line.id?{id:Number(line.id)}:{}),item_id:Number(line.item_id),discount_amount:String(line.discount_amount||"0"),tax_code_id:line.tax_code_id?Number(line.tax_code_id):null,vat_percent:line.vat_percent!==""&&line.vat_percent!=null?Number(line.vat_percent):null,notes:line.notes||"",...(line.cost_share_percent!=null&&line.cost_share_percent!==""?{cost_share_percent:String(line.cost_share_percent)}:{})};
 if(shipmentIsBase(line,item))return {...common,quantity:String(line.quantity??""),unit_code:String(line.unit_code||item?.base_unit_code||""),unit_price:String(line.unit_price??""),price_unit:"UNIT",actual_weight_kg:line.actual_weight_kg==null||line.actual_weight_kg===""?null:String(line.actual_weight_kg)};
 const gross=Number(line.gross_qty_kg||0),deduction=Number(line.deduction_qty_kg||0);
 return {...common,gross_qty_kg:gross,deduction_qty_kg:deduction,accepted_qty_kg:Math.max(0,gross-deduction),deduction_reason:line.deduction_reason||"",price_unit:line.price_unit||"KG",unit_price:Number(line.unit_price||0),discount_amount:Number(line.discount_amount||0)};
}
