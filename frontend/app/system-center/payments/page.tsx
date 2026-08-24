"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import api from "../../api";

type Dashboard = {
  companies: number;
  subscriptions: number;
  invoices: number;
  payments: number;
  paid_amount: number | string;
  unpaid_amount: number | string;
  paid_invoices?: number;
  unpaid_invoices?: number;
  partial_invoices?: number;
  cancelled_invoices?: number;
};

type Subscription = {
  id: number;
  company_id: number;
  company_name: string;
  plan_id: number;
  plan_name: string;
  start_date?: string | null;
  end_date?: string | null;
  status?: string;
};

type Plan = {
  id: number;
  plan_name: string;
  plan_code?: string;
  monthly_price: number | string;
  yearly_price: number | string | null;
  is_active?: boolean | number;
};

type Invoice = {
  id: number;
  company_id: number;
  subscription_id: number | null;
  plan_id: number | null;
  invoice_number: string;
  invoice_date: string;
  due_date: string | null;
  subtotal: number | string;
  discount_amount: number | string;
  tax_rate: number | string;
  tax_amount: number | string;
  total_amount: number | string;
  paid_amount: number | string;
  remaining_amount: number | string;
  currency_code: string;
  status: string;
  billing_period: string;
  period_start: string | null;
  period_end: string | null;
  notes: string | null;
  company_name: string | null;
  plan_name: string | null;
};

type Payment = {
  id: number;
  company_id: number;
  subscription_id: number | null;
  invoice_id: number | null;
  payment_number: string;
  payment_date: string;
  amount: number | string;
  currency_code: string;
  payment_method: string;
  payment_status: string;
  reference_number: string | null;
  gateway_name: string | null;
  bank_name: string | null;
  notes: string | null;
  company_name: string | null;
  invoice_number: string | null;
};

type DialogMode = "INVOICE" | "PAYMENT" | null;

const emptyDashboard: Dashboard = {
  companies: 0,
  subscriptions: 0,
  invoices: 0,
  payments: 0,
  paid_amount: 0,
  unpaid_amount: 0,
  paid_invoices: 0,
  unpaid_invoices: 0,
  partial_invoices: 0,
  cancelled_invoices: 0,
};

const inputClass =
  "w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm text-slate-900 outline-none transition focus:border-[#0B2A4A] focus:bg-white";

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function addDays(dateValue: string, days: number): string {
  const date = new Date(`${dateValue}T00:00:00`);
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
}

function addMonths(dateValue: string, months: number): string {
  const date = new Date(`${dateValue}T00:00:00`);
  const originalDay = date.getDate();

  date.setDate(1);
  date.setMonth(date.getMonth() + months);

  const lastDayOfTargetMonth = new Date(
    date.getFullYear(),
    date.getMonth() + 1,
    0
  ).getDate();

  date.setDate(Math.min(originalDay, lastDayOfTargetMonth));
  return date.toISOString().slice(0, 10);
}

function calculateBillingPeriodEnd(
  periodStart: string,
  billingPeriod: string
): string {
  if (!periodStart) return "";

  switch (billingPeriod) {
    case "MONTHLY":
      return addDays(addMonths(periodStart, 1), -1);
    case "QUARTERLY":
      return addDays(addMonths(periodStart, 3), -1);
    case "SEMI_ANNUAL":
      return addDays(addMonths(periodStart, 6), -1);
    case "YEARLY":
      return addDays(addMonths(periodStart, 12), -1);
    default:
      return periodStart;
  }
}

function calculatePlanSubtotal(
  plan: Plan | undefined,
  billingPeriod: string
): string {
  if (!plan) return "";

  const monthlyPrice = Number(plan.monthly_price || 0);

  switch (billingPeriod) {
    case "MONTHLY":
      return String(monthlyPrice);
    case "QUARTERLY":
      return String(monthlyPrice * 3);
    case "SEMI_ANNUAL":
      return String(monthlyPrice * 6);
    case "YEARLY":
      return plan.yearly_price === null || plan.yearly_price === ""
        ? ""
        : String(Number(plan.yearly_price));
    default:
      return "";
  }
}

function initialInvoiceForm() {
  const invoiceDate = today();

  return {
    subscription_id: "",
    company_id: "",
    plan_id: "",
    invoice_date: invoiceDate,
    due_date: addDays(invoiceDate, 7),
    subtotal: "",
    discount_amount: "0",
    tax_rate: "15",
    currency_code: "SAR",
    billing_period: "YEARLY",
    period_start: invoiceDate,
    period_end: calculateBillingPeriodEnd(invoiceDate, "YEARLY"),
    notes: "",
  };
}

function initialPaymentForm() {
  return {
    invoice_id: "",
    payment_date: today(),
    amount: "",
    currency_code: "SAR",
    payment_method: "BANK_TRANSFER",
    reference_number: "",
    bank_name: "",
    account_number: "",
    gateway_name: "",
    gateway_transaction_id: "",
    notes: "",
  };
}

