"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import api from "../../api";
import SystemDialog from "@/components/common/SystemDialog";
import {
  backupPlatformSession,
  saveSession,
} from "../../../lib/session";

type Company = {
  id: number;
  company_name: string;
  owner_name: string | null;
  phone: string | null;
  email: string | null;
  city: string | null;
  is_active: number;
  plan_name: string | null;
  plan_code: string | null;
  start_date: string | null;
  end_date: string | null;
  status: string | null;
};

type Plan = {
  id: number;
  plan_name: string;
  monthly_price: number | string;
};

type DialogState = {
  open: boolean;
  type: "success" | "error" | "warning" | "info" | "confirm";
  title: string;
  message: string;
  action: "none" | "support";
  confirmText?: string;
  showCancel?: boolean;
};

const emptyForm = {
  company_name: "",
  owner_name: "",
  phone: "",
  email: "",
  city: "",
  address: "",
  plan_id: "",
  start_date: "",
  end_date: "",
};

const emptyDialog: DialogState = {
  open: false,
  type: "info",
  title: "",
  message: "",
  action: "none",
};

function firstApiError(error: any, fallback: string): string {
  const errors = error?.response?.data?.errors;

  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }

  return String(error?.response?.data?.message || fallback);
}

function formatDate(value: string | null): string {
  if (!value) return "-";
  return value.slice(0, 10);
}

function statusLabel(status: string | null, active: number): string {
  if (!active) return "الشركة متوقفة";

  const normalized = String(status || "ACTIVE").toUpperCase();
  const labels: Record<string, string> = {
    ACTIVE: "اشتراك فعال",
    PENDING: "بانتظار التفعيل",
    EXPIRED: "منتهي",
    SUSPENDED: "موقوف",
    CANCELLED: "ملغي",
    TRIAL: "تجريبي",
  };

  return labels[normalized] || normalized;
}

