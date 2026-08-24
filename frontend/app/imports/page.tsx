"use client";

import {useEffect,useMemo,useState} from "react";
import api from "../api";
import SearchSelect from "@/components/sulb/SearchSelect";
import {ListToolbar,Pager,TableScroll,usePagedSearch} from "@/components/sulb/ListUX";
import {Badge,Btn,Field,PageHero,Panel,Stat,fmt,inputCls} from "@/components/sulb/EnterpriseUI";

type Entity={code:string;label:string;kind:string;key_field:string;fields:{code:string;label:string;required:boolean;aliases:string[]}[]};

async function fileToBase64(file:File){
 const bytes=new Uint8Array(await file.arrayBuffer());
 let binary="";const chunk=0x8000;
 for(let i=0;i<bytes.length;i+=chunk)binary+=String.fromCharCode(...bytes.subarray(i,Math.min(i+chunk,bytes.length)));
 return btoa(binary);
}
function getErrorMessage(e:any){
 const data=e?.response?.data;
 if(data?.errors&&typeof data.errors==="object"){
  for(const value of Object.values(data.errors)){
   if(Array.isArray(value)&&value.length)return String(value[0]);
   if(value)return String(value);
  }
 }
 return String(data?.message||e?.message||"تعذر تنفيذ الطلب.");
}


