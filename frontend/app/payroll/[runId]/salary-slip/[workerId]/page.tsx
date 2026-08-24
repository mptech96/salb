"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import api from "../../../../api";

type SalarySlipData = {
  company?: any;
  salary?: any;
  summary?: {
    total_additions: number;
    total_deductions: number;
    net_salary: number;
  };
};

export default function SalarySlipPage() {
  const params = useParams();

  const runId = params?.runId;
  const workerId = params?.workerId;

  const [data, setData] = useState<SalarySlipData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!runId || !workerId) return;

    loadSalarySlip();
  }, [runId, workerId]);

  async function loadSalarySlip() {
    setLoading(true);
    setError("");

    try {
      const res = await api.get(
        `/payroll/${runId}/salary-slip/${workerId}`
      );

      setData(res?.data?.data || null);
    } catch (e: any) {
      setError(
        e?.response?.data?.message ||
          "تعذر تحميل كشف الراتب."
      );
    } finally {
      setLoading(false);
    }
  }

  if (loading) {
    return (
      <main
        dir="rtl"
        className="flex min-h-screen items-center justify-center bg-slate-100 p-4"
      >
        <div className="rounded-3xl bg-white px-8 py-10 text-center shadow-xl">
          <div className="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />
          <div className="mt-4 font-bold text-slate-600">
            جاري تحميل كشف الراتب...
          </div>
        </div>
      </main>
    );
  }

  if (error || !data?.salary) {
    return (
      <main
        dir="rtl"
        className="flex min-h-screen items-center justify-center bg-slate-100 p-4"
      >
        <div className="w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-8 text-center shadow-xl">
          <div className="text-2xl font-black text-rose-700">
            تعذر عرض كشف الراتب
          </div>

          <div className="mt-3 text-sm font-semibold leading-7 text-slate-600">
            {error || "كشف الراتب غير موجود."}
          </div>

          <button
            type="button"
            onClick={() => window.close()}
            className="mt-6 rounded-2xl bg-slate-200 px-5 py-3 font-black text-slate-700"
          >
            إغلاق
          </button>
        </div>
      </main>
    );
  }

  const salary = data.salary;
  const company = data.company || {};
  const summary = data.summary || {
    total_additions: 0,
    total_deductions: 0,
    net_salary: 0,
  };

  return (
    <main
      dir="rtl"
      className="min-h-screen bg-slate-100 p-4 sm:p-6"
    >
      <div className="mx-auto max-w-5xl">
        <div className="mb-4 flex flex-col gap-3 print:hidden sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={() => window.print()}
            className="rounded-2xl bg-[#0B2A4A] px-5 py-3 font-black text-white hover:bg-[#123D68]"
          >
            طباعة كشف الراتب
          </button>

          <button
            type="button"
            onClick={() => window.close()}
            className="rounded-2xl bg-slate-200 px-5 py-3 font-black text-slate-700 hover:bg-slate-300"
          >
            إغلاق
          </button>
        </div>

        <section className="overflow-hidden rounded-3xl bg-white shadow-xl print:rounded-none print:shadow-none">
          <header className="bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white sm:p-8">
            <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <div className="text-sm font-bold text-blue-100">
                  SULB ERP
                </div>

                <h1 className="mt-2 text-3xl font-black">
                  كشف راتب موظف
                </h1>

                <div className="mt-2 text-sm font-semibold text-blue-100">
                  {salary.run_number || `PAY-${runId}`}
                </div>
              </div>

              <div className="rounded-2xl bg-white/10 p-4 text-sm leading-7">
                <div className="font-black">
                  {company.company_name ||
                    company.name ||
                    "شركة صلب"}
                </div>

                <div>
                  الشهر: {formatMonth(salary.salary_month)}
                </div>

                <div>
                  الفرع: {salary.branch_name || "الفرع الرئيسي"}
                </div>
              </div>
            </div>
          </header>

          <div className="space-y-6 p-5 sm:p-8">
            <section className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <InfoCard
                title="بيانات الموظف"
                items={[
                  ["اسم الموظف", salary.worker_name || "-"],
                  ["الرقم الوظيفي", salary.employee_no || "-"],
                  ["المسمى الوظيفي", salary.job_title || "-"],
                  ["القسم", salary.department || "-"],
                ]}
              />

              <InfoCard
                title="البيانات النظامية والبنكية"
                items={[
                  [
                    "رقم الهوية / الإقامة",
                    salary.national_id ||
                      salary.iqama_number ||
                      "-",
                  ],
                  ["الجوال", salary.phone || "-"],
                  ["البنك", salary.bank_name || "-"],
                  ["الآيبان", salary.iban || "-"],
                ]}
              />
            </section>

            <section className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <SummaryCard
                title="الراتب الأساسي"
                value={salary.basic_amount}
              />

              <SummaryCard
                title="إجمالي الإضافات"
                value={summary.total_additions}
                className="text-blue-700"
              />

              <SummaryCard
                title="إجمالي الخصومات"
                value={summary.total_deductions}
                className="text-rose-700"
              />
            </section>

            <section className="overflow-hidden rounded-3xl border border-slate-200">
              <div className="border-b bg-slate-50 px-5 py-4">
                <h2 className="text-lg font-black text-slate-800">
                  تفاصيل الراتب
                </h2>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-right">
                  <thead className="bg-slate-100">
                    <tr>
                      <th className="p-4">البند</th>
                      <th className="p-4">استحقاق</th>
                      <th className="p-4">خصم</th>
                    </tr>
                  </thead>

                  <tbody>
                    <SalaryRow
                      title="الراتب الأساسي"
                      earning={salary.basic_amount}
                    />

                    <SalaryRow
                      title="العمل الإضافي"
                      earning={salary.overtime_amount}
                    />

                    <SalaryRow
                      title="البدلات"
                      earning={salary.allowance_amount}
                    />

                    <SalaryRow
                      title="المكافآت"
                      earning={salary.bonus_amount}
                    />

                    <SalaryRow
                      title="العمولات"
                      earning={salary.commission_amount}
                    />

                    <SalaryRow
                      title="خصم السلف"
                      deduction={salary.loan_deduction}
                    />

                    <SalaryRow
                      title="خصومات أخرى"
                      deduction={salary.other_deduction}
                    />
                  </tbody>

                  <tfoot className="bg-slate-50 font-black">
                    <tr className="border-t">
                      <td className="p-4">
                        الإجمالي
                      </td>

                      <td className="p-4 text-emerald-700">
                        {money(
                          number(salary.basic_amount) +
                            number(summary.total_additions)
                        )}
                      </td>

                      <td className="p-4 text-rose-700">
                        {money(summary.total_deductions)}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </section>

            <section className="rounded-3xl border border-emerald-200 bg-emerald-50 p-6">
              <div className="text-sm font-bold text-emerald-700">
                صافي الراتب المستحق
              </div>

              <div className="mt-3 text-4xl font-black text-emerald-800">
                {money(summary.net_salary)}
              </div>
            </section>

            <section className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <StatusCard
                title="حالة المسير"
                value={translateRunStatus(
                  salary.run_status
                )}
              />

              <StatusCard
                title="حالة الدفع"
                value={translatePaymentStatus(
                  salary.payment_status
                )}
              />

              <StatusCard
                title="طريقة الدفع"
                value={translatePaymentMethod(
                  salary.payment_method
                )}
              />
            </section>

            {salary.run_journal_entry_id && (
              <section className="rounded-3xl border border-blue-200 bg-blue-50 p-5 text-blue-900">
                <div className="font-black">
                  القيد المحاسبي
                </div>

                <div className="mt-2 text-sm font-semibold">
                  تم ربط كشف الراتب بالقيد رقم JE #
                  {salary.run_journal_entry_id}
                </div>
              </section>
            )}

            <footer className="border-t pt-5 text-center text-xs font-semibold leading-6 text-slate-500">
              تم إصدار هذا الكشف إلكترونيًا من نظام SULB ERP.
              لا يحتاج إلى توقيع ما لم تتطلب سياسة الشركة خلاف ذلك.
            </footer>
          </div>
        </section>
      </div>

      <style jsx global>{`
        @media print {
          @page {
            size: A4;
            margin: 10mm;
          }

          body {
            background: white !important;
          }

          main {
            padding: 0 !important;
            background: white !important;
          }
        }
      `}</style>
    </main>
  );
}

