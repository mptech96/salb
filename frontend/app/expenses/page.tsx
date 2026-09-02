"use client";
import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import ServerPagination, {
  PaginationMeta,
} from "@/components/finance/ServerPagination";
import Link from "next/link";
const n = (v: any) => Number(v || 0),
  money = (v: any) =>
    n(v).toLocaleString("en-US", {
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    }),
  today = () => new Date().toISOString().slice(0, 10);
export default function ExpensesPage() {
  const [rows, setRows] = useState<any[]>([]),
    [meta, setMeta] = useState<any>({
      branches: [],
      types: [],
      shipments: [],
      cars: [],
      purchases: [],
      sales: [],
      drivers: [],
      workers: [],
      financial_accounts: [],
      currencies: [],
    });
  const [show, setShow] = useState(false),
    [saving, setSaving] = useState(false),
    [loading, setLoading] = useState(true),
    [filters, setFilters] = useState<any>({
      page: 1,
      per_page: 25,
      search: "",
      from_date: "",
      to_date: "",
      expense_type_id: "",
      payment_status: "",
      financial_account_id: "",
      branch_id: "",
    });
  const [pagination, setPagination] = useState<PaginationMeta>({
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
  });
  const [summary, setSummary] = useState<any>({
    filtered_total: 0,
    paid_count: 0,
    unpaid_count: 0,
  });
  const [dialog, setDialog] = useState<any>({
    open: false,
    type: "info",
    title: "",
    message: "",
  });
  const blank = () => ({
    branch_id: "",
    expense_type_id: "",
    expense_date: today(),
    scope_type: "GENERAL",
    reference_id: "",
    amount: "",
    payment_status: "PAID",
    payment_method: "CASH",
    financial_account_id: "",
    currency_code: "",
    exchange_rate: 1,
    expense_effect: "COST",
    notes: "",
  });
  const [form, setForm] = useState<any>(blank());
  const say = (type: string, title: string, message: string) =>
    setDialog({ open: true, type, title, message });
  const apiMsg = (e: any) =>
    e?.response?.data?.message || e?.message || "تعذر إكمال العملية.";
  async function load(next = filters) {
    setLoading(true);
    try {
      const [r, m] = await Promise.all([
        api.get("/expenses", {
          params: Object.fromEntries(
            Object.entries(next).filter(([, v]) => v !== ""),
          ),
        }),
        api.get("/expenses/meta"),
      ]);
      const md = m.data.data || {},
        pg = r.data.data || {};
      setRows(pg.data || []);
      setPagination(pg);
      setSummary(r.data.summary || {});
      setMeta(md);
      setForm((f: any) => ({
        ...f,
        branch_id:
          f.branch_id ||
          String(md.scoped_branch_id || md.branches?.[0]?.id || ""),
        currency_code:
          f.currency_code ||
          md.base_currency ||
          md.currencies?.[0]?.currency_code ||
          "",
      }));
    } catch (e: any) {
      say("error", "تعذر تحميل المصروفات", apiMsg(e));
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    const timer = setTimeout(() => void load(filters), 300);
    return () => clearTimeout(timer);
  }, [filters]);
  const branch = String(form.branch_id || "");
  const financial = useMemo(
    () =>
      meta.financial_accounts?.filter(
        (x: any) =>
          (!x.branch_id || String(x.branch_id) === branch) &&
          Number(x.is_active) === 1,
      ) || [],
    [meta.financial_accounts, branch],
  );
  const refOptions = useMemo(() => {
    const fl = (a: any[]) =>
      a.filter((x) => !x.branch_id || String(x.branch_id) === branch);
    if (form.scope_type === "SHIPMENT")
      return fl(meta.shipments || []).map((x) => ({
        id: x.id,
        name: x.shipment_number || `#${x.id}`,
      }));
    if (form.scope_type === "CAR")
      return fl(meta.cars || []).map((x) => ({
        id: x.id,
        name: x.plate_number || x.car_number || `#${x.id}`,
      }));
    if (form.scope_type === "PURCHASE_INVOICE")
      return fl(meta.purchases || []).map((x) => ({
        id: x.id,
        name: x.invoice_number || `#${x.id}`,
      }));
    if (form.scope_type === "SALES_INVOICE")
      return fl(meta.sales || []).map((x) => ({
        id: x.id,
        name: x.invoice_number || `#${x.id}`,
      }));
    if (form.scope_type === "DRIVER")
      return fl(meta.drivers || []).map((x) => ({
        id: x.id,
        name: x.driver_name,
      }));
    if (form.scope_type === "WORKER")
      return fl(meta.workers || []).map((x) => ({
        id: x.id,
        name: x.worker_name,
      }));
    return [];
  }, [form.scope_type, branch, meta]);
  const doc = n(form.amount),
    base = doc * n(form.exchange_rate || 1);
  function openNew() {
    setForm({
      ...blank(),
      branch_id: String(meta.scoped_branch_id || meta.branches?.[0]?.id || ""),
      currency_code:
        meta.base_currency || meta.currencies?.[0]?.currency_code || "",
    });
    setShow(true);
  }
  function method(v: string) {
    const wanted = ["BANK", "BANK_TRANSFER", "CARD"].includes(v)
      ? "BANK"
      : v === "WALLET"
        ? "WALLET"
        : "CASH";
    const x =
      financial.find((a: any) => a.account_type === wanted) || financial[0];
    setForm({
      ...form,
      payment_method: v,
      financial_account_id: x ? String(x.id) : "",
      currency_code: x?.currency_code || form.currency_code,
      exchange_rate:
        (x?.currency_code || form.currency_code) === meta.base_currency
          ? 1
          : form.exchange_rate,
    });
  }
  async function save() {
    if (
      !form.branch_id ||
      !form.expense_type_id ||
      !form.expense_date ||
      doc <= 0
    )
      return say(
        "warning",
        "بيانات ناقصة",
        "حدد الفرع ونوع المصروف والتاريخ والمبلغ.",
      );
    if (form.scope_type !== "GENERAL" && !form.reference_id)
      return say("warning", "بيانات ناقصة", "حدد مرجع المصروف.");
    if (form.payment_status === "PAID" && !form.financial_account_id)
      return say(
        "warning",
        "بيانات ناقصة",
        "حدد الخزينة أو البنك الذي تم منه الدفع.",
      );
    setSaving(true);
    try {
      const p: any = {
        branch_id: Number(form.branch_id),
        expense_type_id: Number(form.expense_type_id),
        expense_date: form.expense_date,
        scope_type: form.scope_type,
        reference_id: form.reference_id ? Number(form.reference_id) : null,
        amount: base,
        payment_status: form.payment_status,
        payment_method: form.payment_method,
        financial_account_id: form.financial_account_id
          ? Number(form.financial_account_id)
          : null,
        currency_code: form.currency_code,
        exchange_rate: n(form.exchange_rate) || 1,
        foreign_amount: form.currency_code !== meta.base_currency ? doc : null,
        expense_effect: form.expense_effect,
        notes: form.notes || null,
      };
      await api.post("/expenses", p);
      say(
        "success",
        "تم ترحيل المصروف",
        form.payment_status === "PAID"
          ? `تم الصرف من ${financial.find((x: any) => String(x.id) === String(form.financial_account_id))?.account_name || "الحساب المالي"} وإنشاء السند والقيد.`
          : "تم إثبات المصروف كمستحق دون حركة نقدية.",
      );
      setShow(false);
      await load();
    } catch (e: any) {
      say("error", "تعذر حفظ المصروف", apiMsg(e));
    } finally {
      setSaving(false);
    }
  }
  return (
    <section dir="rtl" className="space-y-5">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white">
        <div className="flex items-end justify-between">
          <div>
            <h1 className="text-3xl font-black">مركز المصروفات</h1>
            <p className="mt-2 text-sm text-blue-100">
              مصروفات حسب الفرع ومركز النشاط والخزينة/البنك، مع دعم المستحق
              والعملات.
            </p>
          </div>
          <button
            onClick={openNew}
            className="rounded-2xl bg-white px-5 py-3 font-black text-[#0B2A4A]"
          >
            + مصروف جديد
          </button>
        </div>
      </div>
      <div className="grid gap-3 md:grid-cols-4">
        <Card t="نتائج الفلتر" v={pagination.total} />
        <Card t="إجمالي نتائج الفلتر" v={money(summary.filtered_total)} />
        <Card t="مدفوعة" v={summary.paid_count} />
        <Card t="مستحقة" v={summary.unpaid_count} />
      </div>
      <div className="grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-3 xl:grid-cols-8">
        <input
          className="rounded-xl border p-3 xl:col-span-2"
          placeholder="بحث في البيان أو المرجع..."
          value={filters.search}
          onChange={(e) =>
            setFilters({ ...filters, search: e.target.value, page: 1 })
          }
        />
        <input
          type="date"
          className="rounded-xl border p-3"
          value={filters.from_date}
          onChange={(e) =>
            setFilters({ ...filters, from_date: e.target.value, page: 1 })
          }
        />
        <input
          type="date"
          className="rounded-xl border p-3"
          value={filters.to_date}
          onChange={(e) =>
            setFilters({ ...filters, to_date: e.target.value, page: 1 })
          }
        />
        <select
          className="rounded-xl border p-3"
          value={filters.expense_type_id}
          onChange={(e) =>
            setFilters({ ...filters, expense_type_id: e.target.value, page: 1 })
          }
        >
          <option value="">كل الأنواع</option>
          {meta.types?.map((x: any) => (
            <option key={x.id} value={x.id}>
              {x.type_name}
            </option>
          ))}
        </select>
        <select
          className="rounded-xl border p-3"
          value={filters.payment_status}
          onChange={(e) =>
            setFilters({ ...filters, payment_status: e.target.value, page: 1 })
          }
        >
          <option value="">كل الحالات</option>
          <option value="PAID">مدفوع</option>
          <option value="UNPAID">مستحق</option>
        </select>
        {meta.branches?.length > 1 && (
          <select className="rounded-xl border p-3" value={filters.branch_id} onChange={(e)=>setFilters({...filters,branch_id:e.target.value,page:1})}>
            <option value="">كل الفروع</option>
            {meta.branches.map((branch:any)=><option key={branch.id} value={branch.id}>{branch.branch_name}</option>)}
          </select>
        )}
        <select className="rounded-xl border p-3" value={filters.financial_account_id} onChange={(e)=>setFilters({...filters,financial_account_id:e.target.value,page:1})}>
          <option value="">كل الخزائن والبنوك</option>
          {meta.financial_accounts?.map((account:any)=><option key={account.id} value={account.id}>{account.account_name}</option>)}
        </select>
      </div>
      <div className="overflow-hidden rounded-3xl border bg-white">
        <div className="overflow-x-auto">
          <table className="min-w-[1250px] w-full text-right">
            <thead className="bg-slate-100">
              <tr>
                {[
                  "التاريخ",
                  "الفرع",
                  "النوع",
                  "المبلغ الدفتري",
                  "العملة",
                  "الحالة",
                  "الخزينة/البنك",
                  "المرجع",
                  "السند",
                  "القيد",
                ].map((x) => (
                  <th className="p-4" key={x}>
                    {x}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {!rows.length ? (
                <tr>
                  <td colSpan={10} className="p-8 text-center text-slate-500">
                    {loading ? "جاري التحميل..." : "لا توجد مصروفات"}
                  </td>
                </tr>
              ) : (
                rows.map((x: any) => (
                  <tr key={x.id} className="border-t">
                    <td className="p-4">{x.expense_date}</td>
                    <td className="p-4">
                      {meta.branches?.find(
                        (b: any) => String(b.id) === String(x.branch_id),
                      )?.branch_name ||
                        x.branch_id ||
                        "-"}
                    </td>
                    <td className="p-4 font-black">{x.type_name || "-"}</td>
                    <td className="p-4 font-black">{money(x.amount)}</td>
                    <td className="p-4">
                      {x.currency_code || meta.base_currency}
                    </td>
                    <td className="p-4">
                      {x.payment_status === "PAID" ? "مدفوع" : "مستحق"}
                    </td>
                    <td className="p-4">{x.financial_account_name || "-"}</td>
                    <td className="p-4">
                      {x.shipment_number ||
                        x.car_number ||
                        x.purchase_invoice_number ||
                        x.sales_invoice_number ||
                        x.driver_name ||
                        x.worker_name ||
                        "عام"}
                    </td>
                    <td className="p-4">{x.voucher_number || "-"}</td>
                    <td className="p-4">{x.journal_entry_id || "-"}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        <ServerPagination
          meta={pagination}
          onPage={(page) => setFilters({ ...filters, page })}
          onPerPage={(per_page) =>
            setFilters({ ...filters, per_page, page: 1 })
          }
        />
      </div>
      {show && (
        <Modal title="مصروف جديد" close={() => setShow(false)}>
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-blue-50 p-3 text-sm">
            <span>نوع المصروف يحدد حساب المصروف في دفتر الأستاذ.</span>
            <Link href="/expense-types" className="font-black text-blue-700">
              + إضافة نوع مصروف
            </Link>
          </div>
          <div className="grid gap-4 md:grid-cols-3">
            <Select
              l="الفرع *"
              v={form.branch_id}
              set={(v) =>
                setForm({
                  ...form,
                  branch_id: v,
                  reference_id: "",
                  financial_account_id: "",
                })
              }
              o={(meta.branches || []).map((x: any) => ({
                id: x.id,
                name: x.branch_name,
              }))}
            />
            <Select
              l="نوع المصروف *"
              v={form.expense_type_id}
              set={(v) => {
                const t = meta.types?.find((x: any) => String(x.id) === v);
                setForm({
                  ...form,
                  expense_type_id: v,
                  scope_type: t?.default_scope || form.scope_type,
                  reference_id: "",
                });
              }}
              o={(meta.types || []).map((x: any) => ({
                id: x.id,
                name: `${x.type_name}${x.account_code ? ` — ${x.account_code} ${x.account_name || ""}` : ""}`,
              }))}
            />
            <Input
              l="التاريخ"
              type="date"
              v={form.expense_date}
              set={(v) => setForm({ ...form, expense_date: v })}
            />
            <Select
              l="مرتبط بـ"
              v={form.scope_type}
              set={(v) => setForm({ ...form, scope_type: v, reference_id: "" })}
              o={[
                { id: "GENERAL", name: "عام" },
                { id: "SHIPMENT", name: "شحنة" },
                { id: "CAR", name: "سيارة" },
                { id: "PURCHASE_INVOICE", name: "فاتورة شراء" },
                { id: "SALES_INVOICE", name: "فاتورة بيع" },
                { id: "DRIVER", name: "سائق" },
                { id: "WORKER", name: "عامل" },
              ]}
            />
            {form.scope_type !== "GENERAL" && (
              <Select
                l="المرجع"
                v={form.reference_id}
                set={(v) => setForm({ ...form, reference_id: v })}
                o={refOptions}
              />
            )}
            <Select
              l="حالة الدفع"
              v={form.payment_status}
              set={(v) => setForm({ ...form, payment_status: v })}
              o={[
                { id: "PAID", name: "مدفوع الآن" },
                { id: "UNPAID", name: "مستحق / غير مدفوع" },
              ]}
            />
            <Select
              l="طريقة الدفع"
              v={form.payment_method}
              set={method}
              o={[
                { id: "CASH", name: "نقد" },
                { id: "BANK", name: "بنك" },
                { id: "BANK_TRANSFER", name: "تحويل بنكي" },
                { id: "CARD", name: "بطاقة" },
                { id: "WALLET", name: "محفظة" },
              ]}
            />
            {form.payment_status === "PAID" && (
              <Select
                l="الخزينة / البنك *"
                v={form.financial_account_id}
                set={(v) => {
                  const x = financial.find((a: any) => String(a.id) === v);
                  setForm({
                    ...form,
                    financial_account_id: v,
                    currency_code: x?.currency_code || form.currency_code,
                    exchange_rate:
                      (x?.currency_code || form.currency_code) ===
                      meta.base_currency
                        ? 1
                        : form.exchange_rate,
                  });
                }}
                o={financial.map((x: any) => ({
                  id: x.id,
                  name: `${x.account_name} — ${x.branch_name || "مركزي"} — ${x.currency_code}`,
                }))}
              />
            )}
            <Select
              l="العملة"
              v={form.currency_code}
              set={(v) =>
                setForm({
                  ...form,
                  currency_code: v,
                  exchange_rate:
                    v === meta.base_currency ? 1 : form.exchange_rate,
                })
              }
              o={(meta.currencies || []).map((x: any) => ({
                id: x.currency_code,
                name: `${x.currency_code} - ${x.currency_name}`,
              }))}
            />
            <Input
              l={`المبلغ (${form.currency_code})`}
              type="number"
              v={form.amount}
              set={(v) => setForm({ ...form, amount: v })}
            />
            <Input
              l={`سعر الصرف إلى ${meta.base_currency || "الأساسية"}`}
              type="number"
              v={form.exchange_rate}
              set={(v) => setForm({ ...form, exchange_rate: v })}
            />
            <Select
              l="تصنيف الأثر"
              v={form.expense_effect}
              set={(v) => setForm({ ...form, expense_effect: v })}
              o={[
                { id: "COST", name: "تشغيلي / تكلفة" },
                { id: "ADMIN", name: "إداري" },
              ]}
            />
            <div className="rounded-2xl bg-slate-50 p-4">
              <div className="text-xs text-slate-500">القيمة الدفترية</div>
              <div className="text-xl font-black">
                {money(base)} {meta.base_currency}
              </div>
            </div>
          </div>
          <textarea
            rows={3}
            className="w-full rounded-2xl border bg-slate-50 p-4"
            placeholder="ملاحظات"
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
          />
          <button
            disabled={saving}
            onClick={save}
            className="w-full rounded-2xl bg-[#0B2A4A] p-4 font-black text-white disabled:opacity-50"
          >
            {saving ? "جاري الحفظ..." : "حفظ وترحيل المصروف"}
          </button>
        </Modal>
      )}
      <SystemDialog
        {...dialog}
        onClose={() => setDialog((d: any) => ({ ...d, open: false }))}
        onConfirm={() => setDialog((d: any) => ({ ...d, open: false }))}
      />
    </section>
  );
}
function Card({ t, v }: any) {
  return (
    <div className="rounded-3xl border bg-white p-5">
      <div className="text-sm text-slate-500">{t}</div>
      <div className="mt-2 text-2xl font-black text-[#0B2A4A]">{v}</div>
    </div>
  );
}
function Modal({ title, close, children }: any) {
  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/45 p-4">
      <div className="mx-auto max-w-6xl rounded-3xl bg-white">
        <div className="flex justify-between border-b p-5">
          <h2 className="text-2xl font-black">{title}</h2>
          <button onClick={close} className="rounded-xl bg-slate-100 px-4 py-2">
            إغلاق
          </button>
        </div>
        <div className="space-y-4 p-5">{children}</div>
      </div>
    </div>
  );
}
function Input({
  l,
  v,
  set,
  type = "text",
}: {
  l: string;
  v: any;
  set: (v: string) => void;
  type?: string;
}) {
  return (
    <label>
      <span className="mb-1 block text-sm font-bold">{l}</span>
      <input
        type={type}
        step="0.001"
        className="w-full rounded-2xl border bg-slate-50 p-3"
        value={v ?? ""}
        onChange={(e) => set(e.target.value)}
      />
    </label>
  );
}
function Select({
  l,
  v,
  set,
  o,
}: {
  l: string;
  v: any;
  set: (v: string) => void;
  o: any[];
}) {
  return (
    <label>
      <span className="mb-1 block text-sm font-bold">{l}</span>
      <select
        className="w-full rounded-2xl border bg-slate-50 p-3"
        value={v ?? ""}
        onChange={(e) => set(e.target.value)}
      >
        <option value="">اختر</option>
        {o.map((x: any, i: number) => (
          <option key={`${x.id}-${i}`} value={x.id}>
            {x.name}
          </option>
        ))}
      </select>
    </label>
  );
}
