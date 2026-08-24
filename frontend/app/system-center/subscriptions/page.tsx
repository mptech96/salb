"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import api from "../../api";

type Status = "ACTIVE" | "TRIAL" | "SUSPENDED" | "EXPIRED" | "CANCELLED";

type Subscription = {
  id: number;
  company_id: number;
  plan_id: number;
  start_date: string;
  end_date: string;
  status: Status;
  notes: string | null;
  company_name: string;
  owner_name: string | null;
  phone: string | null;
  email: string | null;
  city: string | null;
  company_active: number;
  plan_name: string;
  plan_code: string;
  monthly_price: number | string;
  max_branches: number | null;
  max_users: number | null;
  max_cars: number | null;
  max_invoices: number | null;
  remaining_days: number;
};

type Plan = {
  id: number;
  plan_name: string;
  plan_code: string;
  monthly_price: number | string;
  max_branches: number | null;
  max_users: number | null;
  max_cars: number | null;
  max_invoices: number | null;
  is_active: number;
};

type Summary = {
  total: number;
  active: number;
  trial: number;
  expired: number;
  suspended: number;
  cancelled: number;
  expiring_soon: number;
};

type DialogMode = "RENEW" | "PLAN" | "EXTEND" | "STATUS" | null;

const emptySummary: Summary = {
  total: 0,
  active: 0,
  trial: 0,
  expired: 0,
  suspended: 0,
  cancelled: 0,
  expiring_soon: 0,
};

const inputClass =
  "w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none transition focus:border-[#0B2A4A]";