export default function PaymentsPage() {
  const [dashboard, setDashboard] = useState<Dashboard>(emptyDashboard);
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);

  const [activeTab, setActiveTab] =
    useState<"INVOICES" | "PAYMENTS">("INVOICES");

  const [dialogMode, setDialogMode] = useState<DialogMode>(null);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);

  const [invoiceForm, setInvoiceForm] = useState(initialInvoiceForm);
  const [paymentForm, setPaymentForm] = useState(initialPaymentForm);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [cancellingId, setCancellingId] = useState<number | null>(null);
  const [error, setError] = useState("");

  const loadData = useCallback(async (manual = false) => {
    manual ? setRefreshing(true) : setLoading(true);
    setError("");

    try {
      const [
        dashboardResponse,
        invoicesResponse,
        paymentsResponse,
        subscriptionsResponse,
        plansResponse,
      ] = await Promise.all([
        api.get("/system-admin/payment-dashboard"),
        api.get("/system-admin/invoices"),
        api.get("/system-admin/payments"),
        api.get("/system-admin/subscriptions"),
        api.get("/plans"),
      ]);

      setDashboard(dashboardResponse.data?.data || emptyDashboard);

      setInvoices(
        Array.isArray(invoicesResponse.data?.data)
          ? invoicesResponse.data.data
          : []
      );

      setPayments(
        Array.isArray(paymentsResponse.data?.data)
          ? paymentsResponse.data.data
          : []
      );

      setSubscriptions(
        Array.isArray(subscriptionsResponse.data?.data?.subscriptions)
          ? subscriptionsResponse.data.data.subscriptions
          : []
      );

      setPlans(
        Array.isArray(plansResponse.data?.data)
          ? plansResponse.data.data
          : []
      );
    } catch (requestError: any) {
      setError(
        requestError?.response?.data?.message ||
          "تعذر تحميل بيانات الفواتير والمدفوعات"
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const filteredInvoices = useMemo(() => {
    const query = search.trim().toLowerCase();

    return invoices.filter((invoice) => {
      const text = [
        invoice.invoice_number,
        invoice.company_name,
        invoice.plan_name,
        invoice.status,
        invoice.billing_period,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      return (
        (!query || text.includes(query)) &&
        (statusFilter === "ALL" || invoice.status === statusFilter)
      );
    });
  }, [invoices, search, statusFilter]);

  const filteredPayments = useMemo(() => {
    const query = search.trim().toLowerCase();

    return payments.filter((payment) => {
      const text = [
        payment.payment_number,
        payment.company_name,
        payment.invoice_number,
        payment.payment_method,
        payment.payment_status,
        payment.reference_number,
        payment.bank_name,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      return !query || text.includes(query);
    });
  }, [payments, search]);

  function openInvoiceDialog() {
    setSelectedInvoice(null);
    setInvoiceForm(initialInvoiceForm());
    setDialogMode("INVOICE");
  }

  function openPaymentDialog(invoice?: Invoice) {
    const form = initialPaymentForm();

    if (invoice) {
      form.invoice_id = String(invoice.id);
      form.amount = String(invoice.remaining_amount || "");
      form.currency_code = invoice.currency_code || "SAR";
      setSelectedInvoice(invoice);
    } else {
      setSelectedInvoice(null);
    }

    setPaymentForm(form);
    setDialogMode("PAYMENT");
  }

  function closeDialog() {
    if (saving) {
      return;
    }

    setDialogMode(null);
    setSelectedInvoice(null);
  }

  function handleSubscriptionChange(subscriptionId: string) {
    const subscription = subscriptions.find(
      (item) => Number(item.id) === Number(subscriptionId)
    );

    if (!subscription) {
      setInvoiceForm((current) => ({
        ...current,
        subscription_id: "",
        company_id: "",
        plan_id: "",
        subtotal: "",
      }));

      return;
    }

    const plan = plans.find(
      (item) => Number(item.id) === Number(subscription.plan_id)
    );

    const startDate = subscription.start_date || today();
    const endDate = subscription.end_date || addDays(startDate, 365);

    setInvoiceForm((current) => ({
      ...current,
      subscription_id: String(subscription.id),
      company_id: String(subscription.company_id),
      plan_id: String(subscription.plan_id),
      subtotal: plan
        ? calculatePlanSubtotal(plan, current.billing_period)
        : current.subtotal,
      period_start: startDate,
      period_end:
        current.billing_period === "CUSTOM"
          ? endDate
          : calculateBillingPeriodEnd(startDate, current.billing_period),
    }));
  }

  function handleInvoiceSelection(invoiceId: string) {
    const invoice = invoices.find(
      (item) => Number(item.id) === Number(invoiceId)
    );

    setSelectedInvoice(invoice || null);

    setPaymentForm((current) => ({
      ...current,
      invoice_id: invoiceId,
      amount: invoice ? String(invoice.remaining_amount || "") : "",
      currency_code: invoice?.currency_code || "SAR",
    }));
  }

  async function createInvoice(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!invoiceForm.company_id) {
      alert("اختر الاشتراك والشركة");
      return;
    }

    if (!invoiceForm.plan_id) {
      alert("اختر الباقة");
      return;
    }

    if (!invoiceForm.subtotal || Number(invoiceForm.subtotal) < 0) {
      if (invoiceForm.billing_period === "YEARLY") {
        alert("السعر السنوي غير محدد لهذه الباقة. حدده من شاشة الباقات أولًا.");
      } else {
        alert("أدخل قيمة الفاتورة");
      }
      return;
    }

    setSaving(true);

    try {
      await api.post("/system-admin/invoices", {
        company_id: Number(invoiceForm.company_id),

        subscription_id: invoiceForm.subscription_id
          ? Number(invoiceForm.subscription_id)
          : null,

        plan_id: Number(invoiceForm.plan_id),
        invoice_date: invoiceForm.invoice_date,
        due_date: invoiceForm.due_date || null,
        subtotal: Number(invoiceForm.subtotal),
        discount_amount: Number(invoiceForm.discount_amount || 0),
        tax_rate: Number(invoiceForm.tax_rate || 0),
        currency_code: invoiceForm.currency_code,
        billing_period: invoiceForm.billing_period,
        period_start: invoiceForm.period_start || null,
        period_end: invoiceForm.period_end || null,
        notes: invoiceForm.notes || null,
      });

      alert("تم إنشاء فاتورة الاشتراك بنجاح");

      setDialogMode(null);
      setInvoiceForm(initialInvoiceForm());

      await loadData(true);
    } catch (requestError: any) {
      alert(
        requestError?.response?.data?.message ||
          firstValidationError(requestError?.response?.data?.errors) ||
          "تعذر إنشاء الفاتورة"
      );
    } finally {
      setSaving(false);
    }
  }

  async function createPayment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!paymentForm.invoice_id) {
      alert("اختر الفاتورة");
      return;
    }

    if (!paymentForm.amount || Number(paymentForm.amount) <= 0) {
      alert("أدخل مبلغ دفعة صحيح");
      return;
    }

    if (
      selectedInvoice &&
      Number(paymentForm.amount) > Number(selectedInvoice.remaining_amount)
    ) {
      alert("مبلغ الدفعة أكبر من المبلغ المتبقي");
      return;
    }

    setSaving(true);

    try {
      await api.post("/system-admin/payments", {
        invoice_id: Number(paymentForm.invoice_id),
        payment_date: paymentForm.payment_date,
        amount: Number(paymentForm.amount),
        currency_code: paymentForm.currency_code,
        payment_method: paymentForm.payment_method,
        reference_number: paymentForm.reference_number || null,
        bank_name: paymentForm.bank_name || null,
        account_number: paymentForm.account_number || null,
        gateway_name: paymentForm.gateway_name || null,

        gateway_transaction_id:
          paymentForm.gateway_transaction_id || null,

        notes: paymentForm.notes || null,
      });

      alert("تم تسجيل الدفعة وتحديث الفاتورة بنجاح");

      setDialogMode(null);
      setSelectedInvoice(null);
      setPaymentForm(initialPaymentForm());

      await loadData(true);
    } catch (requestError: any) {
      alert(
        requestError?.response?.data?.message ||
          firstValidationError(requestError?.response?.data?.errors) ||
          "تعذر تسجيل الدفعة"
      );
    } finally {
      setSaving(false);
    }
  }

  async function cancelInvoice(invoice: Invoice) {
    if (invoice.status === "PAID") {
      alert("لا يمكن إلغاء فاتورة مدفوعة بالكامل");
      return;
    }

    if (Number(invoice.paid_amount) > 0) {
      alert("لا يمكن إلغاء فاتورة تحتوي على دفعات");
      return;
    }

    const confirmed = window.confirm(
      `هل تريد إلغاء الفاتورة ${invoice.invoice_number}؟`
    );

    if (!confirmed) {
      return;
    }

    setCancellingId(invoice.id);

    try {
      await api.put(`/system-admin/invoices/${invoice.id}/cancel`);

      alert("تم إلغاء الفاتورة بنجاح");

      await loadData(true);
    } catch (requestError: any) {
      alert(
        requestError?.response?.data?.message ||
          "تعذر إلغاء الفاتورة"
      );
    } finally {
      setCancellingId(null);
    }
  }

  const availableInvoices = invoices.filter(
    (invoice) =>
      invoice.status !== "PAID" &&
      invoice.status !== "CANCELLED" &&
      Number(invoice.remaining_amount) > 0
  );

  const invoicePreview = calculateInvoicePreview(invoiceForm);

  return (
    <section dir="rtl" className="space-y-6">
      <header
        className="overflow-hidden rounded-[32px] p-6 text-white shadow-xl sm:p-8"
        style={{
          background:
            "linear-gradient(270deg, #071D33 0%, #0B2A4A 50%, #164F82 100%)",
        }}
      >
        <div className="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <div className="mb-3 inline-flex rounded-full bg-white/10 px-4 py-2 text-sm font-bold text-blue-100">
              الإدارة المالية لاشتراكات المنصة
            </div>

            <h1 className="text-3xl font-black sm:text-4xl">
              الفواتير والمدفوعات
            </h1>

            <p className="mt-3 max-w-3xl text-sm leading-7 text-blue-100 sm:text-base">
              إنشاء فواتير الاشتراكات وتسجيل الدفعات ومتابعة التحصيل
              والمبالغ المتبقية.
            </p>
          </div>

          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={openInvoiceDialog}
              className="rounded-2xl bg-white px-5 py-3 text-sm font-black text-[#0B2A4A] shadow hover:bg-blue-50"
            >
              + إنشاء فاتورة
            </button>

            <button
              type="button"
              onClick={() => openPaymentDialog()}
              className="rounded-2xl border border-white/30 bg-white/10 px-5 py-3 text-sm font-black text-white hover:bg-white/20"
            >
              + تسجيل دفعة
            </button>

            <button
              type="button"
              onClick={() => loadData(true)}
              disabled={refreshing}
              className="rounded-2xl border border-white/30 bg-white/10 px-5 py-3 text-sm font-black text-white hover:bg-white/20 disabled:opacity-60"
            >
              {refreshing ? "جاري التحديث..." : "تحديث"}
            </button>
          </div>
        </div>

        <div className="mt-7 grid grid-cols-2 gap-3 md:grid-cols-4">
          <HeroStat title="إجمالي الفواتير" value={dashboard.invoices} />
          <HeroStat title="إجمالي المدفوعات" value={dashboard.payments} />
          <HeroMoney title="المبلغ المحصل" value={dashboard.paid_amount} />
          <HeroMoney title="المبلغ المتبقي" value={dashboard.unpaid_amount} />
        </div>
      </header>

      {error ? (
        <div className="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-700">
          <div className="font-black">تعذر تحميل البيانات</div>
          <div className="mt-1 text-sm">{error}</div>

          <button
            type="button"
            onClick={() => loadData()}
            className="mt-4 rounded-xl bg-red-700 px-5 py-2.5 text-sm font-black text-white"
          >
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <SmallStat
          title="فواتير مدفوعة"
          value={dashboard.paid_invoices || 0}
        />

        <SmallStat
          title="غير مدفوعة"
          value={dashboard.unpaid_invoices || 0}
        />

        <SmallStat
          title="مدفوعة جزئيًا"
          value={dashboard.partial_invoices || 0}
        />

        <SmallStat
          title="فواتير ملغاة"
          value={dashboard.cancelled_invoices || 0}
        />
      </div>

      <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => setActiveTab("INVOICES")}
              className={`rounded-2xl px-5 py-3 text-sm font-black ${
                activeTab === "INVOICES"
                  ? "bg-[#0B2A4A] text-white"
                  : "bg-slate-100 text-slate-700"
              }`}
            >
              الفواتير ({invoices.length})
            </button>

            <button
              type="button"
              onClick={() => setActiveTab("PAYMENTS")}
              className={`rounded-2xl px-5 py-3 text-sm font-black ${
                activeTab === "PAYMENTS"
                  ? "bg-[#0B2A4A] text-white"
                  : "bg-slate-100 text-slate-700"
              }`}
            >
              المدفوعات ({payments.length})
            </button>
          </div>

          <div className="flex w-full flex-col gap-3 sm:flex-row xl:max-w-3xl">
            {activeTab === "INVOICES" ? (
              <select
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
                className={inputClass}
              >
                <option value="ALL">جميع الحالات</option>
                <option value="UNPAID">غير مدفوع</option>
                <option value="PARTIAL">مدفوع جزئيًا</option>
                <option value="PAID">مدفوع</option>
                <option value="CANCELLED">ملغي</option>
              </select>
            ) : null}

            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={
                activeTab === "INVOICES"
                  ? "بحث برقم الفاتورة أو الشركة أو الباقة..."
                  : "بحث برقم الدفعة أو الشركة أو المرجع..."
              }
              className={inputClass}
            />
          </div>
        </div>
      </div>

      {activeTab === "INVOICES" ? (
        <InvoicesTable
          loading={loading}
          rows={filteredInvoices}
          cancellingId={cancellingId}
          onPayment={openPaymentDialog}
          onCancel={cancelInvoice}
        />
      ) : (
        <PaymentsTable loading={loading} rows={filteredPayments} />
      )}

      {dialogMode === "INVOICE" ? (
        <Dialog title="إنشاء فاتورة اشتراك" onClose={closeDialog}>
          <form onSubmit={createInvoice} className="space-y-5">
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="الاشتراك">
                <select
                  value={invoiceForm.subscription_id}
                  onChange={(event) =>
                    handleSubscriptionChange(event.target.value)
                  }
                  className={inputClass}
                  required
                >
                  <option value="">اختر الاشتراك</option>

                  {subscriptions.map((subscription) => (
                    <option key={subscription.id} value={subscription.id}>
                      {subscription.company_name} —{" "}
                      {subscription.plan_name || "بدون باقة"}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="الباقة">
                <select
                  value={invoiceForm.plan_id}
                  onChange={(event) => {
                    const planId = event.target.value;

                    const plan = plans.find(
                      (item) => Number(item.id) === Number(planId)
                    );

                    setInvoiceForm((current) => ({
                      ...current,
                      plan_id: planId,

                      subtotal: plan
                        ? calculatePlanSubtotal(plan, current.billing_period)
                        : current.subtotal,
                    }));
                  }}
                  className={inputClass}
                  required
                >
                  <option value="">اختر الباقة</option>

                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>
                      {plan.plan_name} — شهري {money(plan.monthly_price)} ر.س
                      {plan.yearly_price !== null && plan.yearly_price !== ""
                        ? ` — سنوي ${money(plan.yearly_price)} ر.س`
                        : ""}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="تاريخ الفاتورة">
                <input
                  type="date"
                  value={invoiceForm.invoice_date}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      invoice_date: event.target.value,
                    }))
                  }
                  className={inputClass}
                  required
                />
              </Field>

              <Field label="تاريخ الاستحقاق">
                <input
                  type="date"
                  value={invoiceForm.due_date}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      due_date: event.target.value,
                    }))
                  }
                  min={invoiceForm.invoice_date}
                  className={inputClass}
                />
              </Field>

              <Field label="دورة الفوترة">
                <select
                  value={invoiceForm.billing_period}
                  onChange={(event) => {
                    const billingPeriod = event.target.value;

                    setInvoiceForm((current) => {
                      const selectedPlan = plans.find(
                        (plan) => Number(plan.id) === Number(current.plan_id)
                      );

                      return {
                        ...current,
                        billing_period: billingPeriod,
                        subtotal:
                          billingPeriod === "CUSTOM"
                            ? current.subtotal
                            : calculatePlanSubtotal(selectedPlan, billingPeriod),
                        period_end:
                          billingPeriod === "CUSTOM"
                            ? current.period_end
                            : calculateBillingPeriodEnd(
                                current.period_start,
                                billingPeriod
                              ),
                      };
                    });
                    }}
                  className={inputClass}
                >
                  <option value="MONTHLY">شهري</option>
                  <option value="QUARTERLY">ربع سنوي</option>
                  <option value="SEMI_ANNUAL">نصف سنوي</option>
                  <option value="YEARLY">سنوي</option>
                  <option value="CUSTOM">مخصص</option>
                </select>
              </Field>

              <Field label="العملة">
                <select
                  value={invoiceForm.currency_code}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      currency_code: event.target.value,
                    }))
                  }
                  className={inputClass}
                >
                  <option value="SAR">ريال سعودي</option>
                  <option value="USD">دولار أمريكي</option>
                </select>
              </Field>

              <Field label="تاريخ بداية الفترة">
                <input
                  type="date"
                  value={invoiceForm.period_start}
                  onChange={(event) => {
                    const periodStart = event.target.value;

                    setInvoiceForm((current) => ({
                      ...current,
                      period_start: periodStart,
                      period_end:
                        current.billing_period === "CUSTOM"
                          ? current.period_end
                          : calculateBillingPeriodEnd(
                              periodStart,
                              current.billing_period
                            ),
                    }));
                  }}
                  className={inputClass}
                />
              </Field>

              <Field label="تاريخ نهاية الفترة">
                <input
                  type="date"
                  value={invoiceForm.period_end}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      period_end: event.target.value,
                    }))
                  }
                  min={invoiceForm.period_start}
                  className={inputClass}
                />
              </Field>

              <Field label="المبلغ قبل الخصم والضريبة">
                <input
                  type="number"
                  min="0"
                  step="0.001"
                  value={invoiceForm.subtotal}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      subtotal: event.target.value,
                    }))
                  }
                  readOnly={invoiceForm.billing_period !== "CUSTOM"}
                  className={`${inputClass} ${
                    invoiceForm.billing_period !== "CUSTOM"
                      ? "cursor-not-allowed bg-slate-100 text-slate-600"
                      : ""
                  }`}
                  required
                />
              </Field>

              <Field label="قيمة الخصم">
                <input
                  type="number"
                  min="0"
                  step="0.001"
                  value={invoiceForm.discount_amount}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      discount_amount: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <Field label="نسبة الضريبة">
                <input
                  type="number"
                  min="0"
                  max="100"
                  step="0.001"
                  value={invoiceForm.tax_rate}
                  onChange={(event) =>
                    setInvoiceForm((current) => ({
                      ...current,
                      tax_rate: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <div className="rounded-2xl bg-[#0B2A4A] p-4 text-white">
                <div className="text-xs text-blue-100">إجمالي الفاتورة</div>

                <div className="mt-2 text-2xl font-black">
                  {money(invoicePreview.total)} ر.س
                </div>

                <div className="mt-2 text-xs text-blue-100">
                  الضريبة: {money(invoicePreview.tax)} ر.س
                </div>
              </div>
            </div>

            <Field label="الملاحظات">
              <textarea
                rows={3}
                value={invoiceForm.notes}
                onChange={(event) =>
                  setInvoiceForm((current) => ({
                    ...current,
                    notes: event.target.value,
                  }))
                }
                className={inputClass}
              />
            </Field>

            <DialogActions
              saving={saving}
              submitLabel="إنشاء الفاتورة"
              onCancel={closeDialog}
            />
          </form>
        </Dialog>
      ) : null}

      {dialogMode === "PAYMENT" ? (
        <Dialog title="تسجيل دفعة اشتراك" onClose={closeDialog}>
          <form onSubmit={createPayment} className="space-y-5">
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="الفاتورة">
                <select
                  value={paymentForm.invoice_id}
                  onChange={(event) =>
                    handleInvoiceSelection(event.target.value)
                  }
                  className={inputClass}
                  required
                >
                  <option value="">اختر الفاتورة</option>

                  {availableInvoices.map((invoice) => (
                    <option key={invoice.id} value={invoice.id}>
                      {invoice.invoice_number} — {invoice.company_name} — متبقي{" "}
                      {money(invoice.remaining_amount)} ر.س
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="تاريخ الدفعة">
                <input
                  type="date"
                  value={paymentForm.payment_date}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      payment_date: event.target.value,
                    }))
                  }
                  className={inputClass}
                  required
                />
              </Field>

              <Field label="مبلغ الدفعة">
                <input
                  type="number"
                  min="0.001"
                  max={
                    selectedInvoice
                      ? Number(selectedInvoice.remaining_amount)
                      : undefined
                  }
                  step="0.001"
                  value={paymentForm.amount}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      amount: event.target.value,
                    }))
                  }
                  className={inputClass}
                  required
                />
              </Field>

              <Field label="طريقة الدفع">
                <select
                  value={paymentForm.payment_method}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      payment_method: event.target.value,
                    }))
                  }
                  className={inputClass}
                >
                  <option value="CASH">نقدي</option>
                  <option value="BANK_TRANSFER">تحويل بنكي</option>
                  <option value="CARD">بطاقة</option>
                  <option value="ONLINE">دفع إلكتروني</option>
                  <option value="CHEQUE">شيك</option>
                </select>
              </Field>

              <Field label="رقم المرجع">
                <input
                  value={paymentForm.reference_number}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      reference_number: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <Field label="اسم البنك">
                <input
                  value={paymentForm.bank_name}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      bank_name: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <Field label="رقم الحساب">
                <input
                  value={paymentForm.account_number}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      account_number: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <Field label="العملة">
                <select
                  value={paymentForm.currency_code}
                  onChange={(event) =>
                    setPaymentForm((current) => ({
                      ...current,
                      currency_code: event.target.value,
                    }))
                  }
                  className={inputClass}
                >
                  <option value="SAR">ريال سعودي</option>
                  <option value="USD">دولار أمريكي</option>
                </select>
              </Field>
            </div>

            {selectedInvoice ? (
              <div className="grid gap-3 rounded-2xl bg-slate-50 p-4 sm:grid-cols-3">
                <InvoicePreviewItem
                  title="إجمالي الفاتورة"
                  value={`${money(selectedInvoice.total_amount)} ر.س`}
                />

                <InvoicePreviewItem
                  title="المدفوع سابقًا"
                  value={`${money(selectedInvoice.paid_amount)} ر.س`}
                />

                <InvoicePreviewItem
                  title="المتبقي"
                  value={`${money(selectedInvoice.remaining_amount)} ر.س`}
                />
              </div>
            ) : null}

            <Field label="الملاحظات">
              <textarea
                rows={3}
                value={paymentForm.notes}
                onChange={(event) =>
                  setPaymentForm((current) => ({
                    ...current,
                    notes: event.target.value,
                  }))
                }
                className={inputClass}
              />
            </Field>

            <DialogActions
              saving={saving}
              submitLabel="تسجيل الدفعة"
              onCancel={closeDialog}
            />
          </form>
        </Dialog>
      ) : null}
    </section>
  );
}