function InfoCard({
  title,
  items,
}: {
  title: string;
  items: Array<[string, any]>;
}) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
      <h2 className="text-lg font-black text-[#0B2A4A]">
        {title}
      </h2>

      <div className="mt-4 space-y-3">
        {items.map(([label, value]) => (
          <div
            key={label}
            className="flex items-start justify-between gap-4 border-b border-slate-200 pb-2 last:border-0"
          >
            <span className="text-sm font-bold text-slate-500">
              {label}
            </span>

            <span className="text-left text-sm font-black text-slate-800">
              {value}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

function SummaryCard({
  title,
  value,
  className = "text-[#0B2A4A]",
}: {
  title: string;
  value: any;
  className?: string;
}) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-sm font-bold text-slate-500">
        {title}
      </div>

      <div className={`mt-3 text-3xl font-black ${className}`}>
        {money(value)}
      </div>
    </div>
  );
}

function SalaryRow({
  title,
  earning = 0,
  deduction = 0,
}: {
  title: string;
  earning?: any;
  deduction?: any;
}) {
  return (
    <tr className="border-t hover:bg-slate-50">
      <td className="p-4 font-bold text-slate-800">
        {title}
      </td>

      <td className="p-4 font-black text-emerald-700">
        {number(earning) > 0 ? money(earning) : "-"}
      </td>

      <td className="p-4 font-black text-rose-700">
        {number(deduction) > 0
          ? money(deduction)
          : "-"}
      </td>
    </tr>
  );
}

function StatusCard({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-slate-50 p-4">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-2 font-black text-slate-800">
        {value}
      </div>
    </div>
  );
}

function money(value: any): string {
  return number(value).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

function number(value: any): number {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function formatMonth(value: any): string {
  if (!value) return "-";

  const month = String(value).slice(0, 7);
  const date = new Date(`${month}-01T00:00:00`);

  if (Number.isNaN(date.getTime())) {
    return month;
  }

  return date.toLocaleDateString("ar-SA", {
    year: "numeric",
    month: "long",
  });
}

function translateRunStatus(value: string): string {
  const map: Record<string, string> = {
    DRAFT: "مسودة",
    APPROVED: "معتمد",
    PAID: "مدفوع",
  };

  return map[value] || value || "-";
}

function translatePaymentStatus(value: string): string {
  const map: Record<string, string> = {
    UNPAID: "غير مدفوع",
    PAID: "مدفوع",
  };

  return map[value] || value || "-";
}

function translatePaymentMethod(value: string): string {
  const map: Record<string, string> = {
    CASH: "نقدًا",
    BANK: "تحويل بنكي",
  };

  return map[value] || value || "-";
}