export default function SubscriptionsPage() {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [summary, setSummary] = useState<Summary>(emptySummary);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [planFilter, setPlanFilter] = useState("ALL");

  const [selected, setSelected] = useState<Subscription | null>(null);
  const [dialogMode, setDialogMode] = useState<DialogMode>(null);

  const [renewForm, setRenewForm] = useState({
    start_date: today(),
    end_date: oneYearFromToday(),
    plan_id: "",
    notes: "",
  });

  const [planForm, setPlanForm] = useState({
    plan_id: "",
    notes: "",
  });

  const [extendForm, setExtendForm] = useState({
    days: "30",
    notes: "",
  });

  const [statusForm, setStatusForm] = useState({
    status: "SUSPENDED" as Status,
    notes: "",
  });

  const loadData = useCallback(async (manual = false) => {
    manual ? setRefreshing(true) : setLoading(true);
    setError("");

    try {
      const [subscriptionsRes, plansRes] = await Promise.all([
        api.get("/system-admin/subscriptions"),
        api.get("/plans"),
      ]);

      setSummary(subscriptionsRes.data?.data?.summary || emptySummary);
      setSubscriptions(
        Array.isArray(subscriptionsRes.data?.data?.subscriptions)
          ? subscriptionsRes.data.data.subscriptions
          : []
      );
      setPlans(Array.isArray(plansRes.data?.data) ? plansRes.data.data : []);
    } catch (requestError: any) {
      setError(
        requestError?.response?.data?.message ||
          "تعذر تحميل بيانات الاشتراكات"
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();

    return subscriptions.filter((item) => {
      const text = [
        item.company_name,
        item.owner_name,
        item.phone,
        item.email,
        item.city,
        item.plan_name,
        item.plan_code,
        item.status,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      return (
        (!q || text.includes(q)) &&
        (statusFilter === "ALL" || item.status === statusFilter) &&
        (planFilter === "ALL" || Number(item.plan_id) === Number(planFilter))
      );
    });
  }, [subscriptions, search, statusFilter, planFilter]);

  function openDialog(mode: Exclude<DialogMode, null>, item: Subscription) {
    setSelected(item);
    setDialogMode(mode);

    if (mode === "RENEW") {
      setRenewForm({
        start_date: today(),
        end_date: oneYearFromToday(),
        plan_id: String(item.plan_id),
        notes: "",
      });
    }

    if (mode === "PLAN") {
      setPlanForm({ plan_id: String(item.plan_id), notes: "" });
    }

    if (mode === "EXTEND") {
      setExtendForm({ days: "30", notes: "" });
    }

    if (mode === "STATUS") {
      setStatusForm({
        status: item.status === "ACTIVE" ? "SUSPENDED" : "ACTIVE",
        notes: "",
      });
    }
  }

  function closeDialog() {
    if (saving) return;
    setDialogMode(null);
    setSelected(null);
  }

  async function renewSubscription() {
    if (!selected) return;
    if (!renewForm.start_date || !renewForm.end_date) {
      alert("حدد تاريخ بداية ونهاية الاشتراك");
      return;
    }
    if (new Date(renewForm.end_date) < new Date(renewForm.start_date)) {
      alert("تاريخ النهاية يجب أن يكون بعد تاريخ البداية");
      return;
    }

    setSaving(true);
    try {
      await api.post(`/system-admin/subscriptions/${selected.id}/renew`, {
        start_date: renewForm.start_date,
        end_date: renewForm.end_date,
        plan_id: renewForm.plan_id ? Number(renewForm.plan_id) : undefined,
        notes: renewForm.notes || null,
      });
      alert("تم تجديد الاشتراك بنجاح");
      setDialogMode(null);
      setSelected(null);
      await loadData(true);
    } catch (e: any) {
      alert(e?.response?.data?.message || "تعذر تجديد الاشتراك");
    } finally {
      setSaving(false);
    }
  }

  async function changePlan() {
    if (!selected) return;
    if (!planForm.plan_id) {
      alert("اختر الباقة الجديدة");
      return;
    }

    setSaving(true);
    try {
      await api.put(`/system-admin/subscriptions/${selected.id}/plan`, {
        plan_id: Number(planForm.plan_id),
        notes: planForm.notes || null,
      });
      alert("تم تغيير الباقة بنجاح");
      setDialogMode(null);
      setSelected(null);
      await loadData(true);
    } catch (e: any) {
      alert(e?.response?.data?.message || "تعذر تغيير الباقة");
    } finally {
      setSaving(false);
    }
  }

  async function extendSubscription() {
    if (!selected) return;
    const days = Number(extendForm.days);
    if (!Number.isFinite(days) || days < 1) {
      alert("أدخل عدد أيام صحيح");
      return;
    }

    setSaving(true);
    try {
      await api.post(`/system-admin/subscriptions/${selected.id}/extend`, {
        days,
        notes: extendForm.notes || null,
      });
      alert("تم تمديد الاشتراك بنجاح");
      setDialogMode(null);
      setSelected(null);
      await loadData(true);
    } catch (e: any) {
      alert(e?.response?.data?.message || "تعذر تمديد الاشتراك");
    } finally {
      setSaving(false);
    }
  }

  async function updateStatus() {
    if (!selected) return;
    if (
      !window.confirm(
        `سيتم تغيير حالة اشتراك ${selected.company_name} إلى ${statusLabel(
          statusForm.status
        )}. هل تريد المتابعة؟`
      )
    ) {
      return;
    }

    setSaving(true);
    try {
      await api.put(`/system-admin/subscriptions/${selected.id}/status`, {
        status: statusForm.status,
        notes: statusForm.notes || null,
      });
      alert("تم تحديث حالة الاشتراك");
      setDialogMode(null);
      setSelected(null);
      await loadData(true);
    } catch (e: any) {
      alert(e?.response?.data?.message || "تعذر تحديث حالة الاشتراك");
    } finally {
      setSaving(false);
    }
  }

  async function quickExtend(item: Subscription) {
    if (!window.confirm(`تمديد اشتراك ${item.company_name} لمدة 30 يوم؟`)) {
      return;
    }

    try {
      await api.post(`/system-admin/subscriptions/${item.id}/extend`, {
        days: 30,
        notes: "تمديد سريع لمدة 30 يوم",
      });
      alert("تم تمديد الاشتراك");
      await loadData(true);
    } catch (e: any) {
      alert(e?.response?.data?.message || "تعذر تمديد الاشتراك");
    }
  }

  return (
    <section dir="rtl" className="space-y-6">
      <header className="overflow-hidden rounded-[32px] bg-gradient-to-l from-[#071D33] via-[#0B2A4A] to-[#164F82] p-6 text-white shadow-xl sm:p-8">
        <div className="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <div className="mb-3 inline-flex rounded-full bg-white/10 px-4 py-2 text-sm font-bold text-blue-100">
              إدارة دورة حياة اشتراكات الشركات
            </div>
            <h1 className="text-3xl font-black sm:text-4xl">الاشتراكات</h1>
            <p className="mt-3 max-w-3xl text-sm leading-7 text-blue-100 sm:text-base">
              متابعة الاشتراكات وتجديدها وتغيير الباقات وتعليقها وإعادة تفعيلها
              من شاشة واحدة.
            </p>
          </div>

          <button
            type="button"
            onClick={() => loadData(true)}
            disabled={refreshing}
            className="w-fit rounded-2xl border border-white/30 bg-white/10 px-5 py-3 text-sm font-black text-white hover:bg-white/20 disabled:opacity-60"
          >
            {refreshing ? "جاري التحديث..." : "تحديث البيانات"}
          </button>
        </div>

        <div className="mt-7 grid grid-cols-2 gap-3 md:grid-cols-4">
          <HeroStat title="إجمالي الاشتراكات" value={summary.total} />
          <HeroStat title="اشتراكات نشطة" value={summary.active} />
          <HeroStat title="تنتهي قريبًا" value={summary.expiring_soon} />
          <HeroStat title="اشتراكات منتهية" value={summary.expired} />
        </div>
      </header>

      {error ? (
        <div className="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-700 shadow-sm">
          <div className="font-black">تعذر تحميل بيانات الاشتراكات</div>
          <div className="mt-1 text-sm">{error}</div>
          <button
            type="button"
            onClick={() => loadData()}
            className="mt-4 rounded-xl bg-red-700 px-5 py-2.5 text-sm font-bold text-white"
          >
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <SmallStat title="نشطة" value={summary.active} className="border-emerald-200 bg-emerald-50" />
        <SmallStat title="تجريبية" value={summary.trial} className="border-blue-200 bg-blue-50" />
        <SmallStat title="معلقة" value={summary.suspended} className="border-amber-200 bg-amber-50" />
        <SmallStat title="منتهية" value={summary.expired} className="border-red-200 bg-red-50" />
        <SmallStat title="ملغاة" value={summary.cancelled} className="border-slate-200 bg-slate-50" />
      </div>

      <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="بحث بالشركة أو المالك أو الجوال أو الباقة..."
            className={inputClass}
          />

          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className={inputClass}
          >
            <option value="ALL">كل الحالات</option>
            <option value="ACTIVE">نشط</option>
            <option value="TRIAL">تجريبي</option>
            <option value="SUSPENDED">معلق</option>
            <option value="EXPIRED">منتهي</option>
            <option value="CANCELLED">ملغي</option>
          </select>

          <select
            value={planFilter}
            onChange={(e) => setPlanFilter(e.target.value)}
            className={inputClass}
          >
            <option value="ALL">كل الباقات</option>
            {plans.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.plan_name}
              </option>
            ))}
          </select>

          <button
            type="button"
            onClick={() => {
              setSearch("");
              setStatusFilter("ALL");
              setPlanFilter("ALL");
            }}
            className="rounded-2xl border border-slate-200 bg-white p-4 font-black text-slate-700 hover:bg-slate-50"
          >
            مسح الفلاتر
          </button>
        </div>
      </div>

      <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5">
          <h2 className="text-xl font-black text-[#0B2A4A]">قائمة الاشتراكات</h2>
          <p className="mt-1 text-sm text-slate-500">
            {loading ? "جاري التحميل..." : `${filtered.length} اشتراك`}
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1450px] text-right">
            <thead className="bg-slate-50 text-xs text-slate-600">
              <tr>
                <th className="p-4 font-black">الشركة</th>
                <th className="p-4 font-black">الباقة</th>
                <th className="p-4 font-black">البداية</th>
                <th className="p-4 font-black">النهاية</th>
                <th className="p-4 font-black">المدة المتبقية</th>
                <th className="p-4 font-black">الحدود</th>
                <th className="p-4 font-black">الحالة</th>
                <th className="p-4 font-black">العمليات</th>
              </tr>
            </thead>

            <tbody>
              {loading ? (
                <LoadingRows />
              ) : filtered.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-12 text-center text-slate-500">
                    لا توجد اشتراكات مطابقة للفلاتر الحالية
                  </td>
                </tr>
              ) : (
                filtered.map((item) => (
                  <tr key={item.id} className="border-t border-slate-100 align-top hover:bg-slate-50">
                    <td className="p-4">
                      <div className="font-black text-[#0B2A4A]">{item.company_name}</div>
                      <div className="mt-1 text-xs text-slate-500">{item.owner_name || "-"}</div>
                      <div className="mt-1 text-xs text-slate-400">{item.phone || "-"}</div>
                    </td>

                    <td className="p-4">
                      <div className="font-bold text-slate-800">{item.plan_name}</div>
                      <div className="mt-1 text-xs text-slate-500">{item.plan_code}</div>
                      <div className="mt-1 text-xs font-bold text-[#0B2A4A]">
                        {money(item.monthly_price)} ر.س / شهر
                      </div>
                    </td>

                    <td className="p-4 text-sm text-slate-700">{formatDate(item.start_date)}</td>
                    <td className="p-4 text-sm text-slate-700">{formatDate(item.end_date)}</td>
                    <td className="p-4"><RemainingDays days={Number(item.remaining_days)} /></td>

                    <td className="p-4">
                      <div className="grid grid-cols-2 gap-2 text-xs">
                        <LimitPill title="المستخدمون" value={item.max_users} />
                        <LimitPill title="الفروع" value={item.max_branches} />
                        <LimitPill title="السيارات" value={item.max_cars} />
                        <LimitPill title="الفواتير" value={item.max_invoices} />
                      </div>
                    </td>

                    <td className="p-4"><StatusBadge status={item.status} /></td>

                    <td className="p-4">
                      <div className="flex min-w-[340px] flex-wrap gap-2">
                        <ActionButton label="تجديد" onClick={() => openDialog("RENEW", item)} className="bg-[#0B2A4A] text-white" />
                        <ActionButton label="تغيير الباقة" onClick={() => openDialog("PLAN", item)} className="bg-blue-100 text-blue-700" />
                        <ActionButton label="تمديد 30 يوم" onClick={() => quickExtend(item)} className="bg-emerald-100 text-emerald-700" />
                        <ActionButton label="تمديد مخصص" onClick={() => openDialog("EXTEND", item)} className="bg-violet-100 text-violet-700" />
                        <ActionButton
                          label={item.status === "ACTIVE" ? "تعليق" : "تغيير الحالة"}
                          onClick={() => openDialog("STATUS", item)}
                          className={item.status === "ACTIVE" ? "bg-amber-100 text-amber-700" : "bg-slate-200 text-slate-700"}
                        />
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {dialogMode && selected ? (
        <Dialog title={dialogTitle(dialogMode)} subtitle={selected.company_name} onClose={closeDialog}>
          {dialogMode === "RENEW" ? (
            <div className="space-y-4">
              <Field label="تاريخ البداية">
                <input type="date" value={renewForm.start_date} onChange={(e) => setRenewForm({ ...renewForm, start_date: e.target.value })} className={inputClass} />
              </Field>
              <Field label="تاريخ النهاية">
                <input type="date" value={renewForm.end_date} onChange={(e) => setRenewForm({ ...renewForm, end_date: e.target.value })} className={inputClass} />
              </Field>
              <Field label="الباقة">
                <select value={renewForm.plan_id} onChange={(e) => setRenewForm({ ...renewForm, plan_id: e.target.value })} className={inputClass}>
                  <option value="">نفس الباقة الحالية</option>
                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>{plan.plan_name} — {money(plan.monthly_price)} ر.س</option>
                  ))}
                </select>
              </Field>
              <Field label="ملاحظات">
                <textarea rows={4} value={renewForm.notes} onChange={(e) => setRenewForm({ ...renewForm, notes: e.target.value })} className={inputClass} />
              </Field>
              <DialogActions saving={saving} onCancel={closeDialog} onSave={renewSubscription} saveLabel="تجديد الاشتراك" />
            </div>
          ) : null}

          {dialogMode === "PLAN" ? (
            <div className="space-y-4">
              <Field label="الباقة الحالية">
                <div className="rounded-2xl bg-slate-100 p-4 font-black text-slate-700">{selected.plan_name}</div>
              </Field>
              <Field label="الباقة الجديدة">
                <select value={planForm.plan_id} onChange={(e) => setPlanForm({ ...planForm, plan_id: e.target.value })} className={inputClass}>
                  <option value="">اختر الباقة</option>
                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>{plan.plan_name} — {money(plan.monthly_price)} ر.س</option>
                  ))}
                </select>
              </Field>
              <Field label="ملاحظات">
                <textarea rows={4} value={planForm.notes} onChange={(e) => setPlanForm({ ...planForm, notes: e.target.value })} className={inputClass} />
              </Field>
              <DialogActions saving={saving} onCancel={closeDialog} onSave={changePlan} saveLabel="حفظ الباقة" />
            </div>
          ) : null}

          {dialogMode === "EXTEND" ? (
            <div className="space-y-4">
              <Field label="عدد أيام التمديد">
                <input type="number" min="1" max="3650" value={extendForm.days} onChange={(e) => setExtendForm({ ...extendForm, days: e.target.value })} className={inputClass} />
              </Field>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {[7, 15, 30, 90].map((days) => (
                  <button key={days} type="button" onClick={() => setExtendForm({ ...extendForm, days: String(days) })} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 hover:border-[#0B2A4A]">
                    {days} يوم
                  </button>
                ))}
              </div>
              <Field label="ملاحظات">
                <textarea rows={4} value={extendForm.notes} onChange={(e) => setExtendForm({ ...extendForm, notes: e.target.value })} className={inputClass} />
              </Field>
              <DialogActions saving={saving} onCancel={closeDialog} onSave={extendSubscription} saveLabel="تمديد الاشتراك" />
            </div>
          ) : null}

          {dialogMode === "STATUS" ? (
            <div className="space-y-4">
              <Field label="الحالة الحالية"><StatusBadge status={selected.status} /></Field>
              <Field label="الحالة الجديدة">
                <select value={statusForm.status} onChange={(e) => setStatusForm({ ...statusForm, status: e.target.value as Status })} className={inputClass}>
                  <option value="ACTIVE">نشط</option>
                  <option value="TRIAL">تجريبي</option>
                  <option value="SUSPENDED">معلق</option>
                  <option value="EXPIRED">منتهي</option>
                  <option value="CANCELLED">ملغي</option>
                </select>
              </Field>
              <Field label="ملاحظات">
                <textarea rows={4} value={statusForm.notes} onChange={(e) => setStatusForm({ ...statusForm, notes: e.target.value })} className={inputClass} />
              </Field>
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-800">
                الحالات المعلقة والمنتهية والملغاة توقف الشركة، والحالتان النشطة والتجريبية تعيدان تفعيلها.
              </div>
              <DialogActions saving={saving} onCancel={closeDialog} onSave={updateStatus} saveLabel="تحديث الحالة" />
            </div>
          ) : null}
        </Dialog>
      ) : null}
    </section>
  );
}

