"use client";
import AccountSearchSelect from "@/components/finance/AccountSearchSelect";
import {fieldClassName,secondaryButtonClassName} from "@/components/ui/enterprise";
export default function ProcessingCostEditor({costs,onChange,branch,currencies,disabled}:any){
 const update=(i:number,key:string,value:string)=>onChange(costs.map((c:any,n:number)=>n===i?{...c,[key]:value,...(key==="payment_status"?{financial_account_id:""}:{})}:c));
 return <fieldset disabled={disabled} className="space-y-3 rounded-xl border p-3"><legend className="font-bold">تكاليف المعالجة الإضافية</legend><p className="text-sm">أدخل مرجعًا فريدًا لكل بند من مستند التكلفة. يعتمد المبلغ عند ترحيل المعالجة، ويوزع على المخرجات بالنسب المعروضة، دون وزن مفترض. المدفوع يقيد مباشرة على الخزينة المختارة.</p>
 {costs.map((c:any,i:number)=><div className="grid gap-2 rounded-xl border p-3 md:grid-cols-2" key={i}>
 <label>نوع التكلفة<input aria-label={`نوع التكلفة ${i+1}`} className={fieldClassName} value={c.cost_type} onChange={e=>update(i,"cost_type",e.target.value)}/></label>
 <label>مرجع مستند التكلفة<input aria-label={`مرجع مستند التكلفة ${i+1}`} className={fieldClassName} value={c.source_reference} onChange={e=>update(i,"source_reference",e.target.value)}/></label>
 <label>مبلغ التكلفة<input aria-label={`مبلغ التكلفة ${i+1}`} inputMode="decimal" className={fieldClassName} value={c.amount} onChange={e=>update(i,"amount",e.target.value)}/></label>
 <label>عملة التكلفة<select aria-label={`عملة التكلفة ${i+1}`} className={fieldClassName} value={c.currency_code} onChange={e=>update(i,"currency_code",e.target.value)}>{currencies.map((x:any)=><option key={x.currency_code}>{x.currency_code}</option>)}</select></label>
 <label>سعر الصرف الموثق<input aria-label={`سعر الصرف الموثق ${i+1}`} className={fieldClassName} inputMode="decimal" value={c.exchange_rate} onChange={e=>update(i,"exchange_rate",e.target.value)}/></label>
 <label>سداد التكلفة<select aria-label={`سداد التكلفة ${i+1}`} className={fieldClassName} value={c.payment_status} onChange={e=>update(i,"payment_status",e.target.value)}><option value="UNPAID">مستحقة غير مدفوعة</option><option value="PAID">مدفوعة من خزينة</option></select></label>
 {c.payment_status==="PAID"&&<AccountSearchSelect endpoint="/inventory-operations/account-options" branch={branch} disabled={disabled} label={`خزينة التكلفة ${i+1}`} value={c.financial_account_id||""} onChange={v=>update(i,"financial_account_id",v)}/>}
 <button type="button" className={secondaryButtonClassName} onClick={()=>onChange(costs.filter((_:any,n:number)=>n!==i))}>حذف تكلفة {i+1}</button></div>)}
 <button type="button" className={secondaryButtonClassName} onClick={()=>onChange([...costs,{cost_type:"",source_reference:"",amount:"",currency_code:currencies.find((c:any)=>c.is_base)?.currency_code||currencies[0]?.currency_code||"",exchange_rate:"1",payment_status:"UNPAID",financial_account_id:""}])}>إضافة تكلفة موثقة</button></fieldset>;
}