function InvoicesTable({
  loading,
  rows,
  cancellingId,
  onPayment,
  onCancel,
}: {
  loading: boolean;
  rows: Invoice[];
  cancellingId: number | null;
  onPayment: (invoice: Invoice) => void;
  onCancel: (invoice: Invoice) => void;
}) {
  return (
    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-100 p-5">
        <h2 className="text-xl font-black text-[#0B2A4A]">
          فواتير الاشتراكات
        </h2>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[1450px] text-right">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-4">رقم الفاتورة</th>
              <th className="p-4">الشركة</th>
              <th className="p-4">الباقة</th>
              <th className="p-4">التاريخ</th>
              <th className="p-4">الاستحقاق</th>
              <th className="p-4">الإجمالي</th>
              <th className="p-4">المدفوع</th>
              <th className="p-4">المتبقي</th>
              <th className="p-4">الحالة</th>
              <th className="p-4">الإجراءات</th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <LoadingRows columns={10} />
            ) : rows.length === 0 ? (
              <EmptyRow
                columns={10}
                message="لا توجد فواتير اشتراكات حتى الآن"
              />
            ) : (
              rows.map((invoice) => (
                <tr
                  key={invoice.id}
                  className="border-t border-slate-100 hover:bg-slate-50"
                >
                  <td className="p-4 font-black text-[#0B2A4A]">
                    {invoice.invoice_number}
                  </td>

                  <td className="p-4">{invoice.company_name || "-"}</td>
                  <td className="p-4">{invoice.plan_name || "-"}</td>
                  <td className="p-4">{formatDate(invoice.invoice_date)}</td>
                  <td className="p-4">{formatDate(invoice.due_date)}</td>

                  <td className="p-4 font-black">
                    {money(invoice.total_amount)} ر.س
                  </td>

                  <td className="p-4 font-bold text-emerald-700">
                    {money(invoice.paid_amount)} ر.س
                  </td>

                  <td className="p-4 font-bold text-red-700">
                    {money(invoice.remaining_amount)} ر.س
                  </td>

                  <td className="p-4">
                    <StatusBadge status={invoice.status} />
                  </td>

                  <td className="p-4">
                    <div className="flex gap-2">
                      {invoice.status !== "PAID" &&
                      invoice.status !== "CANCELLED" &&
                      Number(invoice.remaining_amount) > 0 ? (
                        <button
                          type="button"
                          onClick={() => onPayment(invoice)}
                          className="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700"
                        >
                          تسجيل دفعة
                        </button>
                      ) : null}

                      {invoice.status !== "PAID" &&
                      invoice.status !== "CANCELLED" &&
                      Number(invoice.paid_amount) === 0 ? (
                        <button
                          type="button"
                          onClick={() => onCancel(invoice)}
                          disabled={cancellingId === invoice.id}
                          className="rounded-xl bg-red-50 px-3 py-2 text-xs font-black text-red-700 hover:bg-red-100 disabled:opacity-50"
                        >
                          {cancellingId === invoice.id
                            ? "جاري الإلغاء..."
                            : "إلغاء"}
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PaymentsTable({
  loading,
  rows,
}: {
  loading: boolean;
  rows: Payment[];
}) {
  return (
    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-100 p-5">
        <h2 className="text-xl font-black text-[#0B2A4A]">
          سجل المدفوعات
        </h2>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[1250px] text-right">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-4">رقم الدفعة</th>
              <th className="p-4">الشركة</th>
              <th className="p-4">الفاتورة</th>
              <th className="p-4">التاريخ</th>
              <th className="p-4">المبلغ</th>
              <th className="p-4">طريقة الدفع</th>
              <th className="p-4">البنك</th>
              <th className="p-4">رقم المرجع</th>
              <th className="p-4">الحالة</th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <LoadingRows columns={9} />
            ) : rows.length === 0 ? (
              <EmptyRow columns={9} message="لا توجد مدفوعات مسجلة حتى الآن" />
            ) : (
              rows.map((payment) => (
                <tr
                  key={payment.id}
                  className="border-t border-slate-100 hover:bg-slate-50"
                >
                  <td className="p-4 font-black text-[#0B2A4A]">
                    {payment.payment_number}
                  </td>

                  <td className="p-4">{payment.company_name || "-"}</td>
                  <td className="p-4">{payment.invoice_number || "-"}</td>
                  <td className="p-4">{formatDate(payment.payment_date)}</td>

                  <td className="p-4 font-black text-emerald-700">
                    {money(payment.amount)} ر.س
                  </td>

                  <td className="p-4">
                    {paymentMethodLabel(payment.payment_method)}
                  </td>

                  <td className="p-4">{payment.bank_name || "-"}</td>
                  <td className="p-4">{payment.reference_number || "-"}</td>

                  <td className="p-4">
                    <StatusBadge status={payment.payment_status} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Dialog({
  title,
  children,
  onClose,
}: {
  title: string;
  children: React.ReactNode;
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4">
      <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-[30px] bg-white shadow-2xl">
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white p-5">
          <h2 className="text-xl font-black text-[#0B2A4A]">{title}</h2>

          <button
            type="button"
            onClick={onClose}
            className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-xl font-black text-slate-700 hover:bg-slate-200"
          >
            ×
          </button>
        </div>

        <div className="p-5 sm:p-7">{children}</div>
      </div>
    </div>
  );
}

function Field({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-black text-slate-700">
        {label}
      </span>

      {children}
    </label>
  );
}

function DialogActions({
  saving,
  submitLabel,
  onCancel,
}: {
  saving: boolean;
  submitLabel: string;
  onCancel: () => void;
}) {
  return (
    <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
      <button
        type="button"
        onClick={onCancel}
        disabled={saving}
        className="rounded-2xl bg-slate-100 px-6 py-3 text-sm font-black text-slate-700 hover:bg-slate-200 disabled:opacity-50"
      >
        إلغاء
      </button>

      <button
        type="submit"
        disabled={saving}
        className="rounded-2xl bg-[#0B2A4A] px-7 py-3 text-sm font-black text-white hover:bg-[#123B64] disabled:opacity-50"
      >
        {saving ? "جاري الحفظ..." : submitLabel}
      </button>
    </div>
  );
}

function HeroStat({
  title,
  value,
}: {
  title: string;
  value: number;
}) {
  return (
    <div className="rounded-2xl bg-white/10 p-4">
      <div className="text-xs font-bold text-blue-100">{title}</div>

      <div className="mt-2 text-3xl font-black">
        {Number(value || 0).toLocaleString("ar-SA")}
      </div>
    </div>
  );
}

function HeroMoney({
  title,
  value,
}: {
  title: string;
  value: unknown;
}) {
  return (
    <div className="rounded-2xl bg-white/10 p-4">
      <div className="text-xs font-bold text-blue-100">{title}</div>

      <div className="mt-2 text-2xl font-black">{money(value)} ر.س</div>
    </div>
  );
}

function SmallStat({
  title,
  value,
}: {
  title: string;
  value: number;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="text-xs font-bold text-slate-500">{title}</div>

      <div className="mt-2 text-2xl font-black text-[#0B2A4A]">
        {Number(value || 0).toLocaleString("ar-SA")}
      </div>
    </div>
  );
}

function InvoicePreviewItem({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div>
      <div className="text-xs font-bold text-slate-500">{title}</div>
      <div className="mt-1 font-black text-[#0B2A4A]">{value}</div>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const normalized = String(status || "").toUpperCase();

  const className =
    normalized === "PAID" || normalized === "CONFIRMED"
      ? "bg-emerald-100 text-emerald-700"
      : normalized === "PARTIAL"
        ? "bg-blue-100 text-blue-700"
        : normalized === "UNPAID" || normalized === "FAILED"
          ? "bg-red-100 text-red-700"
          : normalized === "REFUNDED"
            ? "bg-violet-100 text-violet-700"
            : normalized === "CANCELLED"
              ? "bg-slate-200 text-slate-700"
              : "bg-amber-100 text-amber-700";

  const labels: Record<string, string> = {
    PAID: "مدفوع",
    PARTIAL: "مدفوع جزئيًا",
    UNPAID: "غير مدفوع",
    FAILED: "فاشل",
    CONFIRMED: "مؤكد",
    REFUNDED: "مسترد",
    CANCELLED: "ملغي",
    PENDING: "قيد الانتظار",
  };

  return (
    <span className={`rounded-full px-3 py-1.5 text-xs font-black ${className}`}>
      {labels[normalized] || normalized || "-"}
    </span>
  );
}

function LoadingRows({ columns }: { columns: number }) {
  return (
    <>
      {[1, 2, 3].map((row) => (
        <tr key={row} className="border-t border-slate-100">
          <td colSpan={columns} className="p-4">
            <div className="h-14 animate-pulse rounded-xl bg-slate-100" />
          </td>
        </tr>
      ))}
    </>
  );
}

function EmptyRow({
  columns,
  message,
}: {
  columns: number;
  message: string;
}) {
  return (
    <tr>
      <td colSpan={columns} className="p-12 text-center text-slate-500">
        {message}
      </td>
    </tr>
  );
}

function calculateInvoicePreview(form: ReturnType<typeof initialInvoiceForm>) {
  const subtotal = Number(form.subtotal || 0);
  const discount = Number(form.discount_amount || 0);
  const taxRate = Number(form.tax_rate || 0);

  const taxable = Math.max(subtotal - discount, 0);
  const tax = taxable * (taxRate / 100);
  const total = taxable + tax;

  return {
    taxable,
    tax,
    total,
  };
}

function firstValidationError(errors: unknown): string | null {
  if (!errors || typeof errors !== "object") {
    return null;
  }

  const values = Object.values(errors as Record<string, unknown>);

  for (const value of values) {
    if (Array.isArray(value) && value.length > 0) {
      return String(value[0]);
    }

    if (typeof value === "string") {
      return value;
    }
  }

  return null;
}

function money(value: unknown): string {
  return Number(value || 0).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

function formatDate(value: string | null | undefined): string {
  if (!value) {
    return "-";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("ar-SA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
}

function paymentMethodLabel(value: string): string {
  const labels: Record<string, string> = {
    CASH: "نقدي",
    BANK_TRANSFER: "تحويل بنكي",
    CARD: "بطاقة",
    ONLINE: "دفع إلكتروني",
    CHEQUE: "شيك",
  };

  return labels[String(value || "").toUpperCase()] || value || "-";
}