function HeroStat({ title, value }: { title: string; value: number }) {
  return (
    <div className="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
      <div className="text-xs font-bold text-blue-100 sm:text-sm">{title}</div>
      <div className="mt-2 text-2xl font-black sm:text-3xl">{Number(value || 0).toLocaleString("ar-SA")}</div>
    </div>
  );
}

function SmallStat({ title, value, className }: { title: string; value: number; className: string }) {
  return (
    <div className={`rounded-2xl border p-4 shadow-sm ${className}`}>
      <div className="text-xs font-bold text-slate-600">{title}</div>
      <div className="mt-2 text-2xl font-black text-[#0B2A4A]">{Number(value || 0).toLocaleString("ar-SA")}</div>
    </div>
  );
}

function StatusBadge({ status }: { status: Status }) {
  const styles: Record<Status, string> = {
    ACTIVE: "bg-emerald-100 text-emerald-700",
    TRIAL: "bg-blue-100 text-blue-700",
    SUSPENDED: "bg-amber-100 text-amber-700",
    EXPIRED: "bg-red-100 text-red-700",
    CANCELLED: "bg-slate-200 text-slate-700",
  };

  return <span className={`inline-flex rounded-full px-3 py-1.5 text-xs font-black ${styles[status]}`}>{statusLabel(status)}</span>;
}

