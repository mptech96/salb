"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import api from "../../api";

type Plan = {
  id: number;
  plan_name: string;
  plan_code: string;
  monthly_price: number | string;
  yearly_price: number | string | null;
  max_branches: number | null;
  max_users: number | null;
  max_cars: number | null;
  max_invoices: number | null;
  is_active: number;
  companies_count: number | string;
};

type Summary = {
  total: number;
  active: number;
  inactive: number;
  companies: number;
};

const emptyForm = {
  plan_name: "",
  plan_code: "",
  monthly_price: "0",
  yearly_price: "",
  max_branches: "1",
  max_users: "2",
  max_cars: "",
  max_invoices: "",
  is_active: 1,
};

export default function PlansPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [summary, setSummary] = useState<Summary>({
    total: 0,
    active: 0,
    inactive: 0,
    companies: 0,
  });

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [showDialog, setShowDialog] = useState(false);
  const [editing, setEditing] = useState<Plan | null>(null);
  const [form, setForm] = useState(emptyForm);

  const loadData = useCallback(async () => {
    setLoading(true);

    try {
      const response = await api.get("/system-admin/plans");

      setPlans(
        Array.isArray(response.data?.data?.plans)
          ? response.data.data.plans
          : []
      );

      setSummary(
        response.data?.data?.summary || {
          total: 0,
          active: 0,
          inactive: 0,
          companies: 0,
        }
      );
    } catch (error: any) {
      alert(error?.response?.data?.message || "تعذر تحميل الباقات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const filteredPlans = useMemo(() => {
    const q = search.trim().toLowerCase();

    return plans.filter((plan) => {
      const matchesSearch =
        !q ||
        `${plan.plan_name} ${plan.plan_code}`
          .toLowerCase()
          .includes(q);

      const matchesStatus =
        statusFilter === "ALL" ||
        (statusFilter === "ACTIVE" && Number(plan.is_active) === 1) ||
        (statusFilter === "INACTIVE" && Number(plan.is_active) === 0);

      return matchesSearch && matchesStatus;
    });
  }, [plans, search, statusFilter]);

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setShowDialog(true);
  }

  function openEdit(plan: Plan) {
    setEditing(plan);
    setForm({
      plan_name: plan.plan_name || "",
      plan_code: plan.plan_code || "",
      monthly_price: String(plan.monthly_price ?? 0),
      yearly_price: String(plan.yearly_price ?? ""),
      max_branches: String(plan.max_branches ?? ""),
      max_users: String(plan.max_users ?? ""),
      max_cars: String(plan.max_cars ?? ""),
      max_invoices: String(plan.max_invoices ?? ""),
      is_active: Number(plan.is_active) === 1 ? 1 : 0,
    });
    setShowDialog(true);
  }

  function payload() {
    return {
      plan_name: form.plan_name.trim(),
      plan_code: form.plan_code.trim().toUpperCase(),
      monthly_price: Number(form.monthly_price || 0),
      yearly_price: form.yearly_price === "" ? null : Number(form.yearly_price),
      max_branches: form.max_branches ? Number(form.max_branches) : null,
      max_users: form.max_users ? Number(form.max_users) : null,
      max_cars: form.max_cars ? Number(form.max_cars) : null,
      max_invoices: form.max_invoices ? Number(form.max_invoices) : null,
      is_active: Number(form.is_active),
    };
  }

  async function savePlan() {
    if (!form.plan_name.trim()) return alert("أدخل اسم الباقة");
    if (!form.plan_code.trim()) return alert("أدخل كود الباقة");
    if (Number(form.monthly_price) < 0) return alert("السعر الشهري غير صحيح");
    if (form.yearly_price !== "" && Number(form.yearly_price) < 0) {
      return alert("السعر السنوي غير صحيح");
    }

    setSaving(true);

    try {
      if (editing) {
        await api.put(`/system-admin/plans/${editing.id}`, payload());
        alert("تم تحديث الباقة");
      } else {
        await api.post("/system-admin/plans", payload());
        alert("تم إنشاء الباقة");
      }

      setShowDialog(false);
      setEditing(null);
      setForm(emptyForm);
      await loadData();
    } catch (error: any) {
      const validationErrors = error?.response?.data?.errors;

      if (validationErrors) {
        const firstError = Object.values(validationErrors).flat().at(0);
        alert(String(firstError || "تحقق من البيانات"));
      } else {
        alert(error?.response?.data?.message || "تعذر حفظ الباقة");
      }
    } finally {
      setSaving(false);
    }
  }

  async function togglePlan(plan: Plan) {
    const action = Number(plan.is_active) === 1 ? "إيقاف" : "تفعيل";
    if (!confirm(`هل تريد ${action} باقة "${plan.plan_name}"؟`)) return;

    try {
      await api.put(`/system-admin/plans/${plan.id}/toggle`);
      await loadData();
    } catch (error: any) {
      alert(error?.response?.data?.message || "تعذر تغيير حالة الباقة");
    }
  }

  async function deletePlan(plan: Plan) {
    if (!confirm(`هل تريد حذف باقة "${plan.plan_name}" نهائيًا؟`)) return;

    try {
      await api.delete(`/system-admin/plans/${plan.id}`);
      alert("تم حذف الباقة");
      await loadData();
    } catch (error: any) {
      alert(error?.response?.data?.message || "تعذر حذف الباقة");
    }
  }

  return (
    <section dir="rtl" className="space-y-6">
      <header className="rounded-[32px] bg-gradient-to-l from-[#071D33] via-[#0B2A4A] to-[#164F82] p-6 text-white shadow-xl sm:p-8">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-sm font-bold text-blue-100">إدارة الأسعار والحدود</p>
            <h1 className="mt-2 text-3xl font-black sm:text-4xl">الباقات</h1>
            <p className="mt-3 text-sm text-blue-100">
              إنشاء وتعديل الباقات وحدود المستخدمين والفروع والسيارات والفواتير.
            </p>
          </div>

          <button
            type="button"
            onClick={openCreate}
            className="w-fit rounded-2xl bg-white px-6 py-3 font-black text-[#0B2A4A] shadow"
          >
            + إنشاء باقة
          </button>
        </div>

        <div className="mt-7 grid grid-cols-2 gap-3 md:grid-cols-4">
          <HeroStat title="إجمالي الباقات" value={summary.total} />
          <HeroStat title="الباقات النشطة" value={summary.active} />
          <HeroStat title="الباقات المتوقفة" value={summary.inactive} />
          <HeroStat title="الشركات المرتبطة" value={summary.companies} />
        </div>
      </header>

      <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="بحث باسم الباقة أو الكود..."
            className="rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none md:col-span-2"
          />

          <select
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value)}
            className="rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none"
          >
            <option value="ALL">كل الحالات</option>
            <option value="ACTIVE">النشطة</option>
            <option value="INACTIVE">المتوقفة</option>
          </select>
        </div>
      </div>

      {loading ? (
        <div className="rounded-3xl border bg-white p-12 text-center text-slate-500">
          جاري تحميل الباقات...
        </div>
      ) : filteredPlans.length === 0 ? (
        <div className="rounded-3xl border bg-white p-12 text-center text-slate-500">
          لا توجد باقات مطابقة
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          {filteredPlans.map((plan) => (
            <article
              key={plan.id}
              className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
            >
              <div className="bg-[#0B2A4A] p-5 text-white">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="text-xs font-bold text-blue-100">{plan.plan_code}</div>
                    <h2 className="mt-2 text-2xl font-black">{plan.plan_name}</h2>
                  </div>
                  <StatusBadge active={Number(plan.is_active) === 1} />
                </div>

                <div className="mt-5 space-y-2">
                  <div>
                    <span className="text-3xl font-black">{money(plan.monthly_price)}</span>
                    <span className="mr-2 text-sm text-blue-100">ر.س / شهر</span>
                  </div>
                  <div className="text-sm text-blue-100">
                    السعر السنوي:{" "}
                    <strong className="text-white">
                      {plan.yearly_price === null || plan.yearly_price === ""
                        ? "غير محدد"
                        : `${money(plan.yearly_price)} ر.س`}
                    </strong>
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 p-5">
                <Limit title="المستخدمون" value={plan.max_users} />
                <Limit title="الفروع" value={plan.max_branches} />
                <Limit title="السيارات" value={plan.max_cars} />
                <Limit title="الفواتير" value={plan.max_invoices} />
              </div>

              <div className="border-t border-slate-100 px-5 py-4">
                <div className="mb-4 text-sm text-slate-500">
                  الشركات المرتبطة: <strong className="text-[#0B2A4A]">{Number(plan.companies_count || 0)}</strong>
                </div>

                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => openEdit(plan)}
                    className="rounded-xl bg-blue-100 px-4 py-2 text-sm font-black text-blue-700"
                  >
                    تعديل
                  </button>

                  <button
                    type="button"
                    onClick={() => togglePlan(plan)}
                    className="rounded-xl bg-amber-100 px-4 py-2 text-sm font-black text-amber-700"
                  >
                    {Number(plan.is_active) === 1 ? "إيقاف" : "تفعيل"}
                  </button>

                  <button
                    type="button"
                    onClick={() => deletePlan(plan)}
                    className="rounded-xl bg-red-100 px-4 py-2 text-sm font-black text-red-700"
                  >
                    حذف
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}

      {showDialog ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !saving) setShowDialog(false);
          }}
        >
          <div className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-[28px] bg-white shadow-2xl">
            <div className="flex items-start justify-between border-b p-5">
              <div>
                <h2 className="text-2xl font-black text-[#0B2A4A]">
                  {editing ? "تعديل الباقة" : "إنشاء باقة"}
                </h2>
                <p className="mt-1 text-sm text-slate-500">
                  أدخل بيانات السعر والحدود الخاصة بالباقة.
                </p>
              </div>

              <button
                type="button"
                onClick={() => !saving && setShowDialog(false)}
                className="h-10 w-10 rounded-xl bg-slate-100 text-xl font-black"
              >
                ×
              </button>
            </div>

            <div className="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
              <Field label="اسم الباقة">
                <input
                  value={form.plan_name}
                  onChange={(event) => setForm({ ...form, plan_name: event.target.value })}
                  className={inputClass}
                  placeholder="الباقة الاحترافية"
                />
              </Field>

              <Field label="كود الباقة">
                <input
                  value={form.plan_code}
                  onChange={(event) => setForm({ ...form, plan_code: event.target.value.toUpperCase() })}
                  className={inputClass}
                  placeholder="PRO"
                  dir="ltr"
                />
              </Field>

              <Field label="السعر الشهري">
                <input
                  type="number"
                  min="0"
                  step="0.001"
                  value={form.monthly_price}
                  onChange={(event) => setForm({ ...form, monthly_price: event.target.value })}
                  className={inputClass}
                />
              </Field>

              <Field label="السعر السنوي">
                <input
                  type="number"
                  min="0"
                  step="0.001"
                  value={form.yearly_price}
                  onChange={(event) => setForm({ ...form, yearly_price: event.target.value })}
                  className={inputClass}
                  placeholder="مثال: 2990.000"
                />
              </Field>

              <Field label="حالة الباقة">
                <select
                  value={form.is_active}
                  onChange={(event) => setForm({ ...form, is_active: Number(event.target.value) })}
                  className={inputClass}
                >
                  <option value={1}>نشطة</option>
                  <option value={0}>متوقفة</option>
                </select>
              </Field>

              <Field label="حد المستخدمين">
                <input
                  type="number"
                  min="1"
                  value={form.max_users}
                  onChange={(event) => setForm({ ...form, max_users: event.target.value })}
                  className={inputClass}
                  placeholder="999 لغير محدود"
                />
              </Field>

              <Field label="حد الفروع">
                <input
                  type="number"
                  min="1"
                  value={form.max_branches}
                  onChange={(event) => setForm({ ...form, max_branches: event.target.value })}
                  className={inputClass}
                  placeholder="999 لغير محدود"
                />
              </Field>

              <Field label="حد السيارات">
                <input
                  type="number"
                  min="1"
                  value={form.max_cars}
                  onChange={(event) => setForm({ ...form, max_cars: event.target.value })}
                  className={inputClass}
                  placeholder="اتركه فارغًا لغير محدود"
                />
              </Field>

              <Field label="حد الفواتير">
                <input
                  type="number"
                  min="1"
                  value={form.max_invoices}
                  onChange={(event) => setForm({ ...form, max_invoices: event.target.value })}
                  className={inputClass}
                  placeholder="اتركه فارغًا لغير محدود"
                />
              </Field>
            </div>

            <div className="flex flex-col-reverse gap-3 border-t p-5 sm:flex-row sm:justify-end">
              <button
                type="button"
                disabled={saving}
                onClick={() => setShowDialog(false)}
                className="rounded-2xl border px-6 py-3 font-black"
              >
                إلغاء
              </button>

              <button
                type="button"
                disabled={saving}
                onClick={savePlan}
                className="rounded-2xl bg-[#0B2A4A] px-7 py-3 font-black text-white disabled:opacity-60"
              >
                {saving ? "جاري الحفظ..." : "حفظ الباقة"}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </section>
  );
}

