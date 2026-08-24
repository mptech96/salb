"use client";

import Link from "next/link";
import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import api from "../api";

type Plan = {
  id: number;
  plan_name: string;
  plan_code?: string;
  monthly_price?: number | string;
  yearly_price?: number | string;
  max_branches?: number;
  max_users?: number;
  is_active?: number | boolean;
};

type BillingPeriod = "MONTHLY" | "QUARTERLY" | "SEMI_ANNUAL" | "YEARLY";

type RegistrationResult = {
  invoice_id: number;
  invoice_number: string;
  invoice_status: string;
  total_amount: number;
  currency_code: string;
  period_start: string;
  period_end: string;
  due_date: string;
  username: string;
  company_active: boolean;
};

const initialForm = {
  owner_name: "",
  username: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
  company_name: "",
  city: "",
  address: "",
  plan_id: "",
  billing_period: "MONTHLY" as BillingPeriod,
};

const billingLabels: Record<BillingPeriod, string> = {
  MONTHLY: "شهري",
  QUARTERLY: "ربع سنوي",
  SEMI_ANNUAL: "نصف سنوي",
  YEARLY: "سنوي",
};

const money = (value: number | string | undefined) => Number(value || 0).toFixed(3);

export default function RegisterPage() {
  const provisioningKey = useRef(crypto.randomUUID());
  const [plans, setPlans] = useState<Plan[]>([]);
  const [form, setForm] = useState(initialForm);
  const [loadingPlans, setLoadingPlans] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [result, setResult] = useState<RegistrationResult | null>(null);

  useEffect(() => {
    api.get("/plans")
      .then((res) => {
        const rows: Plan[] = res.data?.data || [];
        setPlans(rows.filter((p) => p.is_active === undefined || p.is_active === true || Number(p.is_active) === 1));
      })
      .catch((error) => setMessage(error?.response?.data?.message || "تعذر تحميل الباقات."))
      .finally(() => setLoadingPlans(false));
  }, []);

  const selectedPlan = useMemo(
    () => plans.find((plan) => String(plan.id) === form.plan_id),
    [plans, form.plan_id]
  );

  const estimatedPrice = useMemo(() => {
    if (!selectedPlan) return 0;
    const monthly = Number(selectedPlan.monthly_price || 0);
    const yearly = Number(selectedPlan.yearly_price || monthly * 12);
    if (form.billing_period === "QUARTERLY") return monthly * 3;
    if (form.billing_period === "SEMI_ANNUAL") return monthly * 6;
    if (form.billing_period === "YEARLY") return yearly;
    return monthly;
  }, [selectedPlan, form.billing_period]);

  const update = (name: keyof typeof initialForm, value: string) => {
    setForm((current) => ({ ...current, [name]: value }));
    setMessage("");
    setErrors((current) => {
      const next = { ...current };
      delete next[name];
      return next;
    });
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setMessage("");
    setErrors({});

    if (!form.plan_id) {
      setMessage("اختر الباقة المناسبة.");
      return;
    }

    setSubmitting(true);

    try {
      const res = await api.post("/register-company", {
          ...form,
          plan_id: Number(form.plan_id),
        }, { headers: { "Idempotency-Key": provisioningKey.current } });

      provisioningKey.current = crypto.randomUUID();
      setResult(res.data.data);
      localStorage.setItem("sulb_pending_registration", JSON.stringify(res.data.data));
    } catch (error: any) {
      setMessage(error?.response?.data?.message || "حدث خطأ أثناء إنشاء الحساب.");
      setErrors(error?.response?.data?.errors || {});
    } finally {
      setSubmitting(false);
    }
  };

  if (result) {
    return (
      <main dir="rtl" className="min-h-screen bg-slate-50 px-4 py-12">
        <div className="mx-auto max-w-3xl overflow-hidden rounded-[32px] border bg-white shadow-xl">
          <div className="bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-10 text-center text-white">
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white/15 text-4xl">✓</div>
            <h1 className="mt-5 text-3xl font-black">تم إنشاء حسابك بنجاح</h1>
            <p className="mt-3 text-blue-100">تم إنشاء الشركة والفرع والمدير والاشتراك والفاتورة.</p>
          </div>

          <div className="space-y-5 p-7 sm:p-10">
            <div className="grid gap-4 sm:grid-cols-2">
              <Info label="رقم الفاتورة" value={result.invoice_number} />
              <Info label="حالة الفاتورة" value={result.invoice_status === "PAID" ? "مدفوعة" : "غير مدفوعة"} />
              <Info label="الإجمالي" value={`${money(result.total_amount)} ${result.currency_code}`} />
              <Info label="الاستحقاق" value={result.due_date || "-"} />
              <Info label="بداية الاشتراك" value={result.period_start || "-"} />
              <Info label="نهاية الاشتراك" value={result.period_end || "-"} />
            </div>

            {!result.company_active && (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-7 text-amber-900">
                سيتم تفعيل الشركة والاشتراك تلقائيًا بعد تسجيل سداد الفاتورة.
              </div>
            )}

            <Info label="اسم المستخدم" value={result.username} />

            <Link href="/login" className="flex w-full justify-center rounded-2xl bg-[#0B2A4A] px-6 py-4 font-black text-white hover:bg-[#123D68]">
              الانتقال إلى تسجيل الدخول
            </Link>
          </div>
        </div>
      </main>
    );
  }

  return (
    <main dir="rtl" className="min-h-screen bg-slate-50 px-4 py-10">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <p className="text-sm font-bold text-[#123D68]">SULB ERP · صلب ERP</p>
            <h1 className="mt-2 text-4xl font-black text-[#0B2A4A]">إنشاء حساب شركة جديد</h1>
            <p className="mt-3 text-slate-600">أدخل بيانات الشركة والمدير ثم اختر الباقة ودورة الفوترة.</p>
          </div>
          <Link href="/login" className="rounded-2xl border bg-white px-5 py-3 font-bold text-[#0B2A4A]">لدي حساب بالفعل</Link>
        </div>

        <form onSubmit={submit} className="grid gap-6 xl:grid-cols-[1fr_380px]">
          <div className="space-y-6">
            <Section number="1" title="بيانات مدير الشركة">
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="الاسم الكامل" value={form.owner_name} onChange={(v) => update("owner_name", v)} error={errors.owner_name?.[0]} required />
                <Field label="اسم المستخدم" value={form.username} onChange={(v) => update("username", v)} error={errors.username?.[0]} required dir="ltr" />
                <Field label="رقم الجوال" value={form.phone} onChange={(v) => update("phone", v)} error={errors.phone?.[0]} required dir="ltr" />
                <Field label="البريد الإلكتروني" type="email" value={form.email} onChange={(v) => update("email", v)} error={errors.email?.[0]} dir="ltr" />
                <Field label="كلمة المرور" type="password" value={form.password} onChange={(v) => update("password", v)} error={errors.password?.[0]} required dir="ltr" />
                <Field label="تأكيد كلمة المرور" type="password" value={form.password_confirmation} onChange={(v) => update("password_confirmation", v)} error={errors.password_confirmation?.[0]} required dir="ltr" />
              </div>
            </Section>

            <Section number="2" title="بيانات الشركة">
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="اسم الشركة" value={form.company_name} onChange={(v) => update("company_name", v)} error={errors.company_name?.[0]} required />
                <Field label="المدينة" value={form.city} onChange={(v) => update("city", v)} error={errors.city?.[0]} />
                <div className="md:col-span-2">
                  <Field label="العنوان" value={form.address} onChange={(v) => update("address", v)} error={errors.address?.[0]} />
                </div>
              </div>
            </Section>

            <Section number="3" title="اختيار الباقة">
              {loadingPlans ? (
                <div className="py-10 text-center text-slate-500">جاري تحميل الباقات...</div>
              ) : (
                <div className="grid gap-4 lg:grid-cols-3">
                  {plans.map((plan) => {
                    const active = String(plan.id) === form.plan_id;
                    return (
                      <button key={plan.id} type="button" onClick={() => update("plan_id", String(plan.id))}
                        className={`rounded-3xl border p-5 text-right ${active ? "border-[#0B2A4A] bg-blue-50 ring-2 ring-[#0B2A4A]/10" : "bg-white hover:border-slate-400"}`}>
                        <div className="text-lg font-black text-[#0B2A4A]">{plan.plan_name}</div>
                        <div className="mt-5 text-3xl font-black text-[#0B2A4A]">{money(plan.monthly_price)} <span className="text-sm text-slate-500">SAR / شهر</span></div>
                        <div className="mt-4 text-sm text-slate-600">{plan.max_users ? `حتى ${plan.max_users} مستخدم` : ""}</div>
                        <div className="mt-1 text-sm text-slate-600">{plan.max_branches ? `حتى ${plan.max_branches} فرع` : ""}</div>
                      </button>
                    );
                  })}
                </div>
              )}
            </Section>

            <Section number="4" title="دورة الفوترة">
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {(Object.keys(billingLabels) as BillingPeriod[]).map((period) => (
                  <button key={period} type="button" onClick={() => update("billing_period", period)}
                    className={`rounded-2xl border p-4 font-black ${form.billing_period === period ? "border-[#0B2A4A] bg-[#0B2A4A] text-white" : "bg-white text-slate-800"}`}>
                    {billingLabels[period]}
                  </button>
                ))}
              </div>
            </Section>
          </div>

          <aside className="xl:sticky xl:top-6 xl:self-start">
            <div className="rounded-[28px] border bg-white p-6 shadow-lg">
              <h2 className="text-xl font-black text-[#0B2A4A]">ملخص التسجيل</h2>
              <div className="mt-6 space-y-4 border-b pb-6 text-sm">
                <Row label="الشركة" value={form.company_name || "-"} />
                <Row label="المدير" value={form.owner_name || "-"} />
                <Row label="الباقة" value={selectedPlan?.plan_name || "-"} />
                <Row label="الفوترة" value={billingLabels[form.billing_period]} />
              </div>
              <div className="py-6">
                <p className="text-sm text-slate-500">القيمة قبل الضريبة</p>
                <div className="mt-2 text-4xl font-black text-[#0B2A4A]">{money(estimatedPrice)} <span className="text-base text-slate-500">SAR</span></div>
              </div>
              {message && <div className="mb-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">{message}</div>}
              <button disabled={submitting || loadingPlans || plans.length === 0}
                className="w-full rounded-2xl bg-[#0B2A4A] px-6 py-4 font-black text-white disabled:opacity-60">
                {submitting ? "جاري إنشاء الحساب..." : "إنشاء الحساب والفاتورة"}
              </button>
            </div>
          </aside>
        </form>
      </div>
    </main>
  );
}

