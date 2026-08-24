"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";

import ERPPage from "@/components/erp/layout/ERPPage";
import ERPHeader from "@/components/erp/layout/ERPHeader";
import ERPToolbar from "@/components/erp/layout/ERPToolbar";
import ERPCard from "@/components/erp/cards/ERPCard";
import ERPStatCard from "@/components/erp/cards/ERPStatCard";
import ERPEmpty from "@/components/erp/cards/ERPEmpty";
import ERPButton from "@/components/erp/buttons/ERPButton";
import ERPInput from "@/components/erp/form/ERPInput";
import ERPSelect from "@/components/erp/form/ERPSelect";
import ERPMessage from "@/components/erp/dialog/ERPMessage";
import ERPConfirm from "@/components/erp/dialog/ERPConfirm";

type PayrollStatus = "DRAFT" | "APPROVED" | "PAID";

type PayrollRun = {
  id: number;
  company_id: number;
  branch_id?: number | null;
  salary_month: string;
  run_number?: string | null;
  status: PayrollStatus;
  total_amount: number | string;
  paid_amount: number | string;
  journal_entry_id?: number | null;
  approved_at?: string | null;
  paid_at?: string | null;
  notes?: string | null;
  created_at?: string | null;
};

type PayrollLine = {
  id: number;
  worker_id: number;
  worker_name?: string;
  salary_type: string;
  rate_amount: number | string;
  work_units: number | string;
  basic_amount: number | string;
  overtime_amount: number | string;
  allowance_amount: number | string;
  bonus_amount: number | string;
  commission_amount: number | string;
  loan_deduction: number | string;
  other_deduction: number | string;
  net_salary: number | string;
  payment_status: string;
  payment_method?: string | null;
};

type MessageState = {
  type: "success" | "error" | "info" | "warning";
  title: string;
  text: string;
} | null;

const emptyGenerateForm = {
  salary_month: new Date().toISOString().slice(0, 7),
  branch_id: "",
};