const inputClass =
  "w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none focus:border-[#0B2A4A]";

function HeroStat({ title, value }: { title: string; value: number }) {
  return (
    <div className="rounded-2xl bg-white/10 p-4">
      <div className="text-xs font-bold text-blue-100">{title}</div>
      <div className="mt-2 text-3xl font-black">{Number(value || 0).toLocaleString("ar-SA")}</div>
    </div>
  );
}

function Limit({ title, value }: { title: string; value: number | null }) {
  return (
    <div className="rounded-2xl bg-slate-50 p-4">
      <div className="text-xs text-slate-500">{title}</div>
      <div className="mt-2 font-black text-[#0B2A4A]">
        {value === null || Number(value) >= 999
          ? "غير محدود"
          : Number(value).toLocaleString("ar-SA")}
      </div>
    </div>
  );
}

function StatusBadge({ active }: { active: boolean }) {
  return (
    <span
      className={`rounded-full px-3 py-1 text-xs font-black ${
        active
          ? "bg-emerald-100 text-emerald-700"
          : "bg-slate-200 text-slate-700"
      }`}
    >
      {active ? "نشطة" : "متوقفة"}
    </span>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label>
      <div className="mb-2 text-sm font-black text-slate-700">{label}</div>
      {children}
    </label>
  );
}

function money(value: unknown): string {
  return Number(value || 0).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}
