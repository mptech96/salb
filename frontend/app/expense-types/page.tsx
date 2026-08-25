"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import useSystemFeedback from "@/components/common/useSystemFeedback";

export default function ExpenseTypesPage() {
  const [types, setTypes] = useState<any[]>([]);
  const [accounts, setAccounts] = useState<any[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [selected, setSelected] = useState<any>(null);
  const [search, setSearch] = useState("");
  const [saving, setSaving] = useState(false);

  const [form, setForm] = useState(defaultForm());
  const { notify, requestConfirmation, feedbackDialog } = useSystemFeedback();

  useEffect(() => {
    loadAll();
  }, []);

  async function loadAll() {
    const [t, a] = await Promise.all([
      api.get("/expense-types"),
      api.get("/expense-types/accounts"),
    ]);

    setTypes(t.data.data || []);
    setAccounts(a.data.data || []);
  }

  function openNew() {
    setSelected(null);
    setForm(defaultForm());
    setShowForm(true);
  }

  function openEdit(row: any) {
    setSelected(row);
    setForm({
      type_name: row.type_name || "",
      type_code: row.type_code || "",
      account_id: row.account_id || "",
      default_scope: row.default_scope || "GENERAL",
      affects_cost: Number(row.affects_cost ?? 1),
      description: row.description || "",
      is_active: Number(row.is_active ?? 1),
    });
    setShowForm(true);
  }

  async function saveType() {
    if (saving) return;
    if (!form.type_name.trim()) return notify("اكتب اسم نوع المصروف", "warning");

    try {
      setSaving(true);

      const payload = {
        ...form,
        account_id: form.account_id || null,
        affects_cost: Number(form.affects_cost),
        is_active: Number(form.is_active),
      };

      if (selected) {
        await api.put(`/expense-types/${selected.id}`, payload);
        notify("تم تعديل نوع المصروف", "success");
      } else {
        await api.post("/expense-types", payload);
        notify("تم إنشاء نوع المصروف", "success");
      }

      setShowForm(false);
      await loadAll();
    } catch (e: any) {
      notify(e?.response?.data?.message || "فشل حفظ نوع المصروف", "error");
    } finally {
      setSaving(false);
    }
  }

  function stopType(id: number) {
    requestConfirmation("إيقاف نوع المصروف؟ لن يظهر كمصروف نشط.", async () => {
      await api.delete(`/expense-types/${id}`);
      await loadAll();
    }, "تأكيد إيقاف نوع المصروف");
  }

  const filtered = useMemo(() => {
    return types.filter((x) =>
      `${x.type_name || ""} ${x.type_code || ""} ${x.account_name || ""} ${x.account_code || ""}`
        .toLowerCase()
        .includes(search.toLowerCase())
    );
  }, [types, search]);

  return (
    <section dir="rtl" className="space-y-5">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p className="text-sm text-blue-100">إعدادات المصروفات</p>
            <h1 className="mt-2 text-3xl font-black">أنواع المصروفات</h1>
            <p className="mt-2 text-sm text-blue-100">
              كل نوع مصروف يرتبط بحساب محاسبي. عند تسجيل المصروف، النظام ينشئ سند وقيد تلقائيًا.
            </p>
          </div>

          <button
            onClick={openNew}
            className="rounded-2xl bg-white px-5 py-3 font-bold text-[#0B2A4A]"
          >
            + نوع مصروف جديد
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Stat title="إجمالي الأنواع" value={types.length} />
        <Stat title="الأنواع النشطة" value={types.filter((x) => Number(x.is_active) === 1).length} />
        <Stat title="مرتبطة بحساب" value={types.filter((x) => x.account_id).length} />
        <Stat title="تؤثر على التكلفة" value={types.filter((x) => Number(x.affects_cost) === 1).length} />
      </div>

      <div className="rounded-3xl border bg-white p-4 shadow-sm">
        <input
          className="w-full rounded-2xl border bg-slate-50 p-4 outline-none"
          placeholder="بحث باسم النوع أو الكود أو الحساب..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="overflow-hidden rounded-3xl border bg-white shadow-sm">
        <div className="border-b p-4">
          <h2 className="text-xl font-black text-[#0B2A4A]">قائمة أنواع المصروفات</h2>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-[1000px] w-full text-right">
            <thead className="bg-slate-100">
              <tr>
                <th className="p-4">النوع</th>
                <th className="p-4">الكود</th>
                <th className="p-4">الحساب المحاسبي</th>
                <th className="p-4">الارتباط الافتراضي</th>
                <th className="p-4">تأثير التكلفة</th>
                <th className="p-4">الحالة</th>
                <th className="p-4">الإجراءات</th>
              </tr>
            </thead>

            <tbody>
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={7} className="p-6 text-center text-slate-500">
                    لا توجد أنواع مصروفات
                  </td>
                </tr>
              ) : (
                filtered.map((row) => (
                  <tr key={row.id} className="border-t hover:bg-slate-50">
                    <td className="p-4 font-black text-[#0B2A4A]">{row.type_name}</td>
                    <td className="p-4">{row.type_code || "-"}</td>
                    <td className="p-4">
                      {row.account_code ? (
                        <span className="font-bold">
                          {row.account_code} - {row.account_name}
                        </span>
                      ) : (
                        <span className="text-rose-600 font-bold">بدون حساب</span>
                      )}
                    </td>
                    <td className="p-4">{scopeLabel(row.default_scope)}</td>
                    <td className="p-4">
                      {Number(row.affects_cost) === 1 ? "يدخل في تكلفة التشغيل" : "مصروف إداري فقط"}
                    </td>
                    <td className="p-4">
                      <span className={`rounded-full px-3 py-1 text-xs font-bold ${
                        Number(row.is_active) === 1
                          ? "bg-emerald-100 text-emerald-700"
                          : "bg-slate-100 text-slate-600"
                      }`}>
                        {Number(row.is_active) === 1 ? "نشط" : "موقوف"}
                      </span>
                    </td>
                    <td className="p-4">
                      <div className="flex gap-2">
                        <button
                          onClick={() => openEdit(row)}
                          className="rounded-xl bg-blue-700 px-3 py-2 text-sm font-bold text-white"
                        >
                          تعديل
                        </button>
                        <button
                          onClick={() => stopType(row.id)}
                          className="rounded-xl bg-rose-600 px-3 py-2 text-sm font-bold text-white"
                        >
                          إيقاف
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showForm && (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
          <div className="mx-auto max-w-5xl rounded-3xl bg-white shadow-2xl">
            <div className="border-b p-5">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-2xl font-black text-[#0B2A4A]">
                    {selected ? "تعديل نوع مصروف" : "إضافة نوع مصروف"}
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">
                    اختر الحساب المحاسبي المناسب. إذا تركته فارغًا، سيقوم النظام بإنشاء حساب مصروف تلقائيًا.
                  </p>
                </div>

                <button
                  onClick={() => setShowForm(false)}
                  className="rounded-2xl bg-slate-200 px-5 py-3 font-bold"
                >
                  إغلاق
                </button>
              </div>
            </div>

            <div className="space-y-5 p-5">
              <InfoBox
                title="كيف يعمل هذا؟"
                text="عند تسجيل مصروف من هذا النوع، النظام سيستخدم الحساب المختار هنا في القيد المحاسبي. مثال: وقود = مصروف وقود، إيجار = مصروف إيجارات."
              />

              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Input
                  label="اسم نوع المصروف *"
                  value={form.type_name}
                  onChange={(v: string) => setForm({ ...form, type_name: v })}
                  hint="مثال: وقود، إيجار، صيانة، عمولة شراء، كهرباء."
                />

                <Input
                  label="كود النوع"
                  value={form.type_code}
                  onChange={(v: string) => setForm({ ...form, type_code: v })}
                  hint="اختياري. إذا تركته فارغًا سيولده النظام."
                />

                <Select
                  label="الحساب المحاسبي"
                  value={form.account_id}
                  onChange={(v: string) => setForm({ ...form, account_id: v })}
                  hint="إذا لم تختار حساب، النظام ينشئ حساب فرعي تلقائيًا تحت المصروفات."
                  options={[
                    { id: "", name: "إنشاء حساب تلقائي" },
                    ...accounts.map((a) => ({
                      id: a.id,
                      name: `${a.account_code} - ${a.account_name}`,
                    })),
                  ]}
                />

                <Select
                  label="الارتباط الافتراضي"
                  value={form.default_scope}
                  onChange={(v: string) => setForm({ ...form, default_scope: v })}
                  hint="يساعد شاشة المصروفات تختار أين سيرتبط المصروف تلقائيًا."
                  options={[
                    { id: "GENERAL", name: "عام" },
                    { id: "SHIPMENT", name: "حمولة" },
                    { id: "CAR", name: "سيارة" },
                    { id: "PURCHASE_INVOICE", name: "فاتورة شراء" },
                    { id: "SALES_INVOICE", name: "فاتورة بيع" },
                    { id: "DRIVER", name: "سائق" },
                    { id: "WORKER", name: "عامل" },
                  ]}
                />

                <Select
                  label="هل يؤثر على التكلفة؟"
                  value={String(form.affects_cost)}
                  onChange={(v: string) => setForm({ ...form, affects_cost: Number(v) })}
                  hint="نعم: يدخل في تكلفة الحمولة/السيارة. لا: يظهر كمصروف إداري فقط."
                  options={[
                    { id: "1", name: "نعم، يؤثر على التكلفة" },
                    { id: "0", name: "لا، مصروف إداري فقط" },
                  ]}
                />

                <Select
                  label="الحالة"
                  value={String(form.is_active)}
                  onChange={(v: string) => setForm({ ...form, is_active: Number(v) })}
                  options={[
                    { id: "1", name: "نشط" },
                    { id: "0", name: "موقوف" },
                  ]}
                />
              </div>

              <textarea
                className="w-full rounded-2xl border bg-slate-50 p-4"
                rows={4}
                placeholder="وصف أو ملاحظات"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />

              <div className="rounded-2xl bg-amber-50 p-4 text-sm font-bold text-amber-800">
                ملاحظة: لا تستخدم نوع مصروف واحد لكل شيء. الأفضل تفصيل الأنواع مثل: وقود، صيانة، إيجار، كهرباء، عمولة شراء، أجور تحميل.
              </div>

              <button
                onClick={saveType}
                disabled={saving}
                className="w-full rounded-2xl bg-[#0B2A4A] px-5 py-4 font-black text-white disabled:opacity-60"
              >
                {saving ? "جاري الحفظ..." : "حفظ نوع المصروف"}
              </button>
            </div>
          </div>
        </div>
      )}
      {feedbackDialog}
    </section>
  );
}

function defaultForm() {
  return {
    type_name: "",
    type_code: "",
    account_id: "",
    default_scope: "GENERAL",
    affects_cost: 1,
    description: "",
    is_active: 1,
  };
}

function Stat({ title, value }: any) {
  return (
    <div className="rounded-3xl border bg-white p-5 shadow-sm">
      <div className="text-sm text-slate-500">{title}</div>
      <div className="mt-2 text-2xl font-black text-[#0B2A4A]">{value}</div>
    </div>
  );
}

function Input({ label, value, onChange, hint }: any) {
  return (
    <label className="block">
      <div className="mb-1 text-sm font-bold text-slate-700">{label}</div>
      <input
        className="w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A]"
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
      />
      {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
    </label>
  );
}

function Select({ label, value, onChange, options, hint }: any) {
  return (
    <label className="block">
      <div className="mb-1 text-sm font-bold text-slate-700">{label}</div>
      <select
        className="w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A]"
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
      >
        {options.map((x: any) => (
          <option key={x.id} value={x.id}>
            {x.name}
          </option>
        ))}
      </select>
      {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
    </label>
  );
}

function InfoBox({ title, text }: any) {
  return (
    <div className="rounded-2xl bg-blue-50 p-4">
      <div className="font-black text-[#0B2A4A]">{title}</div>
      <div className="mt-1 text-sm font-semibold text-slate-600">{text}</div>
    </div>
  );
}

function scopeLabel(v: string) {
  const map: any = {
    GENERAL: "عام",
    SHIPMENT: "حمولة",
    CAR: "سيارة",
    PURCHASE_INVOICE: "فاتورة شراء",
    SALES_INVOICE: "فاتورة بيع",
    DRIVER: "سائق",
    WORKER: "عامل",
  };

  return map[v] || v || "-";
}
