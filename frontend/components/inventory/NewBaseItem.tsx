"use client";
import {useEffect,useState,type FormEvent} from "react";
import api from "@/app/api";

/** New items only; no conversion of an existing historical item. */
export default function NewBaseItem({onCreated}:{onCreated?:()=>void}){
  const [units,setUnits]=useState<Array<{id:number;code:string;name_ar:string}>>([]);
  const [name,setName]=useState("");const [code,setCode]=useState("");const [unit,setUnit]=useState("");
  const [busy,setBusy]=useState(false);const [message,setMessage]=useState("");
  useEffect(()=>{let active=true;api.get('/items/meta',{params:{include_accounts:false}}).then(r=>{if(active)setUnits(r.data.data?.uom_base_units||[]);}).catch(()=>{});return()=>{active=false;};},[]);
  async function create(event:FormEvent){
    event.preventDefault();if(busy||!name.trim()||!unit)return;
    setBusy(true);setMessage("");
    try{await api.post('/items',{item_name:name.trim(),item_code:code.trim()||null,item_type:'STOCK',track_inventory:true,base_uom_id:Number(unit)});
      setName("");setCode("");setMessage('تم إنشاء الصنف ووحدته الأساسية. يمكنك الآن إضافة وحداته الفرعية.');onCreated?.();
    }catch(error:any){setMessage(error?.response?.data?.message||'تعذر إنشاء الصنف. تحقق من صلاحيات إنشاء الأصناف وإدارة وحداتها.');}finally{setBusy(false);}
  }
  if(!units.length)return null;
  return <form aria-label="صنف جديد بوحدة أساسية" onSubmit={event=>void create(event)} className="mb-4 grid gap-3 rounded-xl border bg-slate-50 p-4 sm:grid-cols-2" dir="rtl">
    <h3 className="font-bold sm:col-span-2">صنف جديد بوحدة أساسية مستقلة عن الوزن</h3>
    <label>اسم الصنف<input required disabled={busy} value={name} onChange={e=>setName(e.target.value)} className="w-full rounded border p-2"/></label>
    <label>كود الصنف<input disabled={busy} value={code} onChange={e=>setCode(e.target.value)} className="w-full rounded border p-2"/></label>
    <label>الوحدة الأساسية الجديدة<select required disabled={busy} value={unit} onChange={e=>setUnit(e.target.value)} className="w-full rounded border p-2"><option value="">اختر الوحدة</option>{units.map(u=><option key={u.id} value={u.id}>{u.name_ar} ({u.code})</option>)}</select></label>
    <button disabled={busy||!unit} className="self-end rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">إنشاء صنف ووحدته</button>
    {message&&<p role="status" className="sm:col-span-2">{message}</p>}
  </form>;
}