export default function CompaniesPage() {
  const provisioningKey = useRef(crypto.randomUUID());
  const [companies, setCompanies] = useState<Company[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [supportLoading, setSupportLoading] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [search, setSearch] = useState("");
  const [form, setForm] = useState(emptyForm);
  const [selectedCompany, setSelectedCompany] = useState<Company | null>(null);
  const [dialog, setDialog] = useState<DialogState>(emptyDialog);

  const loadData = useCallback(async () => {
    setLoading(true);

    try {
      const [companiesResponse, plansResponse] = await Promise.all([
        api.get("/companies"),
        api.get("/plans"),
      ]);

      setCompanies(companiesResponse.data.data || []);
      setPlans(plansResponse.data.data || []);
    } catch (error: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر تحميل الشركات",
        message: firstApiError(
          error,
          "حدث خطأ أثناء تحميل بيانات الشركات والباقات."
        ),
        action: "none",
      });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const filteredCompanies = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return companies;

    return companies.filter((company) =>
      [
        company.company_name,
        company.owner_name,
        company.phone,
        company.email,
        company.city,
        company.plan_name,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase()
        .includes(query)
    );
  }, [companies, search]);

  const activeCompanies = useMemo(
    () => companies.filter((company) => Number(company.is_active) === 1).length,
    [companies]
  );

  function showValidation(message: string) {
    setDialog({
      open: true,
      type: "warning",
      title: "راجع بيانات الشركة",
      message,
      action: "none",
    });
  }

  async function saveCompany() {
    const companyName = form.company_name.trim();
    const ownerName = form.owner_name.trim();
    const phone = form.phone.trim();

    if (companyName.length < 3) {
      showValidation("اسم الشركة مطلوب ويجب ألا يقل عن 3 أحرف.");
      return;
    }

    if (ownerName.length < 3) {
      showValidation("اسم المالك أو المدير مطلوب ويجب ألا يقل عن 3 أحرف.");
      return;
    }

    if (!/^[0-9]{7,15}$/.test(phone)) {
      showValidation("رقم الجوال يجب أن يحتوي على أرقام فقط من 7 إلى 15 رقمًا.");
      return;
    }

    if (!form.plan_id) {
      showValidation("اختر باقة الاشتراك.");
      return;
    }

    if (!form.start_date || !form.end_date) {
      showValidation("حدد تاريخ بداية الاشتراك ونهايته.");
      return;
    }

    if (form.end_date < form.start_date) {
      showValidation("تاريخ نهاية الاشتراك يجب ألا يسبق تاريخ البداية.");
      return;
    }

    setSaving(true);

    try {
      const response = await api.post("/companies", {
          ...form,
          company_name: companyName,
          owner_name: ownerName,
          phone,
          plan_id: Number(form.plan_id),
        }, { headers: { "Idempotency-Key": provisioningKey.current } });

      provisioningKey.current = crypto.randomUUID();
      setForm(emptyForm);
      setShowForm(false);
      await loadData();

      setDialog({
        open: true,
        type: "success",
        title: "تم تأسيس الشركة",
        message:
          response.data?.data?.temporary_password
            ? `${response.data?.message || "تم تأسيس الشركة."} اسم المستخدم: ${response.data.data.username} — كلمة المرور المؤقتة: ${response.data.data.temporary_password}`
            : response.data?.message ||
          "تم إنشاء الشركة والفرع الرئيسي والتأسيس المحاسبي بنجاح.",
        action: "none",
      });
    } catch (error: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر إنشاء الشركة",
        message: firstApiError(error, "حدث خطأ أثناء إنشاء الشركة."),
        action: "none",
      });
    } finally {
      setSaving(false);
    }
  }

  function requestSupportAccess(company: Company) {
    setSelectedCompany(company);
    setDialog({
      open: true,
      type: "confirm",
      title: "دخول الدعم الفني",
      message: `سيتم فتح جلسة دعم آمنة داخل شركة «${company.company_name}» لمدة ساعتين، مع حفظ جلسة مدير المنصة للعودة إليها.`,
      action: "support",
      confirmText: "دخول الشركة",
      showCancel: true,
    });
  }

  async function enterSupportMode() {
    if (!selectedCompany) return;

    setSupportLoading(true);

    try {
      backupPlatformSession();

      const response = await api.post(
        `/companies/${selectedCompany.id}/support-access`,
        {
          reason: "دخول دعم فني من لوحة إدارة الشركات",
        }
      );

      saveSession({
        token: response.data.token,
        user: response.data.user,
        subscription: response.data.subscription ?? null,
        permissions: response.data.user?.permissions ?? [],
      });

      window.location.assign("/");
    } catch (error: any) {
      setDialog({
        open: true,
        type: "error",
        title: "فشل دخول الدعم",
        message: firstApiError(
          error,
          "تعذر فتح جلسة الدعم الفني لهذه الشركة."
        ),
        action: "none",
      });
    } finally {
      setSupportLoading(false);
    }
  }

  async function confirmDialog() {
    if (dialog.action === "support") {
      await enterSupportMode();
      return;
    }

    setDialog(emptyDialog);
  }

  return (
    <section dir="rtl" className="space-y-5 sm:space-y-6">
      <header className="overflow-hidden rounded-[28px] bg-gradient-to-l from-[#071D33] via-[#0B2A4A] to-[#164F82] p-5 text-white shadow-xl sm:p-7">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <div className="inline-flex rounded-full bg-white/10 px-4 py-2 text-xs font-bold text-blue-100">
              مركز إدارة عملاء منصة صلب
            </div>
            <h1 className="mt-4 text-3xl font-black sm:text-4xl">
              الشركات والاشتراكات
            </h1>
            <p className="mt-2 max-w-2xl text-sm leading-7 text-blue-100">
              تأسيس الشركات، متابعة الاشتراكات، والدخول الآمن بوضع الدعم الفني.
            </p>
          </div>

          <button
            type="button"
            onClick={() => setShowForm((value) => !value)}
            className="rounded-2xl bg-white px-5 py-3.5 font-black text-[#0B2A4A] shadow-lg transition hover:bg-blue-50"
          >
            {showForm ? "إغلاق نموذج الإضافة" : "+ إضافة شركة"}
          </button>
        </div>

        <div className="mt-6 grid grid-cols-2 gap-3 sm:max-w-lg sm:grid-cols-3">
          <Metric label="إجمالي الشركات" value={companies.length} />
          <Metric label="الشركات النشطة" value={activeCompanies} />
          <Metric
            label="غير النشطة"
            value={Math.max(companies.length - activeCompanies, 0)}
            className="col-span-2 sm:col-span-1"
          />
        </div>
      </header>

      {showForm ? (
        <section className="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="mb-5">
            <h2 className="text-xl font-black text-[#0B2A4A]">
              تأسيس شركة جديدة
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              سيتم إنشاء الفرع الرئيسي والسنة المالية وشجرة الحسابات ومراكز التكلفة تلقائيًا.
            </p>
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Field
              label="اسم الشركة *"
              value={form.company_name}
              onChange={(value) => setForm({ ...form, company_name: value })}
            />
            <Field
              label="اسم المالك أو المدير *"
              value={form.owner_name}
              onChange={(value) => setForm({ ...form, owner_name: value })}
            />
            <Field
              label="رقم الجوال *"
              value={form.phone}
              inputMode="numeric"
              dir="ltr"
              onChange={(value) =>
                setForm({ ...form, phone: value.replace(/\D/g, "").slice(0, 15) })
              }
            />
            <Field
              label="البريد الإلكتروني"
              type="email"
              value={form.email}
              dir="ltr"
              onChange={(value) => setForm({ ...form, email: value })}
            />
            <Field
              label="المدينة"
              value={form.city}
              onChange={(value) => setForm({ ...form, city: value })}
            />
            <Field
              label="العنوان"
              value={form.address}
              onChange={(value) => setForm({ ...form, address: value })}
            />

            <label className="block">
              <span className="mb-2 block text-sm font-black text-slate-700">
                الباقة *
              </span>
              <select
                className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none focus:border-[#0B2A4A]"
                value={form.plan_id}
                onChange={(event) =>
                  setForm({ ...form, plan_id: event.target.value })
                }
              >
                <option value="">اختر الباقة</option>
                {plans.map((plan) => (
                  <option key={plan.id} value={plan.id}>
                    {plan.plan_name} · {Number(plan.monthly_price || 0).toFixed(3)}
                  </option>
                ))}
              </select>
            </label>

            <Field
              label="بداية الاشتراك *"
              type="date"
              value={form.start_date}
              onChange={(value) => setForm({ ...form, start_date: value })}
            />
            <Field
              label="نهاية الاشتراك *"
              type="date"
              value={form.end_date}
              onChange={(value) => setForm({ ...form, end_date: value })}
            />
          </div>

          <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button
              type="button"
              onClick={() => {
                setShowForm(false);
                setForm(emptyForm);
              }}
              disabled={saving}
              className="rounded-2xl border border-slate-300 bg-white px-6 py-3.5 font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              إلغاء
            </button>
            <button
              type="button"
              onClick={() => void saveCompany()}
              disabled={saving}
              className="rounded-2xl bg-[#0B2A4A] px-7 py-3.5 font-black text-white hover:bg-[#123D68] disabled:opacity-60"
            >
              {saving ? "جاري التأسيس..." : "حفظ وتأسيس الشركة"}
            </button>
          </div>
        </section>
      ) : null}

      <section className="rounded-[28px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-xl font-black text-[#0B2A4A]">
              دليل الشركات
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              {loading ? "جاري التحميل..." : `${filteredCompanies.length} شركة`}
            </p>
          </div>
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="بحث بالشركة، المالك، الجوال، المدينة..."
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-[#0B2A4A] sm:max-w-md"
          />
        </div>
      </section>

      <div className="grid gap-4 lg:hidden">
        {filteredCompanies.map((company) => (
          <article
            key={company.id}
            className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h3 className="truncate text-lg font-black text-[#0B2A4A]">
                  {company.company_name}
                </h3>
                <p className="mt-1 truncate text-sm text-slate-500">
                  {company.owner_name || "بدون اسم مالك"}
                </p>
              </div>
              <span
                className={`shrink-0 rounded-full px-3 py-1.5 text-[11px] font-black ${
                  Number(company.is_active) === 1 &&
                  String(company.status || "ACTIVE").toUpperCase() === "ACTIVE"
                    ? "bg-emerald-100 text-emerald-700"
                    : "bg-amber-100 text-amber-800"
                }`}
              >
                {statusLabel(company.status, company.is_active)}
              </span>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
              <Info label="الجوال" value={company.phone || "-"} />
              <Info label="المدينة" value={company.city || "-"} />
              <Info label="الباقة" value={company.plan_name || "-"} />
              <Info label="ينتهي في" value={formatDate(company.end_date)} />
            </div>

            <button
              type="button"
              onClick={() => requestSupportAccess(company)}
              className="mt-4 w-full rounded-2xl bg-[#0B2A4A] px-4 py-3.5 font-black text-white hover:bg-[#123D68]"
            >
              دخول دعم آمن
            </button>
          </article>
        ))}

        {!loading && filteredCompanies.length === 0 ? (
          <div className="rounded-[24px] border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
            لا توجد شركات مطابقة للبحث.
          </div>
        ) : null}
      </div>

      <section className="hidden overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm lg:block">
        <div className="overflow-x-auto">
          <table className="min-w-[1150px] w-full text-right">
            <thead className="bg-slate-100 text-sm text-slate-600">
              <tr>
                <th className="p-4">الشركة</th>
                <th className="p-4">المالك</th>
                <th className="p-4">الجوال</th>
                <th className="p-4">المدينة</th>
                <th className="p-4">الباقة</th>
                <th className="p-4">مدة الاشتراك</th>
                <th className="p-4">الحالة</th>
                <th className="p-4">العملية</th>
              </tr>
            </thead>
            <tbody>
              {filteredCompanies.map((company) => (
                <tr key={company.id} className="border-t border-slate-100 hover:bg-slate-50/80">
                  <td className="p-4 font-black text-[#0B2A4A]">
                    {company.company_name}
                  </td>
                  <td className="p-4">{company.owner_name || "-"}</td>
                  <td className="p-4" dir="ltr">{company.phone || "-"}</td>
                  <td className="p-4">{company.city || "-"}</td>
                  <td className="p-4">{company.plan_name || "-"}</td>
                  <td className="p-4 text-xs leading-6 text-slate-600">
                    {formatDate(company.start_date)}
                    <br />
                    {formatDate(company.end_date)}
                  </td>
                  <td className="p-4">
                    <span className="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-black text-emerald-700">
                      {statusLabel(company.status, company.is_active)}
                    </span>
                  </td>
                  <td className="p-4">
                    <button
                      type="button"
                      onClick={() => requestSupportAccess(company)}
                      className="rounded-xl bg-[#0B2A4A] px-4 py-2.5 text-sm font-black text-white hover:bg-[#123D68]"
                    >
                      دخول دعم
                    </button>
                  </td>
                </tr>
              ))}

              {!loading && filteredCompanies.length === 0 ? (
                <tr>
                  <td colSpan={8} className="p-8 text-center text-slate-500">
                    لا توجد شركات مطابقة للبحث.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </section>

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        confirmText={dialog.confirmText}
        showCancel={dialog.showCancel}
        loading={supportLoading}
        onConfirm={confirmDialog}
        onClose={() => {
          if (!supportLoading) {
            setDialog(emptyDialog);
            setSelectedCompany(null);
          }
        }}
      />
    </section>
  );
}

function Metric({
  label,
  value,
  className = "",
}: {
  label: string;
  value: number;
  className?: string;
}) {
  return (
    <div className={`rounded-2xl bg-white/10 p-4 ${className}`}>
      <div className="text-xs font-bold text-blue-100">{label}</div>
      <div className="mt-1 text-3xl font-black">{value}</div>
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  type = "text",
  inputMode,
  dir = "rtl",
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  inputMode?: "text" | "numeric" | "email" | "tel";
  dir?: "rtl" | "ltr";
}) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-black text-slate-700">
        {label}
      </span>
      <input
        type={type}
        inputMode={inputMode}
        dir={dir}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 outline-none focus:border-[#0B2A4A]"
      />
    </label>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl bg-slate-50 p-3">
      <div className="text-[11px] font-bold text-slate-500">{label}</div>
      <div className="mt-1 truncate font-black text-slate-800">{value}</div>
    </div>
  );
}