function Section({ number, title, children }: { number: string; title: string; children: React.ReactNode }) {
  return <section className="rounded-[28px] border bg-white p-6 shadow-sm"><div className="mb-6 flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#0B2A4A] font-black text-white">{number}</span><h2 className="text-xl font-black text-[#0B2A4A]">{title}</h2></div>{children}</section>;
}

function Field({ label, value, onChange, error, required = false, type = "text", dir = "rtl" }: { label: string; value: string; onChange: (value: string) => void; error?: string; required?: boolean; type?: string; dir?: "rtl" | "ltr" }) {
  return <label className="block"><span className="mb-2 block text-sm font-bold text-slate-700">{label}{required && <span className="mr-1 text-red-500">*</span>}</span><input type={type} dir={dir} value={value} onChange={(e) => onChange(e.target.value)} required={required} className={`w-full rounded-2xl border bg-slate-50 px-4 py-3.5 outline-none ${error ? "border-red-400" : "border-slate-200 focus:border-[#0B2A4A]"}`} />{error && <span className="mt-2 block text-xs font-bold text-red-600">{error}</span>}</label>;
}

function Row({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between gap-4"><span className="text-slate-500">{label}</span><span className="font-bold text-slate-800">{value}</span></div>;
}

function Info({ label, value }: { label: string; value: string }) {
  return <div className="rounded-2xl border bg-slate-50 p-4"><div className="text-xs font-bold text-slate-500">{label}</div><div className="mt-2 break-words font-black text-[#0B2A4A]">{value}</div></div>;
}