export default function PayrollPage() {
  const [runs, setRuns] = useState<PayrollRun[]>([]);
  const [selectedRun, setSelectedRun] = useState<PayrollRun | null>(null);
  const [selectedLines, setSelectedLines] = useState<PayrollLine[]>([]);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [monthFilter, setMonthFilter] = useState("");

  const [loading, setLoading] = useState(false);
  const [detailsLoading, setDetailsLoading] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);

  const [showGenerate, setShowGenerate] = useState(false);
  const [showDetails, setShowDetails] = useState(false);
  const [showPayment, setShowPayment] = useState(false);

  const [generateForm, setGenerateForm] = useState<any>(
    emptyGenerateForm
  );

  const [paymentMethod, setPaymentMethod] = useState("CASH");
  const [financialAccountId, setFinancialAccountId] = useState("");
  const [payrollMeta, setPayrollMeta] = useState<any>({ branches: [], financial_accounts: [], base_currency: "" });

  const [confirmState, setConfirmState] = useState<any>({
    open: false,
    type: "warning",
    action: null,
    title: "",
    text: "",
    confirmText: "تأكيد",
  });

  const [msg, setMsg] = useState<MessageState>(null);

  useEffect(() => {
    loadPayrollRuns();
    api.get("/payroll/meta").then((r) => setPayrollMeta(r?.data?.data || {})).catch(() => setPayrollMeta({ branches: [], financial_accounts: [], base_currency: "" }));
  }, []);

  async function loadPayrollRuns() {
    setLoading(true);

    try {
      const res = await api.get("/payroll");

      const payload = res?.data?.data;

      const records = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
        ? payload.data
        : [];

      setRuns(records);
    } catch (e: any) {
      showMessage(
        "error",
        "تعذر تحميل مسيرات الرواتب",
        apiError(
          e,
          "حدث خطأ أثناء تحميل بيانات مسيرات الرواتب."
        )
      );
    } finally {
      setLoading(false);
    }
  }

  async function openDetails(run: PayrollRun) {
    setSelectedRun(run);
    setSelectedLines([]);
    setShowDetails(true);
    setDetailsLoading(true);

    try {
      const res = await api.get(`/payroll/${run.id}`);

      setSelectedRun(res?.data?.run || run);
      setSelectedLines(res?.data?.lines || []);
    } catch (e: any) {
      showMessage(
        "error",
        "تعذر تحميل تفاصيل المسير",
        apiError(e, "حدث خطأ أثناء تحميل تفاصيل الرواتب.")
      );
    } finally {
      setDetailsLoading(false);
    }
  }

  async function generatePayroll() {
    if (!generateForm.salary_month) {
      showMessage(
        "warning",
        "الشهر مطلوب",
        "اختر شهر الرواتب قبل إنشاء المسير."
      );
      return;
    }

    const companyId = getCompanyId();

    if (!companyId) {
      showMessage(
        "error",
        "لم يتم تحديد الشركة",
        "سجّل الدخول وحدد الشركة الحالية ثم أعد المحاولة."
      );
      return;
    }

    setActionLoading(true);

    try {
      const salaryMonth = `${generateForm.salary_month}-01`;

      const res = await api.post("/payroll/generate", {
        company_id: companyId,
        branch_id: generateForm.branch_id || null,
        salary_month: salaryMonth,
      });

      const data = res?.data?.data;

      showMessage(
        "success",
        "تم إنشاء مسير الرواتب",
        `تم إنشاء المسير بنجاح لعدد ${
          data?.workers_count || 0
        } موظف، بإجمالي ${money(data?.total_amount)}.`
      );

      setShowGenerate(false);
      setGenerateForm(emptyGenerateForm);
      await loadPayrollRuns();
    } catch (e: any) {
      showMessage(
        "error",
        "فشل إنشاء مسير الرواتب",
        apiError(e, "تعذر إنشاء مسير الرواتب.")
      );
    } finally {
      setActionLoading(false);
    }
  }

  function requestApprove(run: PayrollRun) {
    setConfirmState({
      open: true,
      type: "warning",
      title: "اعتماد مسير الرواتب",
      text:
        `سيتم اعتماد المسير ${run.run_number || run.id} ` +
        `وإنشاء قيد محاسبي بقيمة ${money(
          run.total_amount
        )}. بعد الاعتماد لن يمكن إعادة إنشاء المسير للشهر نفسه.`,
      confirmText: "اعتماد وإنشاء القيد",
      action: () => approvePayroll(run),
    });
  }

  async function approvePayroll(run: PayrollRun) {
    closeConfirm();
    setActionLoading(true);

    try {
      const res = await api.post(`/payroll/${run.id}/approve`);

      showMessage(
        "success",
        "تم اعتماد المسير",
        `تم اعتماد مسير الرواتب وإنشاء القيد المحاسبي رقم ${
          res?.data?.data?.journal_entry_id || "-"
        }.`
      );

      await loadPayrollRuns();

      if (showDetails) {
        await openDetails({
          ...run,
          status: "APPROVED",
          journal_entry_id:
            res?.data?.data?.journal_entry_id || null,
        });
      }
    } catch (e: any) {
      showMessage(
        "error",
        "فشل اعتماد المسير",
        apiError(e, "تعذر اعتماد مسير الرواتب.")
      );
    } finally {
      setActionLoading(false);
    }
  }

  function openPaymentDialog(run: PayrollRun) {
    setSelectedRun(run);
    setPaymentMethod("CASH");
    const accounts = (payrollMeta.financial_accounts || []).filter((x: any) => Number(x.is_active) === 1 && (!x.branch_id || String(x.branch_id) === String(run.branch_id || "")));
    const cash = accounts.find((x: any) => x.account_type === "CASH" || x.account_type === "PETTY_CASH") || accounts[0];
    setFinancialAccountId(cash ? String(cash.id) : "");
    setShowPayment(true);
  }

  function requestPayment() {
    if (!selectedRun) return;

    setShowPayment(false);

    setConfirmState({
      open: true,
      type: "warning",
      title: "تأكيد صرف الرواتب",
      text:
        `سيتم صرف مبلغ ${money(
          selectedRun.total_amount
        )} للمسير ${selectedRun.run_number || selectedRun.id} ` +
        `عن طريق ${
          paymentMethod === "CASH" ? "الصندوق" : paymentMethod === "WALLET" ? "المحفظة" : "البنك"
        } (${(payrollMeta.financial_accounts || []).find((x: any) => String(x.id) === String(financialAccountId))?.account_name || "الحساب الافتراضي"})، وإنشاء قيد صرف نهائي.`,
      confirmText: "صرف الرواتب",
      action: payPayroll,
    });
  }

  async function payPayroll() {
    if (!selectedRun) return;

    closeConfirm();
    setActionLoading(true);

    try {
      const res = await api.post(
        `/payroll/${selectedRun.id}/pay`,
        {
          payment_method: paymentMethod,
          financial_account_id: financialAccountId ? Number(financialAccountId) : null,
        }
      );

      showMessage(
        "success",
        "تم صرف الرواتب",
        `تم صرف مبلغ ${money(
          res?.data?.data?.paid_amount
        )} وإنشاء قيد الصرف رقم ${
          res?.data?.data?.journal_entry_id || "-"
        }.`
      );

      setShowPayment(false);
      setShowDetails(false);
      setSelectedRun(null);
      setSelectedLines([]);

      await loadPayrollRuns();
    } catch (e: any) {
      showMessage(
        "error",
        "فشل صرف الرواتب",
        apiError(e, "تعذر صرف مسير الرواتب.")
      );
    } finally {
      setActionLoading(false);
    }
  }

  const filteredRuns = useMemo(() => {
    return runs.filter((run) => {
      const haystack =
        `${run.run_number || ""} ${run.salary_month || ""} ${
          run.status || ""
        }`.toLowerCase();

      const matchesSearch = haystack.includes(
        search.trim().toLowerCase()
      );

      const matchesStatus =
        !statusFilter || run.status === statusFilter;

      const matchesMonth =
        !monthFilter ||
        normalizeMonth(run.salary_month) === monthFilter;

      return matchesSearch && matchesStatus && matchesMonth;
    });
  }, [runs, search, statusFilter, monthFilter]);

  const totals = useMemo(() => {
    const total = runs.reduce(
      (sum, run) => sum + number(run.total_amount),
      0
    );

    const paid = runs.reduce(
      (sum, run) => sum + number(run.paid_amount),
      0
    );

    return {
      total,
      paid,
      remaining: Math.max(total - paid, 0),
      draft: runs.filter((run) => run.status === "DRAFT").length,
      approved: runs.filter(
        (run) => run.status === "APPROVED"
      ).length,
      paidRuns: runs.filter((run) => run.status === "PAID")
        .length,
    };
  }, [runs]);

  return (
    <ERPPage>
      <ERPMessage
        msg={msg}
        onClose={() => setMsg(null)}
      />

      <ERPHeader
        title="إدارة الرواتب"
        subtitle="إنشاء واعتماد وصرف مسيرات الموظفين وربطها بالقيود المحاسبية"
        actions={
          <div className="flex flex-wrap gap-2">
            <ERPButton
              onClick={() => setShowGenerate(true)}
              disabled={actionLoading}
            >
              + إنشاء مسير جديد
            </ERPButton>

            <ERPButton
              type="secondary"
              onClick={loadPayrollRuns}
              disabled={loading}
            >
              {loading ? "جاري التحديث..." : "تحديث"}
            </ERPButton>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <ERPStatCard
          title="عدد المسيرات"
          value={runs.length}
        />

        <ERPStatCard
          title="إجمالي الرواتب"
          value={money(totals.total)}
        />

        <ERPStatCard
          title="إجمالي المدفوع"
          value={money(totals.paid)}
          color="#059669"
        />

        <ERPStatCard
          title="المتبقي"
          value={money(totals.remaining)}
          color="#DC2626"
        />

        <ERPStatCard
          title="بانتظار الاعتماد"
          value={totals.draft}
          color="#F59E0B"
        />

        <ERPStatCard
          title="بانتظار الصرف"
          value={totals.approved}
          color="#2563EB"
        />
      </div>

      <ERPToolbar>
        <div className="grid w-full grid-cols-1 gap-3 md:grid-cols-3">
          <ERPInput
            label="بحث"
            value={search}
            onChange={setSearch}
            placeholder="رقم المسير أو الشهر أو الحالة..."
          />

          <ERPInput
            label="شهر الرواتب"
            type="month"
            value={monthFilter}
            onChange={setMonthFilter}
          />

          <ERPSelect
            label="حالة المسير"
            value={statusFilter}
            onChange={setStatusFilter}
            placeholder="كل الحالات"
            options={[
              { id: "DRAFT", name: "مسودة" },
              { id: "APPROVED", name: "معتمد" },
              { id: "PAID", name: "مدفوع" },
            ]}
          />
        </div>
      </ERPToolbar>

      <ERPCard
        title="مسيرات الرواتب"
        subtitle={`عدد النتائج: ${filteredRuns.length}`}
      >
        {loading ? (
          <LoadingBlock text="جاري تحميل مسيرات الرواتب..." />
        ) : filteredRuns.length === 0 ? (
          <ERPEmpty
            title="لا توجد مسيرات رواتب"
            text="أنشئ أول مسير رواتب أو غيّر خيارات البحث والتصفية."
            action={
              <ERPButton
                onClick={() => setShowGenerate(true)}
              >
                إنشاء مسير جديد
              </ERPButton>
            }
          />
        ) : (
          <>
            <div className="hidden overflow-x-auto lg:block">
              <table className="w-full min-w-[1100px] text-right">
                <thead className="bg-slate-100 text-slate-700">
                  <tr>
                    <th className="p-4">رقم المسير</th>
                    <th className="p-4">الشهر</th>
                    <th className="p-4">الإجمالي</th>
                    <th className="p-4">المدفوع</th>
                    <th className="p-4">المتبقي</th>
                    <th className="p-4">الحالة</th>
                    <th className="p-4">القيد</th>
                    <th className="p-4">الإجراءات</th>
                  </tr>
                </thead>

                <tbody>
                  {filteredRuns.map((run) => (
                    <tr
                      key={run.id}
                      className="border-t transition hover:bg-slate-50"
                    >
                      <td className="p-4 font-black text-[#0B2A4A]">
                        {run.run_number || `PAY-${run.id}`}
                      </td>

                      <td className="p-4 font-bold">
                        {formatMonth(run.salary_month)}
                      </td>

                      <td className="p-4 font-black">
                        {money(run.total_amount)}
                      </td>

                      <td className="p-4 font-bold text-emerald-700">
                        {money(run.paid_amount)}
                      </td>

                      <td className="p-4 font-bold text-rose-700">
                        {money(
                          number(run.total_amount) -
                            number(run.paid_amount)
                        )}
                      </td>

                      <td className="p-4">
                        <PayrollStatusBadge
                          status={run.status}
                        />
                      </td>

                      <td className="p-4">
                        {run.journal_entry_id ? (
                          <span className="rounded-xl bg-blue-50 px-3 py-2 text-sm font-black text-blue-700">
                            JE #{run.journal_entry_id}
                          </span>
                        ) : (
                          <span className="text-slate-400">
                            لم يُنشأ
                          </span>
                        )}
                      </td>

                      <td className="p-4">
                        <div className="flex flex-wrap gap-2">
                          <SmallButton
                            onClick={() => openDetails(run)}
                          >
                            التفاصيل
                          </SmallButton>

                          {run.status === "DRAFT" && (
                            <SmallButton
                              variant="success"
                              onClick={() =>
                                requestApprove(run)
                              }
                              disabled={actionLoading}
                            >
                              اعتماد
                            </SmallButton>
                          )}

                          {run.status === "APPROVED" && (
                            <SmallButton
                              variant="purple"
                              onClick={() =>
                                openPaymentDialog(run)
                              }
                              disabled={actionLoading}
                            >
                              صرف
                            </SmallButton>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="space-y-3 lg:hidden">
              {filteredRuns.map((run) => (
                <PayrollMobileCard
                  key={run.id}
                  run={run}
                  actionLoading={actionLoading}
                  onDetails={() => openDetails(run)}
                  onApprove={() => requestApprove(run)}
                  onPay={() => openPaymentDialog(run)}
                />
              ))}
            </div>
          </>
        )}
      </ERPCard>

      {showGenerate && (
        <GeneratePayrollDialog
          form={generateForm}
          setForm={setGenerateForm}
          branches={payrollMeta.branches || []}
          loading={actionLoading}
          onSave={generatePayroll}
          onClose={() => {
            if (!actionLoading) {
              setShowGenerate(false);
            }
          }}
        />
      )}

      {showDetails && (
        <PayrollDetailsDrawer
          run={selectedRun}
          lines={selectedLines}
          loading={detailsLoading}
          actionLoading={actionLoading}
          onClose={() => {
            setShowDetails(false);
            setSelectedRun(null);
            setSelectedLines([]);
          }}
          onApprove={() =>
            selectedRun && requestApprove(selectedRun)
          }
          onPay={() =>
            selectedRun &&
            openPaymentDialog(selectedRun)
          }
        />
      )}

      {showPayment && selectedRun && (
        <PaymentDialog
          run={selectedRun}
          paymentMethod={paymentMethod}
          setPaymentMethod={setPaymentMethod}
          financialAccountId={financialAccountId}
          setFinancialAccountId={setFinancialAccountId}
          accounts={(payrollMeta.financial_accounts || []).filter((x: any) => Number(x.is_active) === 1 && (!x.branch_id || String(x.branch_id) === String(selectedRun?.branch_id || "")))}
          loading={actionLoading}
          onConfirm={requestPayment}
          onClose={() => {
            if (!actionLoading) {
              setShowPayment(false);
            }
          }}
        />
      )}

      <ERPConfirm
        open={confirmState.open}
        title={confirmState.title}
        text={confirmState.text}
        confirmText={confirmState.confirmText}
        cancelText="إلغاء"
        type={confirmState.type}
        onConfirm={confirmState.action}
        onCancel={closeConfirm}
      />
    </ERPPage>
  );

  function closeConfirm() {
    setConfirmState((prev: any) => ({
      ...prev,
      open: false,
      action: null,
    }));
  }

  function showMessage(
    type: "success" | "error" | "info" | "warning",
    title: string,
    text: string
  ) {
    setMsg({ type, title, text });

    window.scrollTo({
      top: 0,
      behavior: "smooth",
    });
  }
}

function GeneratePayrollDialog({
  form,
  setForm,
  branches = [],
  loading,
  onSave,
  onClose,
}: any) {
  return (
    <div className="fixed inset-0 z-[900] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm">
      <div
        dir="rtl"
        className="w-full max-w-xl rounded-3xl bg-white shadow-2xl"
      >
        <div className="border-b p-5">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            إنشاء مسير رواتب
          </h2>

          <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
            سيجمع النظام الموظفين النشطين، ويحسب الرواتب
            والعمولات والسلف والخصومات للشهر المحدد.
          </p>
        </div>

        <div className="space-y-4 p-5">
          <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold leading-7 text-blue-900">
            لا يمكن إنشاء أكثر من مسير واحد للشركة في الشهر
            نفسه.
          </div>

          <ERPInput
            label="شهر الرواتب"
            type="month"
            value={form.salary_month}
            onChange={(value: any) =>
              setForm({
                ...form,
                salary_month: value,
              })
            }
          />

          <ERPSelect
            label="الفرع"
            value={form.branch_id}
            onChange={(value: any) => setForm({ ...form, branch_id: value || "" })}
            options={[{ id: "", name: "كل/الفرع الحالي حسب الصلاحية" }, ...branches.map((x: any) => ({ id: x.id, name: x.branch_name }))]}
          />
        </div>

        <div className="flex flex-col gap-3 border-t p-5 sm:flex-row">
          <ERPButton
            onClick={onSave}
            disabled={loading}
          >
            {loading
              ? "جاري إنشاء المسير..."
              : "إنشاء المسير"}
          </ERPButton>

          <ERPButton
            type="secondary"
            onClick={onClose}
            disabled={loading}
          >
            إلغاء
          </ERPButton>
        </div>
      </div>
    </div>
  );
}

function PaymentDialog({
  run,
  paymentMethod,
  setPaymentMethod,
  financialAccountId,
  setFinancialAccountId,
  accounts = [],
  loading,
  onConfirm,
  onClose,
}: any) {
  return (
    <div className="fixed inset-0 z-[910] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm">
      <div
        dir="rtl"
        className="w-full max-w-lg rounded-3xl bg-white shadow-2xl"
      >
        <div className="border-b p-5">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            صرف مسير الرواتب
          </h2>

          <p className="mt-2 text-sm font-semibold text-slate-500">
            {run.run_number || `PAY-${run.id}`}
          </p>
        </div>

        <div className="space-y-4 p-5">
          <div className="rounded-2xl border bg-slate-50 p-4">
            <div className="text-sm font-bold text-slate-500">
              مبلغ الصرف
            </div>

            <div className="mt-2 text-3xl font-black text-[#0B2A4A]">
              {money(run.total_amount)}
            </div>
          </div>

          <ERPSelect
            label="طريقة الدفع"
            value={paymentMethod}
            onChange={(value: any) => {
              setPaymentMethod(value);
              const wanted = ["BANK","BANK_TRANSFER","CARD"].includes(value) ? "BANK" : value === "WALLET" ? "WALLET" : "CASH";
              const hit = accounts.find((x: any) => x.account_type === wanted) || accounts[0];
              setFinancialAccountId(hit ? String(hit.id) : "");
            }}
            options={[
              { id: "CASH", name: "نقدًا من الصندوق" },
              { id: "BANK", name: "من حساب بنكي" },
              { id: "BANK_TRANSFER", name: "تحويل بنكي" },
              { id: "CARD", name: "بطاقة" },
              { id: "WALLET", name: "محفظة" },
            ]}
          />

          <ERPSelect
            label="الخزينة / البنك المستخدم"
            value={financialAccountId}
            onChange={setFinancialAccountId}
            options={[{ id: "", name: "استخدام الافتراضي للفرع" }, ...accounts.map((x: any) => ({ id: x.id, name: `${x.account_name} — ${x.branch_name || "مركزي"} — ${x.currency_code || ""}` }))]}
          />

          <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold leading-7 text-amber-900">
            بعد تأكيد الصرف سيُنشئ النظام قيدًا محاسبيًا
            ويحدّث جميع رواتب الموظفين إلى حالة مدفوعة.
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t p-5 sm:flex-row">
          <ERPButton
            type="success"
            onClick={onConfirm}
            disabled={loading}
          >
            متابعة الصرف
          </ERPButton>

          <ERPButton
            type="secondary"
            onClick={onClose}
            disabled={loading}
          >
            إلغاء
          </ERPButton>
        </div>
      </div>
    </div>
  );
}

function PayrollDetailsDrawer({
  run,
  lines,
  loading,
  actionLoading,
  onClose,
  onApprove,
  onPay,
}: any) {
  if (!run) return null;

  const totals = {
    basic: lines.reduce(
      (sum: number, line: PayrollLine) =>
        sum + number(line.basic_amount),
      0
    ),
    additions: lines.reduce(
      (sum: number, line: PayrollLine) =>
        sum +
        number(line.overtime_amount) +
        number(line.allowance_amount) +
        number(line.bonus_amount) +
        number(line.commission_amount),
      0
    ),
    deductions: lines.reduce(
      (sum: number, line: PayrollLine) =>
        sum +
        number(line.loan_deduction) +
        number(line.other_deduction),
      0
    ),
    net: lines.reduce(
      (sum: number, line: PayrollLine) =>
        sum + number(line.net_salary),
      0
    ),
  };

  return (
    <div className="fixed inset-0 z-[850] bg-slate-950/45 backdrop-blur-sm">
      <div
        dir="rtl"
        className="mr-auto flex h-full w-full max-w-6xl flex-col bg-white shadow-2xl"
      >
        <div className="flex flex-col gap-4 border-b p-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 className="text-2xl font-black text-[#0B2A4A]">
              تفاصيل مسير الرواتب
            </h2>

            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-500">
              <span>
                {run.run_number || `PAY-${run.id}`}
              </span>

              <span>•</span>

              <span>{formatMonth(run.salary_month)}</span>

              <PayrollStatusBadge status={run.status} />
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            {run.status === "DRAFT" && (
              <ERPButton
                type="success"
                onClick={onApprove}
                disabled={actionLoading}
              >
                اعتماد المسير
              </ERPButton>
            )}

            {run.status === "APPROVED" && (
              <ERPButton
                type="purple"
                onClick={onPay}
                disabled={actionLoading}
              >
                صرف الرواتب
              </ERPButton>
            )}

            <ERPButton
              type="secondary"
              onClick={onClose}
            >
              إغلاق
            </ERPButton>
          </div>
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto p-5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <ERPStatCard
              title="الراتب الأساسي"
              value={money(totals.basic)}
            />

            <ERPStatCard
              title="الإضافات"
              value={money(totals.additions)}
              color="#2563EB"
            />

            <ERPStatCard
              title="الخصومات"
              value={money(totals.deductions)}
              color="#DC2626"
            />

            <ERPStatCard
              title="صافي المسير"
              value={money(totals.net)}
              color="#059669"
            />
          </div>

          {run.journal_entry_id && (
            <div className="rounded-3xl border border-blue-200 bg-blue-50 p-4 text-blue-900">
              <div className="font-black">
                القيد المحاسبي
              </div>

              <div className="mt-1 text-sm font-semibold">
                تم إنشاء القيد رقم JE #{run.journal_entry_id}
              </div>
            </div>
          )}

          <ERPCard
            title="تفاصيل رواتب الموظفين"
            subtitle={`عدد الموظفين: ${lines.length}`}
          >
            {loading ? (
              <LoadingBlock text="جاري تحميل تفاصيل الموظفين..." />
            ) : lines.length === 0 ? (
              <ERPEmpty
                title="لا توجد تفاصيل"
                text="لم يعثر النظام على تفاصيل مرتبطة بهذا المسير."
              />
            ) : (
              <>
                <div className="hidden overflow-x-auto lg:block">
                  <table className="w-full min-w-[1400px] text-right">
                    <thead className="bg-slate-100">
                      <tr>
                        <th className="p-3">الموظف</th>
                        <th className="p-3">الأساسي</th>
                        <th className="p-3">الإضافي</th>
                        <th className="p-3">البدلات</th>
                        <th className="p-3">المكافأة</th>
                        <th className="p-3">العمولة</th>
                        <th className="p-3">السلف</th>
                        <th className="p-3">خصومات أخرى</th>
                        <th className="p-3">الصافي</th>
                        <th className="p-3">الدفع</th>
                         <th className="p-3">كشف الراتب</th>
                      </tr>
                    </thead>

                    <tbody>
                      {lines.map((line: PayrollLine) => (
                        <tr
                          key={line.id}
                          className="border-t hover:bg-slate-50"
                        >
                          <td className="p-3 font-black text-[#0B2A4A]">
                            {line.worker_name ||
                              `موظف #${line.worker_id}`}
                          </td>

                          <td className="p-3">
                            {money(line.basic_amount)}
                          </td>

                          <td className="p-3">
                            {money(line.overtime_amount)}
                          </td>

                          <td className="p-3">
                            {money(line.allowance_amount)}
                          </td>

                          <td className="p-3">
                            {money(line.bonus_amount)}
                          </td>

                          <td className="p-3">
                            {money(line.commission_amount)}
                          </td>

                          <td className="p-3 text-rose-700">
                            {money(line.loan_deduction)}
                          </td>

                          <td className="p-3 text-rose-700">
                            {money(line.other_deduction)}
                          </td>

                          <td className="p-3 font-black text-emerald-700">
                            {money(line.net_salary)}
                          </td>

                           <td className="p-3">
    <PaymentStatusBadge
        status={line.payment_status}
    />
</td>

         <td className="p-3">
            <SmallButton
                 variant="primary"
                 onClick={() =>
                        window.open(
            `/payroll/${run.id}/salary-slip/${line.worker_id}`,
                "_blank"
                       )
                     }
                  >
             كشف الراتب
              </SmallButton>
         </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="space-y-3 lg:hidden">
                  {lines.map((line: PayrollLine) => (
                    <div
                      key={line.id}
                      className="rounded-3xl border bg-slate-50 p-4"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <div className="font-black text-[#0B2A4A]">
                            {line.worker_name ||
                              `موظف #${line.worker_id}`}
                          </div>

                          <div className="mt-1 text-sm text-slate-500">
                            صافي الراتب
                          </div>
                        </div>

                        <div className="text-xl font-black text-emerald-700">
                          {money(line.net_salary)}
                        </div>
                      </div>

                      <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
                        <MiniValue
                          title="الأساسي"
                          value={line.basic_amount}
                        />

                        <MiniValue
                          title="الإضافي"
                          value={line.overtime_amount}
                        />

                        <MiniValue
                          title="العمولة"
                          value={line.commission_amount}
                        />

                        <MiniValue
                          title="السلف"
                          value={line.loan_deduction}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              </>
            )}
          </ERPCard>
        </div>
      </div>
    </div>
  );
}

function PayrollMobileCard({
  run,
  actionLoading,
  onDetails,
  onApprove,
  onPay,
}: any) {
  const remaining =
    number(run.total_amount) - number(run.paid_amount);

  return (
    <div className="rounded-3xl border bg-slate-50 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="font-black text-[#0B2A4A]">
            {run.run_number || `PAY-${run.id}`}
          </div>

          <div className="mt-1 text-sm font-semibold text-slate-500">
            {formatMonth(run.salary_month)}
          </div>
        </div>

        <PayrollStatusBadge status={run.status} />
      </div>

      <div className="mt-4 grid grid-cols-2 gap-2">
        <MiniValue
          title="الإجمالي"
          value={run.total_amount}
        />

        <MiniValue
          title="المدفوع"
          value={run.paid_amount}
        />

        <MiniValue
          title="المتبقي"
          value={remaining}
        />

        <MiniValue
          title="القيد"
          value={
            run.journal_entry_id
              ? `#${run.journal_entry_id}`
              : "-"
          }
          formatMoney={false}
        />
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        <SmallButton onClick={onDetails}>
          التفاصيل
        </SmallButton>

        {run.status === "DRAFT" && (
          <SmallButton
            variant="success"
            onClick={onApprove}
            disabled={actionLoading}
          >
            اعتماد
          </SmallButton>
        )}

        {run.status === "APPROVED" && (
          <SmallButton
            variant="purple"
            onClick={onPay}
            disabled={actionLoading}
          >
            صرف
          </SmallButton>
        )}
      </div>
    </div>
  );
}

function MiniValue({
  title,
  value,
  formatMoney = true,
}: any) {
  return (
    <div className="rounded-2xl border bg-white p-3">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-1 font-black text-slate-800">
        {formatMoney ? money(value) : value}
      </div>
    </div>
  );
}

function PayrollStatusBadge({
  status,
}: {
  status: PayrollStatus;
}) {
  const config: Record<
    PayrollStatus,
    { label: string; className: string }
  > = {
    DRAFT: {
      label: "مسودة",
      className: "bg-amber-100 text-amber-800",
    },
    APPROVED: {
      label: "معتمد",
      className: "bg-blue-100 text-blue-800",
    },
    PAID: {
      label: "مدفوع",
      className: "bg-emerald-100 text-emerald-800",
    },
  };

  const current = config[status] || config.DRAFT;

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${current.className}`}
    >
      {current.label}
    </span>
  );
}

function PaymentStatusBadge({ status }: any) {
  const paid = status === "PAID";

  return (
    <span
      className={`rounded-full px-3 py-1 text-xs font-black ${
        paid
          ? "bg-emerald-100 text-emerald-800"
          : "bg-amber-100 text-amber-800"
      }`}
    >
      {paid ? "مدفوع" : "غير مدفوع"}
    </span>
  );
}

function SmallButton({
  children,
  onClick,
  variant = "primary",
  disabled = false,
}: any) {
  const styles: Record<string, string> = {
    primary:
      "bg-[#0B2A4A] text-white hover:bg-[#123D68]",
    success:
      "bg-emerald-600 text-white hover:bg-emerald-700",
    purple:
      "bg-purple-700 text-white hover:bg-purple-800",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`rounded-xl px-3 py-2 text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]}`}
    >
      {children}
    </button>
  );
}

function LoadingBlock({ text }: { text: string }) {
  return (
    <div className="flex min-h-48 flex-col items-center justify-center gap-3 rounded-3xl border border-dashed bg-slate-50">
      <div className="h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />

      <div className="font-bold text-slate-500">
        {text}
      </div>
    </div>
  );
}

function getCompanyId(): number | null {
  if (typeof window === "undefined") return null;

  const possibleValues = [
    localStorage.getItem("company_id"),
    localStorage.getItem("companyId"),
    localStorage.getItem("current_company_id"),
    sessionStorage.getItem("company_id"),
  ];

  for (const value of possibleValues) {
    const parsed = Number(value);

    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }

  /*
   * بيئة التطوير الحالية تستخدم الشركة رقم 4.
   * احذف هذا البديل لاحقًا بعد توحيد بيانات الجلسة.
   */
  return 4;
}

function apiError(error: any, fallback: string): string {
  const response = error?.response?.data;

  if (response?.message) {
    return response.message;
  }

  if (response?.errors) {
    const firstError = Object.values(response.errors)
      .flat()
      .find(Boolean);

    if (firstError) {
      return String(firstError);
    }
  }

  return fallback;
}

function number(value: any): number {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function money(value: any): string {
  return number(value).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

function normalizeMonth(value: any): string {
  if (!value) return "";
  return String(value).slice(0, 7);
}

function formatMonth(value: any): string {
  if (!value) return "-";

  const date = new Date(`${normalizeMonth(value)}-01T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return normalizeMonth(value);
  }

  return date.toLocaleDateString("ar-SA", {
    year: "numeric",
    month: "long",
  });
}