export default function ImportsPage(){
 const [entities,setEntities]=useState<Entity[]>([]),[history,setHistory]=useState<any[]>([]),[entity,setEntity]=useState("items"),[file,setFile]=useState<File|null>(null),[preview,setPreview]=useState<any>(null),[mapping,setMapping]=useState<Record<string,string>>({}),[source,setSource]=useState("النظام السابق"),[posting,setPosting]=useState("DRAFT"),[autoCreate,setAutoCreate]=useState(true),[busy,setBusy]=useState(false),[msg,setMsg]=useState("");
 const selected=useMemo(()=>entities.find(x=>x.code===entity),[entities,entity]);
 const historyList=usePagedSearch(history,(x:any)=>`${x.id||""} ${x.entity_code||""} ${x.source_system||""} ${x.posting_mode||""} ${x.status||""} ${x.created_at||""}`,25);
 async function load(){try{const r=await api.get("/imports/catalog");setEntities(r.data.data?.entities||[]);setHistory(r.data.data?.history||[])}catch(e:any){setMsg(e?.response?.data?.message||"تعذر تحميل مركز نقل البيانات.")}}
 useEffect(()=>{void load()},[]);
 async function previewFile(){
  if(!file){setMsg("اختر ملف CSV أولًا.");return;}
  setBusy(true);setMsg("");setPreview(null);
  try{
    // IMPORTANT: send CSV as Base64 JSON through the same API client used by the rest of the app.
    // This deliberately avoids multipart/FormData because the project API client/interceptors can strip the multipart boundary.
    const file_base64=await fileToBase64(file);
    const r=await api.post(`/imports/preview/${entity}`,{file_base64,file_name:file.name});
    if(r.data?.status===false)throw new Error(r.data?.message||"تعذر معاينة الملف.");
    setPreview(r.data.data);setMapping(r.data.data?.mapping||{});
    setMsg(`تمت قراءة الملف بنجاح: ${r.data.data?.total_rows||0} سطر. راجع مطابقة الأعمدة أدناه.`);
    requestAnimationFrame(()=>document.getElementById("sulb-import-mapping")?.scrollIntoView({behavior:"smooth",block:"start"}));
  }catch(e:any){
    setMsg(`فشل معاينة الملف: ${getErrorMessage(e)}`);
  }finally{setBusy(false)}
}

 async function runImport(){
  if(!file){setMsg("اختر ملف CSV أولًا.");return;}
  setBusy(true);setMsg("");
  try{
    const file_base64=await fileToBase64(file);
    const r=await api.post(`/imports/${entity}`,{
      file_base64,file_name:file.name,mapping:JSON.stringify(mapping),source_system:source,posting_mode:posting,
      auto_create_groups_categories:String(autoCreate)
    });
    if(r.data?.status===false)throw new Error(r.data?.message||"تعذر تنفيذ الاستيراد.");
    const st=r.data.data?.stats||{};
    setMsg(`اكتمل الاستيراد: جديد ${st.imported||0}، محدث ${st.updated||0}، متجاوز ${st.skipped||0}، أخطاء ${st.failed||0}.`);
    setPreview(null);setFile(null);await load();
  }catch(e:any){
    setMsg(`فشل الاستيراد: ${getErrorMessage(e)}`);
  }finally{setBusy(false)}
}

 async function download(path:string){try{const r=await api.get(path,{responseType:"blob"});const cd=String(r.headers?.["content-disposition"]||"");const m=cd.match(/filename="?([^";]+)"?/i);const name=m?.[1]||"SULB-export.csv";const url=URL.createObjectURL(new Blob([r.data]));const a=document.createElement("a");a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url)}catch(e:any){setMsg(e?.response?.data?.message||"تعذر تنزيل الملف.")}}
 function pick(code:string){setEntity(code);setFile(null);setPreview(null);setMapping({});setMsg("")}
 return <div className="space-y-5">
  <PageHero eyebrow="مركز الانتقال إلى صلب" title="استيراد وتصدير البيانات" description="انقل بيانات العميل من أي نظام سابق إلى SULB ERP مع معاينة وربط أعمدة قبل الإدخال. فواتير المبيعات والمشتريات تُنشأ كمسودة افتراضيًا ولا تؤثر على المخزون أو الحسابات إلا إذا اخترت الترحيل صراحة." />
  {msg&&<div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 font-bold text-blue-900">{msg}</div>}
  <div className="grid gap-3 md:grid-cols-4"><Stat label="الكيانات المدعومة" value={entities.length}/><Stat label="دفعات سابقة" value={history.length}/><Stat label="الوضع الافتراضي للفواتير" value="DRAFT" sub="آمن: بلا أثر مخزني أو محاسبي"/><Stat label="تنسيق النقل" value="CSV UTF-8" sub="يدعم القالب القديم للأصناف"/></div>
  <Panel title="1. اختر نوع البيانات" sub="يمكن تنزيل قالب صلب أو رفع ملف من نظام آخر ثم ربط أعمدته يدويًا."><div className="grid gap-2 md:grid-cols-3 lg:grid-cols-4">{entities.map(x=><button key={x.code} onClick={()=>pick(x.code)} className={`rounded-2xl border p-4 text-right transition ${entity===x.code?"border-[#0B2A4A] bg-blue-50":"bg-white hover:bg-slate-50"}`}><div className="font-black text-[#0B2A4A]">{x.label}</div><div className="mt-1 text-xs text-slate-500">{x.kind==="TRANSACTION"?"عمليات / فواتير":"بيانات أساسية"}</div></button>)}</div></Panel>
  <Panel title={`2. ملف ${selected?.label||"البيانات"}`} actions={<div className="flex gap-2"><Btn kind="light" onClick={()=>download(`/imports/template/${entity}`)}>تنزيل القالب</Btn><Btn kind="light" onClick={()=>download(`/imports/export/${entity}`)}>تصدير بيانات صلب</Btn></div>}>
   <div className="grid gap-4 md:grid-cols-3"><Field label="النظام المصدر"><input className={inputCls} value={source} onChange={e=>setSource(e.target.value)} placeholder="مثال: برنامج قديم / Excel / ERP آخر"/></Field><Field label="ملف CSV"><input type="file" accept=".csv,.txt" className={inputCls} onChange={e=>setFile(e.target.files?.[0]||null)}/></Field>{selected?.kind==="TRANSACTION"?<Field label="طريقة إدخال الفواتير"><select className={inputCls} value={posting} onChange={e=>setPosting(e.target.value)}><option value="DRAFT">مسودة فقط - موصى به</option><option value="POST">استيراد وترحيل - يؤثر على المخزون والحسابات</option></select></Field>:<Field label="إنشاء المجموعات/الفئات غير الموجودة"><select className={inputCls} value={autoCreate?"1":"0"} onChange={e=>setAutoCreate(e.target.value==="1")}><option value="1">نعم</option><option value="0">لا</option></select></Field>}</div>
   <div className="mt-4 flex flex-wrap items-center gap-3"><Btn disabled={!file||busy} onClick={previewFile}>{busy?"جاري قراءة الملف...":"معاينة وربط الأعمدة"}</Btn>{file&&<span className="text-xs font-bold text-slate-500">الملف: {file.name} — {fmt(file.size/1024,1)} KB</span>}</div>
   {msg&&<div className={`mt-3 rounded-xl border p-3 text-sm font-bold ${msg.startsWith("فشل")?"border-rose-200 bg-rose-50 text-rose-800":"border-blue-200 bg-blue-50 text-blue-900"}`}>{msg}</div>}
  </Panel>
  {preview&&selected&&<div id="sulb-import-mapping"><Panel title="3. مطابقة الأعمدة" sub={`تم اكتشاف ${fmt(preview.total_rows||0,0)} سطر. راجع الربط قبل الاستيراد.`}>
   <div className="grid gap-3 md:grid-cols-3">{selected.fields.map(f=><Field key={f.code} label={`${f.label}${f.required?" *":""}`}><SearchSelect value={mapping[f.code]||""} onChange={v=>setMapping({...mapping,[f.code]:v})} placeholder="— غير مربوط —" searchPlaceholder="ابحث عن اسم العمود..." options={[{value:"",label:"— غير مربوط —"},...(preview.headers||[]).map((h:string)=>({value:h,label:h,search:h}))]}/></Field>)}</div>
   <div className="mt-5 rounded-2xl border bg-slate-50 p-4"><div className="mb-3 flex gap-2"><Badge tone="green">سليم بالعينة: {fmt(preview.sample_valid||0,0)}</Badge><Badge tone={preview.sample_invalid?"red":"slate"}>به أخطاء: {fmt(preview.sample_invalid||0,0)}</Badge></div><div className="overflow-x-auto"><table className="min-w-[900px] w-full text-right text-xs"><thead><tr className="border-b">{selected.fields.slice(0,7).map(f=><th key={f.code} className="p-2">{f.label}</th>)}<th className="p-2">الحالة</th></tr></thead><tbody>{(preview.sample||[]).slice(0,10).map((x:any)=><tr key={x.row_number} className="border-b"><>{selected.fields.slice(0,7).map(f=><td key={f.code} className="p-2">{x.data?.[f.code]||"—"}</td>)}</><td className="p-2">{x.errors?.length?<span className="text-rose-700">{x.errors.join("، ")}</span>:<span className="text-emerald-700">جاهز</span>}</td></tr>)}</tbody></table></div></div>
   {selected.kind==="TRANSACTION"&&posting==="POST"&&<div className="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 font-bold text-amber-900">تنبيه: POST سيجعل الفواتير المستوردة تؤثر فعليًا على المخزون والقيود والضريبة. استخدمه فقط بعد تجربة الملف على نسخة اختبار.</div>}
   <div className="mt-4 flex justify-end"><Btn kind={posting==="POST"&&selected.kind==="TRANSACTION"?"amber":"success"} disabled={busy} onClick={runImport}>{busy?"جاري الاستيراد...":"تنفيذ الاستيراد"}</Btn></div>
  </Panel></div>}
  <Panel title="سجل دفعات الاستيراد" sub="كل دفعة محفوظة باسم المصدر وعدد السطور والنتيجة، حتى تعرف ماذا نُقل ومن أي نظام."><ListToolbar query={historyList.query} setQuery={historyList.setQuery} total={historyList.total} page={historyList.page} pageSize={historyList.pageSize} setPageSize={historyList.setPageSize} placeholder="ابحث بالنوع، النظام المصدر، الحالة أو التاريخ..."/><TableScroll><table className="w-full min-w-[900px] text-right text-sm"><thead className="bg-slate-100"><tr>{["#","النوع","النظام المصدر","الوضع","الصفوف","تم","تجاوز","أخطاء","الحالة","التاريخ"].map(x=><th key={x} className="p-3">{x}</th>)}</tr></thead><tbody>{historyList.paged.length===0?<tr><td colSpan={10} className="p-8 text-center text-slate-400">لا توجد دفعات استيراد مطابقة.</td></tr>:historyList.paged.map((x:any)=><tr key={x.id} className="border-t"><td className="p-3">{x.id}</td><td className="p-3 font-bold">{x.entity_code}</td><td className="p-3">{x.source_system||"—"}</td><td className="p-3"><Badge tone={x.posting_mode==="POST"?"amber":"blue"}>{x.posting_mode}</Badge></td><td className="p-3">{fmt(x.total_rows,0)}</td><td className="p-3">{fmt(x.imported_rows,0)}</td><td className="p-3">{fmt(x.skipped_rows,0)}</td><td className="p-3">{fmt(x.failed_rows,0)}</td><td className="p-3">{x.status}</td><td className="p-3">{x.created_at}</td></tr>)}</tbody></table></TableScroll><Pager page={historyList.page} totalPages={historyList.totalPages} setPage={historyList.setPage}/></Panel>
 </div>
}