function RemainingDays({ days }: { days: number }) {
  if (days < 0) return <span className="rounded-full bg-red-100 px-3 py-1.5 text-xs font-black text-red-700">منتهي منذ {Math.abs(days)} يوم</span>;
  if (days <= 7) return <span className="rounded-full bg-red-100 px-3 py-1.5 text-xs font-black text-red-700">متبقي {days} يوم</span>;
  if (days <= 30) return <span className="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-black text-amber-700">متبقي {days} يوم</span>;
  return <span className="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-black text-emerald-700">متبقي {days} يوم</span>;
}

function LimitPill({ title, value }: { title: string; value: number | null }) {
  return (
    <div className="rounded-xl bg-slate-100 px-3 py-2 text-slate-600">
      <div className="text-[10px]">{title}</div>
      <div className="mt-1 font-black text-slate-800">{value === null || Number(value) >= 999 ? "غير محدود" : Number(value).toLocaleString("ar-SA")}</div>
    </div>
  );
}

function ActionButton({ label, onClick, className }: { label: string; onClick: () => void; className: string }) {
  return <button type="button" onClick={onClick} className={`rounded-xl px-3 py-2 text-xs font-black transition hover:-translate-y-0.5 ${className}`}>{label}</button>;
}

function Dialog({ title, subtitle, onClose, children }: { title: string; subtitle: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-[28px] bg-white shadow-2xl">
        <div className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-100 bg-white p-5">
          <div>
            <h2 className="text-xl font-black text-[#0B2A4A]">{title}</h2>
            <p className="mt-1 text-sm text-slate-500">{subtitle}</p>
          </div>
          <button type="button" onClick={onClose} className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-lg font-black text-slate-600 hover:bg-slate-200">×</button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <div className="mb-2 text-sm font-black text-slate-700">{label}</div>
      {children}
    </label>
  );
}

