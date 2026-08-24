"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import PrintHeader from "@/components/reports/PrintHeader";

const fmt = (v: any) =>
  Number(v || 0).toLocaleString("ar", { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const summaryLabel: Record<string, string> = {
  count: "عدد السجلات",
  total: "الإجمالي",
  total_before_vat: "قبل الضريبة",
  vat: "الضريبة",
  balance: "الرصيد",
  balance_kg: "الرصيد كجم",
  stock_value: "قيمة المخزون",
  received_kg: "الوارد كجم",
  remaining_kg: "المتبقي كجم",
  in_kg: "دخول كجم",
  out_kg: "خروج كجم",
  input_kg: "مدخل كجم",
  output_kg: "مخرج كجم",
  loss_kg: "فاقد كجم",
  net_kg: "صافي كجم",
  purchase_total: "المشتريات",
  direct_costs: "تكاليف مباشرة",
  revenue: "الإيراد",
  cogs: "تكلفة المباع",
  cost: "التكلفة",
  profit: "الربح",
  gross_profit: "مجمل الربح",
  expenses: "المصروفات",
  operating_result: "النتيجة التشغيلية",
  debit: "المدين",
  credit: "الدائن",
  opening_debit: "افتتاحي مدين",
  opening_credit: "افتتاحي دائن",
  closing_debit: "ختامي مدين",
  closing_credit: "ختامي دائن",
  difference: "الفرق",
  net_result: "صافي النتيجة",
  total_assets: "إجمالي الأصول",
  total_liabilities: "إجمالي الالتزامات",
  total_equity: "إجمالي حقوق الملكية",
  liabilities_equity: "الالتزامات وحقوق الملكية",
  purchase_cost: "تكلفة الأصول",
  accumulated_depreciation: "مجمع الإهلاك",
  book_value: "القيمة الدفترية",
  depreciation: "الإهلاك",
  net_salary: "صافي الرواتب",
  qty: "الكمية",
};

export default function ReportsPage() {
  const [catalog, setCatalog] = useState<any[]>([]);
  const [branches, setBranches] = useState<any[]>([]);
  const [active, setActive] = useState("executive");
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState("");
  const [filters, setFilters] = useState({ from_date: "", to_date: "", q: "", branch_id: "" });
  const [dialog, setDialog] = useState<any>({ open: false, type: "info", title: "", message: "" });

  async function loadCatalog() {
    try {
      const response = await api.get("/reports/catalog");
      setCatalog(response.data.data?.reports || []);
      setBranches(response.data.data?.branches || []);
    } catch {
      setCatalog([]);
      setBranches([]);
    }
  }

  async function run(key = active) {
    setLoading(true);
    try {
      const response = await api.get(`/reports/run/${key}`, {
        params: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== "")),
      });
      setData(response.data.data);
    } catch (e: any) {
      setData(null);
      setDialog({ open: true, type: "error", title: "تعذر تحميل التقرير", message: e?.response?.data?.message || "حدث خطأ أثناء إعداد التقرير." });
    } finally {
      setLoading(false);
    }
  }

  async function download(format: "csv" | "xls" | "pdf") {
    setDownloading(format);
    try {
      const response = await api.get(`/reports/export/${active}`, {
        params: { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== "")), format },
        responseType: "blob",
      });
      const ext = format;
      const url = URL.createObjectURL(response.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `SULB-${active}-${new Date().toISOString().slice(0, 10)}.${ext}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (e: any) {
      setDialog({ open: true, type: "error", title: "تعذر التصدير", message: e?.response?.data?.message || "تعذر إنشاء ملف التقرير." });
    } finally {
      setDownloading("");
    }
  }

  useEffect(() => { void loadCatalog(); }, []);
  useEffect(() => { void run(active); }, [active]);

  const groups = useMemo(() => {
    const m: Record<string, any[]> = {};
    for (const r of catalog) (m[r.group] ||= []).push(r);
    return m;
  }, [catalog]);

  return (
    <section dir="rtl" className="space-y-5">
      <div className="no-print rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <div className="text-sm text-blue-100">صلب ERP • مركز التقارير</div>
            <h1 className="mt-1 text-3xl font-black">التقارير والتحليلات الشاملة</h1>
            <p className="mt-2 max-w-4xl text-sm leading-7 text-blue-100">
              التشغيل، المخزون، الشحنات، الميزان، الربحية، المالية والأرصدة من مصدر واحد مع طباعة وPDF وExcel وCSV.
            </p>
          </div>
          <a href="/imports" className="rounded-2xl bg-white px-5 py-3 font-black text-[#0B2A4A]">استيراد البيانات</a>
        </div>
      </div>

      <div className="no-print grid gap-4 xl:grid-cols-[290px_1fr]">
        <aside className="space-y-4 rounded-3xl border bg-white p-4 shadow-sm">
          {Object.entries(groups).map(([group, items]) => (
            <div key={group}>
              <div className="mb-2 text-xs font-black text-slate-400">{group}</div>
              <div className="space-y-1">
                {(items as any[]).map((r) => (
                  <button key={r.key} onClick={() => setActive(r.key)} className={`w-full rounded-xl px-3 py-2.5 text-right text-sm font-bold ${active === r.key ? "bg-[#0B2A4A] text-white" : "hover:bg-slate-100"}`}>
                    {r.label}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </aside>

        <div className="space-y-4">
          <div className="grid gap-3 rounded-3xl border bg-white p-4 shadow-sm md:grid-cols-5">
            <input type="date" value={filters.from_date} onChange={(e) => setFilters({ ...filters, from_date: e.target.value })} className="rounded-xl border p-3" />
            <input type="date" value={filters.to_date} onChange={(e) => setFilters({ ...filters, to_date: e.target.value })} className="rounded-xl border p-3" />
            <select value={filters.branch_id} onChange={(e) => setFilters({ ...filters, branch_id: e.target.value })} className="rounded-xl border p-3">
              <option value="">كل الفروع / نطاقي</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.branch_name}</option>)}
            </select>
            <input value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} placeholder="بحث داخل التقرير..." className="rounded-xl border p-3" />
            <button onClick={() => void run()} className="rounded-xl bg-[#0B2A4A] p-3 font-black text-white">{loading ? "جاري التحميل..." : "تطبيق"}</button>
          </div>

          {data && (
            <div className="flex flex-wrap gap-2">
              <button onClick={() => window.print()} className="rounded-xl border bg-white px-4 py-2 font-bold">طباعة</button>
              <button onClick={() => void download("pdf")} disabled={!!downloading} className="rounded-xl border bg-white px-4 py-2 font-bold">{downloading === "pdf" ? "PDF..." : "PDF"}</button>
              <button onClick={() => void download("xls")} disabled={!!downloading} className="rounded-xl border bg-white px-4 py-2 font-bold">{downloading === "xls" ? "Excel..." : "Excel"}</button>
              <button onClick={() => void download("csv")} disabled={!!downloading} className="rounded-xl border bg-white px-4 py-2 font-bold">{downloading === "csv" ? "CSV..." : "CSV"}</button>
            </div>
          )}
        </div>
      </div>

      {data && (
        <div className="sulb-print-area space-y-4 rounded-3xl border bg-white p-5 shadow-sm">
          <PrintHeader profile={data.print_profile} title={data.title} filters={data.filters} />

          {Object.keys(data.summary || {}).length > 0 && (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
              {Object.entries(data.summary).map(([k, v]) => (
                <div key={k} className="rounded-2xl border bg-slate-50 p-3">
                  <div className="text-xs font-bold text-slate-500">{summaryLabel[k] || k}</div>
                  <div className="mt-1 text-lg font-black text-[#0B2A4A]">{typeof v === "number" || !Number.isNaN(Number(v)) ? fmt(v) : String(v ?? "")}</div>
                </div>
              ))}
            </div>
          )}

          <div className="overflow-x-auto rounded-2xl border">
            <table className="w-full min-w-[900px] text-right text-sm">
              <thead className="bg-slate-100 text-[#0B2A4A]">
                <tr>{data.columns?.map((c: any) => <th key={c.key} className="whitespace-nowrap p-3 font-black">{c.label}</th>)}</tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={data.columns?.length || 1} className="p-8 text-center text-slate-500">جاري إعداد التقرير...</td></tr>
                ) : !data.rows?.length ? (
                  <tr><td colSpan={data.columns?.length || 1} className="p-8 text-center text-slate-500">لا توجد بيانات مطابقة.</td></tr>
                ) : data.rows.map((row: any, idx: number) => (
                  <tr key={row.id ?? `${active}-${idx}`} className="border-t even:bg-slate-50/50">
                    {data.columns.map((c: any) => (
                      <td key={c.key} className={`p-3 ${c.type === "number" ? "font-bold tabular-nums" : ""}`}>
                        {c.type === "number" ? fmt(row[c.key]) : String(row[c.key] ?? "—")}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="print-only border-t pt-3 text-center text-xs text-slate-500">
            {data.print_profile?.report_footer || "تم إنشاء التقرير من نظام صلب ERP"}
          </div>
        </div>
      )}

      <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} onClose={() => setDialog({ ...dialog, open: false })} onConfirm={() => setDialog({ ...dialog, open: false })} />
    </section>
  );
}
