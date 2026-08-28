"use client";
import { useEffect, useMemo, useRef, useState } from "react";
import api from "../api";
import SearchSelect from "@/components/sulb/SearchSelect";
import SystemDialog from "@/components/common/SystemDialog";
import { EmptyState, PageHeader, StatusBadge, fieldClassName, primaryButtonClassName, secondaryButtonClassName } from "@/components/ui/enterprise";
import { EnterpriseFilterBar, EnterpriseTable, StickyActionBar } from "@/components/design-system/EnterpriseWorkspace";

type Permission = { id: number; permission_code: string; permission_name: string; module_name?: string };
type Effect = "INHERIT" | "ALLOW" | "DENY";
const moduleNames: Record<string, string> = { weighbridge:"الميزان", shipments:"الشحنات", purchases:"المشتريات", sales:"المبيعات", inventory:"المخزون", users:"المستخدمون", tax_reports:"الضرائب", accounting:"المحاسبة", branches:"الفروع", reports:"التقارير", settings:"الإعدادات", vouchers:"السندات", expenses:"المصروفات", other:"عام" };
const actionNames: Record<string, string> = { view:"عرض", create:"إنشاء", edit:"تعديل", update:"تعديل", post:"ترحيل", void:"إلغاء", delete:"حذف", print:"طباعة", export:"تصدير", manage:"إدارة", draft:"إعداد" };