function DialogActions({ saving, onCancel, onSave, saveLabel }: { saving: boolean; onCancel: () => void; onSave: () => void; saveLabel: string }) {
  return (
    <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:justify-end">
      <button type="button" onClick={onCancel} disabled={saving} className="rounded-2xl border border-slate-200 px-5 py-3 font-black text-slate-700 disabled:opacity-50">إلغاء</button>
      <button type="button" onClick={onSave} disabled={saving} className="rounded-2xl bg-[#0B2A4A] px-6 py-3 font-black text-white disabled:opacity-60">{saving ? "جاري الحفظ..." : saveLabel}</button>
    </div>
  );
}

function LoadingRows() {
  return (
    <>
      {[1, 2, 3].map((row) => (
        <tr key={row} className="border-t border-slate-100">
          <td colSpan={8} className="p-4"><div className="h-20 animate-pulse rounded-2xl bg-slate-100" /></td>
        </tr>
      ))}
    </>
  );
}

function dialogTitle(mode: Exclude<DialogMode, null>) {
  return {
    RENEW: "تجديد الاشتراك",
    PLAN: "تغيير الباقة",
    EXTEND: "تمديد الاشتراك",
    STATUS: "تحديث حالة الاشتراك",
  }[mode];
}

function statusLabel(status: Status) {
  return {
    ACTIVE: "نشط",
    TRIAL: "تجريبي",
    SUSPENDED: "معلق",
    EXPIRED: "منتهي",
    CANCELLED: "ملغي",
  }[status];
}

function money(value: unknown) {
  return Number(value || 0).toLocaleString("ar-SA", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(value: string | null | undefined) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("ar-SA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function oneYearFromToday() {
  const date = new Date();
  date.setFullYear(date.getFullYear() + 1);
  return date.toISOString().slice(0, 10);
}
