"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import useSystemFeedback from "@/components/common/useSystemFeedback";
import {
  DataTableShell,
  EmptyState,
  FilterBar,
  FormField,
  LoadingState,
  PageHeader,
  StatCard,
  StatusBadge,
  fieldClassName,
  primaryButtonClassName,
  secondaryButtonClassName,
} from "@/components/ui/enterprise";
import { readSession } from "@/lib/session";

type ExpenseType = {
  id: number;
  company_id: number | null;
  type_name: string;
  type_code?: string | null;
  account_id?: number | null;
  account_code?: string | null;
  account_name?: string | null;
  default_scope?: string | null;
  affects_cost?: number;
  description?: string | null;
  is_active?: number;
  is_system?: number;
};

type ExpenseAccount = { id: number; account_code: string; account_name: string };
type ApiFailure = { response?: { data?: { message?: string } } };

function apiMessage(error: unknown, fallback: string) {
  return (error as ApiFailure)?.response?.data?.message || fallback;
}

const emptyForm = () => ({
  type_name: "", type_code: "", account_id: "", default_scope: "GENERAL",
  affects_cost: 1, description: "", is_active: 1,
});

export default function ExpenseTypesPage() {
  const [types, setTypes] = useState<ExpenseType[]>([]);
  const [accounts, setAccounts] = useState<ExpenseAccount[]>([]);
  const [form, setForm] = useState(emptyForm());
  const [selected, setSelected] = useState<ExpenseType | null>(null);
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deactivatingId, setDeactivatingId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const { notify, requestConfirmation, feedbackDialog } = useSystemFeedback();
  const session = readSession();
  const readOnly = Boolean(session?.user.is_support_mode && session.user.support_access_level !== "WRITE");
  const role = String(session?.user.role?.role_code || "").toUpperCase();
  const manager = ["MANAGER", "COMPANY_MANAGER", "COMPANY_ADMIN", "COMPANY_OWNER", "ADMIN"].includes(role);
  const canCreate = !readOnly && (manager || session?.permissions.includes("expenses.create"));
  const canUpdate = !readOnly && (manager || session?.permissions.includes("expenses.update"));
  const canDelete = !readOnly && (manager || session?.permissions.includes("expenses.delete"));

  async function loadAll() {
    setLoading(true); setError("");
    try {
      const [typeResponse, accountResponse] = await Promise.all([
        api.get("/expense-types"), api.get("/expense-types/accounts"),
      ]);
      setTypes(typeResponse.data.data || []);
      setAccounts(accountResponse.data.data || []);
    } catch (requestError: unknown) {
      setError(apiMessage(requestError, "تعذر تحميل أنواع المصروفات."));
    } finally { setLoading(false); }
  }

  // Initial API synchronization is intentionally performed once on mount.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { void loadAll(); }, []);

  const filtered = useMemo(() => types.filter((row) =>
    `${row.type_name} ${row.type_code || ""} ${row.account_name || ""} ${row.account_code || ""}`
      .toLowerCase().includes(search.trim().toLowerCase())), [types, search]);

  function openNew() { setSelected(null); setForm(emptyForm()); setOpen(true); }
  function openEdit(row: ExpenseType) {
    if (Number(row.is_system) === 1) return;
    setSelected(row);
    setForm({
      type_name: row.type_name || "", type_code: row.type_code || "", account_id: row.account_id ? String(row.account_id) : "",
      default_scope: row.default_scope || "GENERAL", affects_cost: Number(row.affects_cost ?? 1),
      description: row.description || "", is_active: Number(row.is_active ?? 1),
    });
    setOpen(true);
  }

  async function save() {
    if (saving) return;
    if (!form.type_name.trim()) return notify("اسم نوع المصروف مطلوب.", "warning");
    if (!form.account_id) return notify("اختر حساب مصروف صالحًا من دليل الحسابات.", "warning");
    setSaving(true);
    try {
      const payload = { ...form, account_id: Number(form.account_id), affects_cost: Number(form.affects_cost), is_active: Number(form.is_active) };
      if (selected) await api.put(`/expense-types/${selected.id}`, payload);
      else await api.post("/expense-types", payload);
      setOpen(false); await loadAll(); notify(selected ? "تم تحديث نوع المصروف." : "تم إنشاء نوع المصروف دون أي حركة مالية.", "success");
    } catch (requestError: unknown) {
      notify(apiMessage(requestError, "تعذر حفظ نوع المصروف."), "error");
    } finally { setSaving(false); }
  }

  function deactivate(row: ExpenseType) {
    if (deactivatingId !== null) return;
    requestConfirmation("سيتم تعطيل النوع مع الاحتفاظ بأي معاملات مالية مرتبطة به.", async () => {
      setDeactivatingId(row.id);
      try {
        await api.delete(`/expense-types/${row.id}`); await loadAll();
        notify("تم تعطيل نوع المصروف.", "success");
      } catch (requestError: unknown) {
        notify(apiMessage(requestError, "تعذر تعطيل نوع المصروف."), "error");
      } finally {
        setDeactivatingId(null);
      }
    }, "تعطيل نوع المصروف");
  }

  return <section dir="rtl" className="min-w-0 space-y-4">
    <PageHeader title="أنواع المصروفات" description="تعريفات تشغيلية ترتبط بحساب مصروف صالح. إنشاء النوع لا ينشئ مصروفًا أو سندًا أو قيدًا." breadcrumbs={[{ label: "الإعدادات" }, { label: "أنواع المصروفات" }]} actions={canCreate ? <button className={primaryButtonClassName} onClick={openNew}>+ نوع مصروف جديد</button> : undefined}/>
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <StatCard label="إجمالي الأنواع" value={types.length}/>
      <StatCard label="الأنواع النظامية" value={types.filter((x) => Number(x.is_system) === 1).length}/>
      <StatCard label="أنواع الشركة" value={types.filter((x) => Number(x.is_system) !== 1).length}/>
      <StatCard label="النشطة" value={types.filter((x) => Number(x.is_active) === 1).length} tone="positive"/>
    </div>
    {readOnly ? <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">وضع الدعم للقراءة فقط — عمليات الإضافة والتعديل والتعطيل غير متاحة.</div> : null}
    {error ? <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error} <button className="mr-2 underline" onClick={() => void loadAll()}>إعادة المحاولة</button></div> : null}
    <FilterBar><input className={fieldClassName} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="ابحث بالاسم أو الكود أو الحساب..."/></FilterBar>
    <DataTableShell title="دليل أنواع المصروفات" description={`${filtered.length} سجل مطابق`}>
      {loading ? <LoadingState/> : filtered.length === 0 ? <EmptyState title="لا توجد أنواع مصروفات" description="أنشئ نوعًا خاصًا بالشركة واربطه بحساب مصروف صالح."/> :
        <table className="enterprise-table min-w-[980px] text-right"><thead><tr><th>النوع</th><th>الكود</th><th>المصدر</th><th>الحساب</th><th>النطاق</th><th>التكلفة</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
          {filtered.map((row) => { const system = Number(row.is_system) === 1; return <tr key={row.id}>
            <td className="font-semibold text-slate-900">{row.type_name}</td><td className="tabular-nums">{row.type_code || "—"}</td>
            <td><StatusBadge tone={system ? "info" : "neutral"}>{system ? "نوع نظامي" : "خاص بالشركة"}</StatusBadge></td>
            <td>{row.account_code ? `${row.account_code} — ${row.account_name}` : system ? "الحساب الافتراضي عند الاستخدام" : "—"}</td>
            <td>{scopeLabel(row.default_scope)}</td><td>{Number(row.affects_cost) === 1 ? "يؤثر" : "إداري"}</td>
            <td><StatusBadge tone={Number(row.is_active) === 1 ? "success" : "neutral"}>{Number(row.is_active) === 1 ? "نشط" : "متوقف"}</StatusBadge></td>
            <td><div className="flex gap-2">{system ? <span className="text-xs text-slate-500">للقراءة فقط</span> : <>{canUpdate ? <button className={secondaryButtonClassName} onClick={() => openEdit(row)}>تعديل</button> : null}{Number(row.is_active) === 1 && canDelete ? <button disabled={deactivatingId !== null} className="inline-flex min-h-10 items-center rounded-lg bg-rose-50 px-3 text-sm font-semibold text-rose-700 disabled:opacity-50" onClick={() => deactivate(row)}>{deactivatingId === row.id ? "جاري التعطيل..." : "تعطيل"}</button> : null}</>}</div></td>
          </tr>; })}
        </tbody></table>}
    </DataTableShell>
    {open ? <div className="fixed inset-0 z-[190] bg-slate-950/45 p-3"><aside className="mr-auto h-full w-full max-w-2xl overflow-y-auto bg-white p-5 shadow-2xl sm:rounded-xl">
      <div className="flex items-center justify-between"><div><h2 className="text-lg font-bold">{selected ? "تعديل نوع مصروف" : "نوع مصروف جديد"}</h2><p className="mt-1 text-xs text-slate-500">التعريف وحده لا ينشئ أي أثر مالي.</p></div><button className={secondaryButtonClassName} disabled={saving} onClick={() => setOpen(false)}>إغلاق</button></div>
      <div className="mt-5 grid gap-4 sm:grid-cols-2">
        <FormField label="اسم النوع" required><input className={fieldClassName} value={form.type_name} onChange={(e) => setForm({ ...form, type_name: e.target.value })}/></FormField>
        <FormField label="الكود" hint="اختياري؛ يولّد الخادم كودًا آمنًا عند تركه فارغًا."><input className={fieldClassName} dir="ltr" value={form.type_code} onChange={(e) => setForm({ ...form, type_code: e.target.value })}/></FormField>
        <FormField label="حساب المصروف" required className="sm:col-span-2" hint="تظهر فقط حسابات EXPENSE النشطة والقابلة للترحيل التابعة للشركة."><select className={fieldClassName} value={form.account_id} onChange={(e) => setForm({ ...form, account_id: e.target.value })}><option value="">اختر حساب المصروف...</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.account_code} — {a.account_name}</option>)}</select></FormField>
        <FormField label="الارتباط الافتراضي"><select className={fieldClassName} value={form.default_scope} onChange={(e) => setForm({ ...form, default_scope: e.target.value })}>{scopeOptions.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></FormField>
        <FormField label="تأثير التكلفة"><select className={fieldClassName} value={form.affects_cost} onChange={(e) => setForm({ ...form, affects_cost: Number(e.target.value) })}><option value={1}>يؤثر على التكلفة</option><option value={0}>مصروف إداري</option></select></FormField>
        <FormField label="الحالة"><select className={fieldClassName} value={form.is_active} onChange={(e) => setForm({ ...form, is_active: Number(e.target.value) })}><option value={1}>نشط</option><option value={0}>متوقف</option></select></FormField>
        <FormField label="الوصف" className="sm:col-span-2"><textarea rows={4} className={fieldClassName} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })}/></FormField>
      </div><div className="sticky bottom-0 mt-5 flex justify-end border-t bg-white py-4"><button className={primaryButtonClassName} disabled={saving} onClick={() => void save()}>{saving ? "جاري الحفظ..." : "حفظ النوع"}</button></div>
    </aside></div> : null}
    {feedbackDialog}
  </section>;
}

const scopeOptions = [
  { id: "GENERAL", name: "عام" }, { id: "SHIPMENT", name: "شحنة" }, { id: "CAR", name: "سيارة" },
  { id: "PURCHASE_INVOICE", name: "فاتورة شراء" }, { id: "SALES_INVOICE", name: "فاتورة بيع" },
  { id: "DRIVER", name: "سائق" }, { id: "WORKER", name: "عامل" },
];
function scopeLabel(value?: string | null) { return scopeOptions.find((x) => x.id === value)?.name || value || "—"; }