export default function PermissionsCenter() {
  const [users,setUsers]=useState<any[]>([]);
  const [perms,setPerms]=useState<Permission[]>([]);
  const [userId,setUserId]=useState("");
  const [detail,setDetail]=useState<any>(null);
  const [over,setOver]=useState<Record<string,Effect>>({});
  const [saving,setSaving]=useState(false);
  const [q,setQ]=useState("");
  const [dialog,setDialog]=useState<any>({open:false,type:"info",title:"",message:""});

  async function load(){try{const r=await api.get("/permission-matrix");setUsers(r.data.data?.users||[]);setPerms(r.data.data?.permissions||[])}catch(e:any){setDialog({open:true,type:"error",title:"تعذر فتح مركز الصلاحيات",message:e?.response?.data?.message||"هذه الشاشة متاحة للمستخدم الرئيسي/مدير الشركة فقط."})}}
  useEffect(()=>{void load()},[]);
  async function pick(id:string){setUserId(id);if(!id){setDetail(null);return}try{const r=await api.get(`/permission-matrix/users/${id}`),d=r.data.data;setDetail(d);const m:Record<string,Effect>={};for(const x of d.overrides||[])m[x.permission_code]=x.effect;setOver(m)}catch(e:any){setDialog({open:true,type:"error",title:"تعذر تحميل المستخدم",message:e?.response?.data?.message||"حدث خطأ."})}}
  async function save(){if(!userId)return;setSaving(true);try{const payload:Record<string,Effect>={};for(const p of perms)payload[p.permission_code]=over[p.permission_code]||"INHERIT";await api.put(`/permission-matrix/users/${userId}`,{overrides:payload});await pick(userId);await load();setDialog({open:true,type:"success",title:"تم حفظ الصلاحيات",message:"تم تطبيق صلاحيات الإجراءات على المستخدم."})}catch(e:any){setDialog({open:true,type:"error",title:"تعذر الحفظ",message:e?.response?.data?.message||"حدث خطأ."})}finally{setSaving(false)}}

  const groups=useMemo(()=>{const grouped:Record<string,Permission[]>={};for(const permission of perms.filter(p=>!q||`${p.permission_code} ${p.permission_name}`.toLowerCase().includes(q.toLowerCase())))(grouped[permission.module_name||"other"]??=[]).push(permission);return grouped},[perms,q]);
  const effective=new Set<string>(detail?.effective_permissions||[]);
  const inherited=new Set<string>(detail?.base_permissions||[]);
  function setModule(list:Permission[],effect:Effect){setOver(previous=>{const next={...previous};for(const permission of list)next[permission.permission_code]=effect;return next})}

  return <section dir="rtl" className="space-y-4">
    <PageHeader title="مركز صلاحيات الإجراءات" description="إدارة صلاحيات موظفي الشركة على مستوى الوحدة والإجراء مع الحفاظ على الصلاحيات الموروثة من الدور." breadcrumbs={[{label:"الرئيسية",href:"/"},{label:"المستخدمون",href:"/users"},{label:"صلاحيات الإجراءات"}]}/>
    <EnterpriseFilterBar><div className="min-w-0 flex-1"><SearchSelect value={userId} onChange={value=>void pick(value)} placeholder="اختر المستخدم" searchPlaceholder="ابحث باسم المستخدم، الدور أو الفرع..." options={users.map(user=>({value:user.id,label:`${user.name} — ${user.role_name||user.role_code||"بدون دور"} — ${user.branch_name||"الشركة"}`,search:`${user.name||""} ${user.role_name||user.role_code||""} ${user.branch_name||""}`}))}/></div><input className={`${fieldClassName} sm:max-w-xs`} value={q} onChange={event=>setQ(event.target.value)} placeholder="بحث في الإجراءات والصلاحيات"/></EnterpriseFilterBar>
    {detail&&<div className="grid gap-2 sm:grid-cols-3"><Metric label="الدور الأساسي" value={detail.role?.role_name||detail.role?.role_code||"—"}/><Metric label="الصلاحيات الفعّالة" value={effective.size}/><Metric label="الموروثة من الدور" value={inherited.size}/></div>}
    {!detail&&<EmptyState title="اختر مستخدمًا لعرض مصفوفة الصلاحيات" description="تظهر فقط صلاحيات الشركة التي يوفرها عقد الصلاحيات الحالي."/>}
    {detail&&Object.entries(groups).map(([module,list])=><section key={module} className="overflow-hidden rounded-lg border border-slate-200 bg-white"><header className="flex flex-col gap-2 border-b border-slate-100 px-3 py-2.5 lg:flex-row lg:items-center lg:justify-between"><div><h2 className="text-xs font-bold text-slate-900">{moduleNames[module]||module||"عام"}</h2><p className="mt-0.5 text-[10px] text-slate-500">{list.length} إجراء · القرارات المباشرة لا تُحفظ قبل اعتماد التعديلات.</p></div><div className="flex flex-wrap items-center gap-2"><ModuleCheckbox permissions={list} overrides={over} onChange={checked=>setModule(list,checked?"ALLOW":"INHERIT")}/><button type="button" className={secondaryButtonClassName} onClick={()=>setModule(list,"ALLOW")}>سماح للجميع</button><button type="button" className={secondaryButtonClassName} onClick={()=>setModule(list,"INHERIT")}>مسح القرارات</button></div></header><EnterpriseTable minWidth={760}><thead><tr><th>الإجراء</th><th>من الدور</th><th>قرار المستخدم</th><th>الصلاحية الحالية</th></tr></thead><tbody>{list.map(permission=><tr key={permission.id}><td><div className="font-medium text-slate-800">{permission.permission_name||actionNames[permission.permission_code.split(".").pop()||""]||permission.permission_code}</div><div dir="ltr" className="mt-1 text-right font-mono text-[11px] text-slate-400">{permission.permission_code}</div></td><td>{inherited.has(permission.permission_code)?<StatusBadge tone="info">موروث</StatusBadge>:<StatusBadge>غير موجود</StatusBadge>}</td><td><select className={`${fieldClassName} max-w-[180px]`} value={over[permission.permission_code]||"INHERIT"} onChange={event=>setOver(previous=>({...previous,[permission.permission_code]:event.target.value as Effect}))}><option value="INHERIT">يرث من الدور</option><option value="ALLOW">سماح مباشر</option><option value="DENY">منع مباشر</option></select></td><td>{effective.has(permission.permission_code)?<StatusBadge tone="success">مسموح</StatusBadge>:<StatusBadge tone="danger">ممنوع</StatusBadge>}</td></tr>)}</tbody></EnterpriseTable></section>)}
    {detail&&Object.keys(groups).length===0&&<EmptyState title="لا توجد صلاحيات مطابقة للبحث"/>}
    {detail&&<StickyActionBar dirty><button type="button" onClick={()=>void save()} disabled={saving} className={primaryButtonClassName}>{saving?"جاري الحفظ...":"حفظ مصفوفة المستخدم"}</button></StickyActionBar>}
    <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} loading={saving} onClose={()=>setDialog({...dialog,open:false})} onConfirm={()=>setDialog({...dialog,open:false})}/>
  </section>;
}

function ModuleCheckbox({permissions,overrides,onChange}:{permissions:Permission[];overrides:Record<string,Effect>;onChange:(checked:boolean)=>void}){const checkbox=useRef<HTMLInputElement>(null);const allowed=permissions.filter(permission=>overrides[permission.permission_code]==="ALLOW").length;useEffect(()=>{if(checkbox.current)checkbox.current.indeterminate=allowed>0&&allowed<permissions.length},[allowed,permissions.length]);return <label className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-xs font-medium text-slate-700"><input ref={checkbox} type="checkbox" checked={permissions.length>0&&allowed===permissions.length} onChange={event=>onChange(event.target.checked)}/>تحديد الوحدة</label>}

function Metric({label,value}:{label:string;value:string|number}){return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5"><div className="text-[10px] font-semibold text-slate-500">{label}</div><div className="mt-1 text-lg font-bold text-slate-900">{value}</div></div>}
