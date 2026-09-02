"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import PrintHeader from "@/components/reports/PrintHeader";
import {PageHeader} from "@/components/ui/enterprise";
import ServerPagination, {PaginationMeta} from "@/components/finance/ServerPagination";

const fmt = (x: any) => Number(x || 0).toLocaleString("ar", { minimumFractionDigits: 3, maximumFractionDigits: 3 });

type Kind = "account" | "customer" | "supplier" | "driver" | "worker";

export default function StatementsPage() {
  const [kind, setKind] = useState<Kind>("account");
  const [entities, setEntities] = useState<any[]>([]);
  const [branches, setBranches] = useState<any[]>([]);
  const [branch, setBranch] = useState("");
  const [selected, setSelected] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [data, setData] = useState<any>(null);
  const [profile, setProfile] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState("");
  const [pagination, setPagination] = useState<PaginationMeta>({current_page:1,last_page:1,per_page:25,total:0});
  const [dialog, setDialog] = useState<any>({ open: false, title: "", message: "", type: "warning" });

  const endpoint = useMemo(() => `/statements/entities/${kind}`, [kind]);
  const nameOf = (x: any) => kind === "account" ? `${x.account_code} - ${x.account_name}` : x.customer_name || x.supplier_name || x.driver_name || x.worker_name || x.name || `#${x.id}`;

  async function loadEntities() {
    setSelected(""); setData(null);
    try { const r = await api.get(endpoint, { params: branch ? { branch_id: branch } : {} }); setEntities(r.data.data || []); }
    catch { setEntities([]); }
  }

  useEffect(() => { void loadEntities(); }, [endpoint, branch]);
  useEffect(() => {
    api.get("/reports/catalog").then((r) => {
      setProfile(r.data.data?.print_profile || null);
      setBranches(r.data.data?.branches || []);
    }).catch(() => {});
  }, []);

  async function load(page = 1, per_page = pagination.per_page) {
    if (!selected) return setDialog({ open: true, type: "warning", title: "اختر الحساب", message: "اختر الحساب أو الطرف المطلوب." });
    setLoading(true);
    try {
      const r = await api.get(`/statements/${kind}/${selected}`, { params: { from_date: from || undefined, to_date: to || undefined, branch_id: branch || undefined, search: search || undefined, page, per_page } });
      setData(r.data.data);
      setPagination(r.data.data?.data || {current_page:1,last_page:1,per_page,total:0});
    } catch (e: any) {
      setDialog({ open: true, type: "error", title: "تعذر تحميل الكشف", message: e?.response?.data?.message || "حدث خطأ أثناء إعداد كشف الحساب." });
    } finally { setLoading(false); }
  }

  async function exportLedger(format:"csv"|"xls") {
    if(!data||kind!=="account")return;
    try{const response=await api.get(`/statements/account/${selected}/export`,{params:{format,from_date:from||undefined,to_date:to||undefined,branch_id:branch||undefined,search:search||undefined},responseType:"blob"});const url=URL.createObjectURL(response.data);const a=document.createElement("a");a.href=url;a.download=`كشف-حساب-${selected}.${format}`;a.click();URL.revokeObjectURL(url);}catch(e:any){setDialog({open:true,type:"error",title:"تعذر التصدير",message:e?.response?.data?.message||"تعذر تصدير النطاق الكامل."});}
  }

  const selectedBranch = branches.find((b) => String(b.id) === branch);
  const printProfile = profile ? { ...profile, branch_name: selectedBranch?.branch_name || profile.branch_name } : profile;
  const selectedEntity = entities.find((x) => String(x.id) === selected);
  const statementName = data?.name || (data?.account ? `${data.account.account_code} - ${data.account.account_name}` : (selectedEntity ? nameOf(selectedEntity) : ''));

  return (
    <section dir="rtl" className="space-y-5">
      <div className="no-print"><PageHeader title="كشوف الحسابات" description="اختر الحساب أو الطرف والفترة والنطاق لعرض الافتتاحي والحركة والرصيد المتحرك والختامي." breadcrumbs={[{label:"المحاسبة",href:"/accounting"},{label:"كشوف الحسابات"}]}/></div>

      <div className="no-print grid gap-3 rounded-3xl border bg-white p-4 shadow-sm md:grid-cols-7">
        <select value={kind} onChange={(e) => setKind(e.target.value as Kind)} className="rounded-xl border p-3">
          <option value="account">حساب من دليل الحسابات</option><option value="customer">عميل</option><option value="supplier">مورد</option><option value="driver">سائق</option><option value="worker">عامل / موظف</option>
        </select>
        <select value={branch} onChange={(e) => setBranch(e.target.value)} className="rounded-xl border p-3">
          <option value="">كل الفروع / نطاقي</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.branch_name}</option>)}
        </select>
        <select value={selected} onChange={(e) => setSelected(e.target.value)} className="rounded-xl border p-3"><option value="">اختر</option>{entities.map((x) => <option key={x.id} value={x.id}>{nameOf(x)}</option>)}</select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-xl border p-3" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-xl border p-3" />
        <input value={search} onChange={(e)=>setSearch(e.target.value)} placeholder="بحث في القيد أو البيان" className="rounded-xl border p-3" />
        <button onClick={()=>load(1)} className="rounded-xl bg-[#0B2A4A] p-3 font-black text-white">{loading ? "جاري التحميل..." : "عرض الكشف"}</button>
      </div>

      {data && <>
        <div className="no-print flex flex-wrap gap-2"><button onClick={() => window.print()} className="rounded-xl border bg-white px-4 py-2 font-bold">طباعة / PDF</button>{kind==="account"?<><button onClick={()=>void exportLedger("xls")} className="rounded-xl border bg-white px-4 py-2 font-bold">Excel — كامل النطاق</button><button onClick={()=>void exportLedger("csv")} className="rounded-xl border bg-white px-4 py-2 font-bold">CSV — كامل النطاق</button></>:null}</div>
        <div className="sulb-print-area space-y-4 rounded-3xl border bg-white p-5 shadow-sm">
          <PrintHeader profile={printProfile} title={`كشف حساب ${statementName}`} filters={{ from_date: from, to_date: to }} />
          <div className="grid gap-3 md:grid-cols-4">
            {[['الرصيد الافتتاحي',`${fmt(data.opening_balance)} ${data.opening_side==='DEBIT'?'مدين':'دائن'}`],['إجمالي المدين',fmt(data.total_debit)],['إجمالي الدائن',fmt(data.total_credit)],['الرصيد الختامي',`${fmt(data.closing_balance)} ${data.closing_side==='DEBIT'?'مدين':'دائن'}`]].map(([t,v]) => <div key={t} className="rounded-2xl border bg-slate-50 p-4"><div className="text-xs font-bold text-slate-500">{t}</div><div className="mt-1 text-xl font-black text-[#0B2A4A]">{v}</div></div>)}
          </div>
          <div className="overflow-x-auto rounded-2xl border"><table className="w-full min-w-[1050px] text-right text-sm"><thead className="bg-slate-100"><tr><th className="p-3">التاريخ</th><th>رقم القيد</th><th>المصدر</th><th>البيان</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead><tbody>{data.rows?.map((r:any) => <tr key={r.id} className="border-t even:bg-slate-50/50"><td className="p-3">{r.entry_date}</td><td className="font-bold">{r.entry_number}</td><td>{r.source_type}</td><td>{r.description||r.entry_description}</td><td>{r.branch_name||'الشركة'}</td><td>{fmt(r.debit)}</td><td>{fmt(r.credit)}</td><td className="font-black">{fmt(r.running_balance)} {r.running_side==='DEBIT'?'مدين':'دائن'}</td></tr>)}</tbody></table></div>
          <div className="no-print"><ServerPagination meta={pagination} onPage={(p)=>void load(p,pagination.per_page)} onPerPage={(pp)=>void load(1,pp)}/></div>
          <div className="print-only border-t pt-3 text-center text-xs text-slate-500">{printProfile?.report_footer || "تم إنشاء الكشف من نظام صلب ERP"}</div>
        </div>
      </>}
      <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} onClose={() => setDialog({ ...dialog, open: false })} onConfirm={() => setDialog({ ...dialog, open: false })} />
    </section>
  );
}
