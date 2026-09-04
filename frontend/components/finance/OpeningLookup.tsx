"use client";
import { useEffect, useState } from "react";
import api from "@/app/api";

type Props = { title: string; type: string; value: string; branch?: string; options: any[][]; onChange: (value: string, row?: any) => void; empty?: string };
export default function OpeningLookup({title,type,value,branch,options,onChange,empty="اختر"}:Props) {
  const [search,setSearch]=useState("");
  const [rows,setRows]=useState<any[]|null>(null);
  const [error,setError]=useState("");
  const [loading,setLoading]=useState(false);
  useEffect(()=>{
    let active=true;
    const timer=setTimeout(async()=>{
      if(!type){setRows([]);return;}
      setLoading(true);setError("");
      try { const response=await api.get("/opening-balances/lookup",{params:{type,search,branch_id:branch||undefined}});if(active)setRows(response.data?.data||[]); }
      catch { if(active)setError("تعذر البحث؛ حاول مرة أخرى."); }
      finally { if(active)setLoading(false); }
    },300);
    return()=>{active=false;clearTimeout(timer);};
  },[type,search,branch]);
  const label=(r:any)=>[r.account_code||r.cost_center_code||r.customer_code||r.supplier_code||r.item_code,r.account_name||r.cost_center_name||r.customer_name||r.supplier_name||r.item_name||r.category_name].filter(Boolean).join(" - ");
  const choices=rows===null?options:rows.map(r=>[r.id,label(r)]);
  const selected=choices.find(r=>String(r[0])===String(value))||options.find(r=>String(r[0])===String(value));
  return <label className="min-w-0 space-y-1"><span className="text-xs font-bold text-slate-600">{title}</span><input aria-label={`بحث ${title}`} className="input" value={search} onChange={e=>setSearch(e.target.value)} placeholder="ابحث بالاسم أو الكود…" disabled={!type}/><select aria-label={title} className="input" value={value||""} onChange={e=>onChange(e.target.value,rows?.find(r=>String(r.id)===e.target.value))} disabled={!type}><option value="">{loading?"جاري البحث…":empty}</option>{value&&!choices.some(r=>String(r[0])===String(value))&&<option value={value}>{selected?.[1]||`#${value}`}</option>}{choices.map(r=><option key={r[0]} value={r[0]}>{r[1]}</option>)}</select>{error&&<span role="alert" className="block text-xs text-rose-700">{error}</span>}<span className="block text-[10px] text-slate-500">أول 25 نتيجة؛ ضيّق البحث عند الحاجة.</span></label>;
}
