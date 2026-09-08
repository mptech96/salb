"use client";
import {useCallback,useEffect,useState} from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import {readSession} from "@/lib/session";
import {FinancialNotice} from "@/components/design-system/AccountingWorkspace";
import {PageHeader,StatusBadge,SurfaceCard,secondaryButtonClassName} from "@/components/ui/enterprise";

type Year={id:number;year_name:string;start_date:string;end_date:string;is_closed:number;closed_at?:string;closed_by?:number;review_status?:string};
type Preview={year:Year;entries_count:number;unposted_entries_count:number;total_debits:number;total_credits:number;trial_balance_difference:number;net_result:number;retained_earnings_account?:{account_code:string;account_name:string};next_year:{year_name:string;exists:boolean};blockers:string[];can_close:boolean};
const money=(value:number)=>Number(value||0).toLocaleString("ar-SA",{minimumFractionDigits:3,maximumFractionDigits:3});

export default function FinancialYearsPage(){
 const[years,setYears]=useState<Year[]>([]),[preview,setPreview]=useState<Preview|null>(null),[confirmed,setConfirmed]=useState(false),[loading,setLoading]=useState(false);
 const[notice,setNotice]=useState<{type:"success"|"error";title:string;message:string}|null>(null);
 const session=readSession(),role=(session?.user?.role?.role_code||"").toUpperCase(),manager=["MANAGER","COMPANY_MANAGER","COMPANY_ADMIN","COMPANY_OWNER","ADMIN"].includes(role);
 const canManage=(manager||Boolean(session?.permissions.includes("financial_setup.manage")))&&!session?.user?.is_support_mode;
 const load=useCallback(async()=>{const response=await api.get("/financial-years"),rows:Year[]=response.data.data||[];setYears(await Promise.all(rows.map(async year=>{if(Number(year.is_closed)===1||new Date(`${year.end_date}T23:59:59`)>=new Date())return year;try{const check=await api.get(`/financial-years/${year.id}/close-preview`);return{...year,review_status:check.data.data.status}}catch{return{...year,review_status:"NEEDS_ATTENTION"}}})))},[]);
 useEffect(()=>{void load()},[load]);
 const review=async(year:Year)=>{setLoading(true);setConfirmed(false);try{const response=await api.get(`/financial-years/${year.id}/close-preview`);setPreview(response.data.data)}catch(error:any){setNotice({type:"error",title:"تعذر فحص الإقفال",message:error?.response?.data?.message||"حدث خطأ"})}finally{setLoading(false)}};
 const close=async()=>{if(!preview||!confirmed)return;setLoading(true);try{await api.post(`/financial-years/${preview.year.id}/close`,{confirm_year_close:true});setPreview(null);setNotice({type:"success",title:"تم الإقفال",message:"تم اعتماد ترحيل السنة وإقفالها وفتح السنة التالية."});await load()}catch(error:any){setNotice({type:"error",title:"تعذر الإقفال",message:error?.response?.data?.message||"حدث خطأ"})}finally{setLoading(false)}};
 const reopen=async(id:number)=>{setLoading(true);try{await api.post(`/financial-years/${id}/reopen`);setNotice({type:"success",title:"تمت إعادة الفتح",message:"تم عكس قيود الإقفال وإعادة فتح السنة."});await load()}catch(error:any){setNotice({type:"error",title:"تعذر إعادة الفتح",message:error?.response?.data?.message||"حدث خطأ"})}finally{setLoading(false)}};
 return <section dir="rtl" className="space-y-4">
  <PageHeader title="السنوات المالية" description="مراجعة السنة واعتماد ترحيل نتيجتها قبل الإقفال النهائي." breadcrumbs={[{label:"المحاسبة",href:"/accounting"},{label:"السنوات المالية"}]}/>
  <FinancialNotice tone="warning">انتهاء التاريخ لا يقفل السنة تلقائيًا. الإقفال يتطلب مراجعة صريحة ويمنع الترحيل داخل الفترة بعد اعتماده.</FinancialNotice>
  <SurfaceCard title="سجل السنوات" description={`${years.length} سنة مالية`}>
   <div className="divide-y divide-slate-100">{years.map(year=>{const closed=Number(year.is_closed)===1,ended=new Date(`${year.end_date}T23:59:59`)<new Date(),attention=year.review_status==="NEEDS_ATTENTION";return <div key={year.id} className="grid gap-3 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-center">
    <div><div className="font-bold text-slate-900">{year.year_name}</div><div className="mt-1 text-xs text-slate-500">{year.start_date} ← {year.end_date}</div>{closed&&year.closed_at?<div className="mt-1 text-xs text-slate-500">أقفل في {year.closed_at}{year.closed_by?` بواسطة #${year.closed_by}`:""}</div>:null}</div>
    <StatusBadge tone={closed?"neutral":attention?"danger":ended?"warning":"success"}>{closed?"مقفلة":attention?"تحتاج معالجة":ended?"جاهزة للمراجعة":"مفتوحة"}</StatusBadge>
    {canManage?<div>{closed?<button disabled={loading} onClick={()=>void reopen(year.id)} className={secondaryButtonClassName}>إعادة فتح</button>:ended?<button disabled={loading} onClick={()=>void review(year)} className={secondaryButtonClassName}>مراجعة وإقفال السنة</button>:null}</div>:null}
   </div>})}</div>
  </SurfaceCard>
  <SystemDialog open={Boolean(preview)} type="warning" title="مراجعة واعتماد إقفال السنة" message="سيتم اعتماد ترحيل أرصدة ونتيجة السنة المالية وإقفال السنة نهائيًا وفق القيود المحاسبية الحالية. يرجى التأكد من مراجعة الحسابات قبل المتابعة." showCancel showConfirm={Boolean(preview?.can_close)} loading={loading} confirmDisabled={!confirmed} confirmText="اعتماد ترحيل السنة وإقفالها" onClose={()=>{if(!loading)setPreview(null)}} onConfirm={close}>
   {preview?<div className="space-y-3 text-sm"><dl className="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-3">
    <dt>السنة</dt><dd>{preview.year.year_name}</dd><dt>الفترة</dt><dd>{preview.year.start_date} — {preview.year.end_date}</dd>
    <dt>إجمالي المدين</dt><dd>{money(preview.total_debits)}</dd><dt>إجمالي الدائن</dt><dd>{money(preview.total_credits)}</dd><dt>الفرق</dt><dd>{money(preview.trial_balance_difference)}</dd>
    <dt>الربح / الخسارة</dt><dd>{money(preview.net_result)}</dd><dt>حساب الوجهة</dt><dd>{preview.retained_earnings_account?`${preview.retained_earnings_account.account_code} — ${preview.retained_earnings_account.account_name}`:"غير مضبوط"}</dd>
    <dt>القيود المرحلة</dt><dd>{preview.entries_count}</dd><dt>القيود غير المرحلة</dt><dd>{preview.unposted_entries_count}</dd><dt>السنة التالية</dt><dd>{preview.next_year.year_name} ({preview.next_year.exists?"موجودة":"سيتم إنشاؤها"})</dd>
   </dl>{preview.blockers.length?<ul className="rounded-xl bg-rose-50 p-3 text-rose-700">{preview.blockers.map(item=><li key={item}>• {item}</li>)}</ul>:null}
   <label className="flex items-start gap-2"><input type="checkbox" checked={confirmed} disabled={!preview.can_close||loading} onChange={event=>setConfirmed(event.target.checked)} className="mt-1"/><span>راجعت الأرصدة والقيود وأوافق صراحةً على ترحيل نتيجة السنة وإقفالها.</span></label>{!preview.can_close?<p className="font-semibold text-rose-700">لا يمكن تنفيذ الإقفال قبل معالجة الموانع أعلاه.</p>:null}</div>:null}
  </SystemDialog>
  <SystemDialog open={Boolean(notice)} type={notice?.type||"success"} title={notice?.title||""} message={notice?.message||""} onClose={()=>setNotice(null)} onConfirm={()=>setNotice(null)}/>
 </section>
}
