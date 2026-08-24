"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import PrintHeader from "@/components/reports/PrintHeader";

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

  async function load() {
    if (!selected) return setDialog({ open: true, type: "warning", title: "اختر الحساب", message: "اختر الحساب أو الطرف المطلوب." });
    setLoading(true);
    try {
      const r = await api.get(`/statements/${kind}/${selected}`, { params: { from_date: from || undefined, to_date: to || undefined, branch_id: branch || undefined } });
      setData(r.data.data);
    } catch (e: any) {
      setDialog({ open: true, type: "error", title: "تعذر تحميل الكشف", message: e?.response?.data?.message || "حدث خطأ أثناء إعداد كشف الحساب." });
    } finally { setLoading(false); }
  }

  function csv() {
    if (!data) return;
    const header = ["التاريخ","رقم القيد","المصدر","البيان","الفرع","مدين","دائن","الرصيد","الجانب"];
    const lines = [header, ...(data.rows || []).map((r: any) => [r.entry_date,r.entry_number,r.source_type,r.description || r.entry_description,r.branch_name || "الشركة",r.debit,r.credit,r.running_balance,r.running_side === "DEBIT" ? "مدين" : "دائن"])];
    const content = "\uFEFF" + lines.map((row: any[]) => row.map((v) => `"${String(v ?? "").replaceAll('"','""')}"`).join(",")).join("\r\n");
    const blob = new Blob([content], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = `كشف-حساب-${selected}.csv`; a.click(); URL.revokeObjectURL(url);
  }

  function excel() {
    if (!data) return;
    const escape = (v: any) => String(v ?? "").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;");
    const rows = (data.rows || []).map((r: any) =>
      `<tr><td>${escape(r.entry_date)}</td><td>${escape(r.entry_number)}</td><td>${escape(r.source_type)}</td><td>${escape(r.description || r.entry_description)}</td><td>${escape(r.branch_name || "الشركة")}</td><td>${escape(r.debit)}</td><td>${escape(r.credit)}</td><td>${escape(r.running_balance)}</td><td>${r.running_side === "DEBIT" ? "مدين" : "دائن"}</td></tr>`
    ).join("");
    const html = `<!doctype html><html dir="rtl"><head><meta charset="UTF-8"><style>body{font-family:Arial;direction:rtl}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:7px;text-align:right}th{background:#e9eef5}</style></head><body><h3>كشف حساب ${escape(statementName)}</h3><table><thead><tr><th>التاريخ</th><th>رقم القيد</th><th>المصدر</th><th>البيان</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>الجانب</th></tr></thead><tbody>${rows}</tbody></table></body></html>`;
    const blob = new Blob(["\uFEFF" + html], { type: "application/vnd.ms-excel;charset=utf-8" });
    const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = `كشف-حساب-${selected}.xls`; a.click(); URL.revokeObjectURL(url);
  }

  const selectedBranch = branches.find((b) => String(b.id) === branch);
  const printProfile = profile ? { ...profile, branch_name: selectedBranch?.branch_name || profile.branch_name } : profile;
  const selectedEntity = entities.find((x) => String(x.id) === selected);
  const statementName = data?.name || (data?.account ? `${data.account.account_code} - ${data.account.account_name}` : (selectedEntity ? nameOf(selectedEntity) : ''));

  return (
    <section dir="rtl" className="space-y-5">
      <div className="no-print rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <div className="text-sm text-blue-100">المالية والمحاسبة</div>
        <h1 className="mt-1 text-3xl font-black">كشوف الحسابات</h1>
        <p className="mt-2 text-blue-100">حساب عام، عميل، مورد، سائق أو عامل مع افتتاحي وحركة ورصيد متحرك وختامي.</p>
      </div>

      <div className="no-print grid gap-3 rounded-3xl border bg-white p-4 shadow-sm md:grid-cols-6">
        <select value={kind} onChange={(e) => setKind(e.target.value as Kind)} className="rounded-xl border p-3">
          <option value="account">حساب من دليل الحسابات</option><option value="customer">عميل</option><option value="supplier">مورد</option><option value="driver">سائق</option><option value="worker">عامل / موظف</option>
        </select>
        <select value={branch} onChange={(e) => setBranch(e.target.value)} className="rounded-xl border p-3">
          <option value="">كل الفروع / نطاقي</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.branch_name}</option>)}
        </select>
        <select value={selected} onChange={(e) => setSelected(e.target.value)} className="rounded-xl border p-3"><option value="">اختر</option>{entities.map((x) => <option key={x.id} value={x.id}>{nameOf(x)}</option>)}</select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="rounded-xl border p-3" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="rounded-xl border p-3" />
        <button onClick={load} className="rounded-xl bg-[#0B2A4A] p-3 font-black text-white">{loading ? "جاري التحميل..." : "عرض الكشف"}</button>
      </div>

      {data && <>
        <div className="no-print flex flex-wrap gap-2"><button onClick={() => window.print()} className="rounded-xl border bg-white px-4 py-2 font-bold">طباعة / PDF</button><button onClick={excel} className="rounded-xl border bg-white px-4 py-2 font-bold">Excel</button><button onClick={csv} className="rounded-xl border bg-white px-4 py-2 font-bold">CSV</button></div>
        <div className="sulb-print-area space-y-4 rounded-3xl border bg-white p-5 shadow-sm">
          <PrintHeader profile={printProfile} title={`كشف حساب ${statementName}`} filters={{ from_date: from, to_date: to }} />
          <div className="grid gap-3 md:grid-cols-4">
            {[['الرصيد الافتتاحي',`${fmt(data.opening_balance)} ${data.opening_side==='DEBIT'?'مدين':'دائن'}`],['إجمالي المدين',fmt(data.total_debit)],['إجمالي الدائن',fmt(data.total_credit)],['الرصيد الختامي',`${fmt(data.closing_balance)} ${data.closing_side==='DEBIT'?'مدين':'دائن'}`]].map(([t,v]) => <div key={t} className="rounded-2xl border bg-slate-50 p-4"><div className="text-xs font-bold text-slate-500">{t}</div><div className="mt-1 text-xl font-black text-[#0B2A4A]">{v}</div></div>)}
          </div>
          <div className="overflow-x-auto rounded-2xl border"><table className="w-full min-w-[1050px] text-right text-sm"><thead className="bg-slate-100"><tr><th className="p-3">التاريخ</th><th>رقم القيد</th><th>المصدر</th><th>البيان</th><th>الفرع</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead><tbody>{data.rows?.map((r:any) => <tr key={r.id} className="border-t even:bg-slate-50/50"><td className="p-3">{r.entry_date}</td><td className="font-bold">{r.entry_number}</td><td>{r.source_type}</td><td>{r.description||r.entry_description}</td><td>{r.branch_name||'الشركة'}</td><td>{fmt(r.debit)}</td><td>{fmt(r.credit)}</td><td className="font-black">{fmt(r.running_balance)} {r.running_side==='DEBIT'?'مدين':'دائن'}</td></tr>)}</tbody></table></div>
          <div className="print-only border-t pt-3 text-center text-xs text-slate-500">{printProfile?.report_footer || "تم إنشاء الكشف من نظام صلب ERP"}</div>
        </div>
      </>}
      <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} onClose={() => setDialog({ ...dialog, open: false })} onConfirm={() => setDialog({ ...dialog, open: false })} />
    </section>
  );
}
