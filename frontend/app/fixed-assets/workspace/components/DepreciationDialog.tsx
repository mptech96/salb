"use client";

import { useEffect, useState } from "react";

import {
  runDepreciation,
  type DepreciationRunResult,
} from "../services/fixedAssets";

import {
  firstValidationMessage,
  validateDepreciationRun,
} from "../validation";

type Props = {
  open: boolean;
  defaultBranchId?: number | string | null;
  onClose: () => void;
  onCompleted?: (
    result: DepreciationRunResult
  ) => void;
};

type MessageState = {
  type: "success" | "error" | "warning" | "info";
  text: string;
} | null;

export default function DepreciationDialog({
  open,
  defaultBranchId = null,
  onClose,
  onCompleted,
}: Props) {
  const [month, setMonth] = useState(
    currentMonth()
  );

  const [branchId, setBranchId] = useState<
    number | string
  >(defaultBranchId || "");

  const [loading, setLoading] =
    useState(false);
  const [reviewing, setReviewing] =
    useState(false);

  const [result, setResult] =
    useState<DepreciationRunResult | null>(
      null
    );

  const [message, setMessage] =
    useState<MessageState>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setMonth(currentMonth());
    setBranchId(defaultBranchId || "");
    setLoading(false);
    setReviewing(false);
    setResult(null);
    setMessage(null);
  }, [open, defaultBranchId]);

  if (!open) {
    return null;
  }

  async function handleRun() {
    const payload = {
      depreciation_month:
        month.length === 7
          ? `${month}-01`
          : month,

      branch_id:
        branchId === ""
          ? null
          : Number(branchId),
    };

    const validation =
      validateDepreciationRun(payload);

    if (!validation.valid) {
      setMessage({
        type: "warning",
        text: firstValidationMessage(
          validation
        ),
      });

      return;
    }

    setLoading(true);
    setResult(null);
    setMessage(null);

    try {
      const response =
        await runDepreciation(payload);

      setResult(response);

      setMessage({
        type:
          response.failed_count > 0
            ? "warning"
            : "success",

        text:
          response.failed_count > 0
            ? `تم ترحيل إهلاك ${response.posted_count} أصل، وتعذر ترحيل ${response.failed_count} أصل.`
            : `تم ترحيل إهلاك ${response.posted_count} أصل بنجاح.`,
      });

      onCompleted?.(response);
    } catch (error: unknown) {
      setMessage({
        type: "error",
        text: getApiError(
          error,
          "تعذر تشغيل الإهلاك للشهر المحدد."
        ),
      });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="fixed inset-0 z-[980] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
      <div
        dir="rtl"
        className="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
      >
        <header className="border-b border-slate-200 px-5 py-4 sm:px-7">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            تشغيل الإهلاك الشهري
          </h2>

          <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
            سيقوم النظام بفحص جميع الأصول
            المؤهلة، واحتساب الإهلاك، وإنشاء
            القيود المحاسبية، وتحديث القيم
            الدفترية.
          </p>
        </header>

        <div className="flex-1 overflow-y-auto p-5 sm:p-7">
          <div className="space-y-5">
            {message && (
              <MessageBox
                type={message.type}
                text={message.text}
                onClose={() =>
                  setMessage(null)
                }
              />
            )}

            <section className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="شهر الإهلاك">
                  <input
                    type="month"
                    value={month}
                    onChange={(event) => {
                      setMonth(event.target.value);
                      setReviewing(false);
                    }}
                    className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100"
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم الفرع">
                  <input
                    type="number"
                    value={branchId}
                    onChange={(event) => {
                      setBranchId(event.target.value);
                      setReviewing(false);
                    }}
                    placeholder="اتركه فارغًا لكل الفروع"
                    className="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100"
                  />
                </FieldWrapper>
              </div>

              <div className="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold leading-7 text-blue-900">
                لن يتم تكرار إهلاك الأصل لنفس
                الشهر. الأصول المباعة أو المشطوبة
                أو الواقعة تحت الصيانة لن تدخل في
                التشغيل.
              </div>
            </section>

            {reviewing && !result && (
              <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4" role="alert">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <span className="inline-flex rounded-full bg-amber-200 px-2.5 py-1 text-[11px] font-black text-amber-950">مراجعة قبل الترحيل</span>
                    <h3 className="mt-2 font-black text-amber-950">الفترة: {month || "غير محددة"}</h3>
                    <p className="mt-1 text-sm font-semibold leading-6 text-amber-900">
                      سيحدد الخادم الأصول المؤهلة ويحتسب الإهلاك وينشئ الأثر المحاسبي. هذه مراجعة للسياق وليست نتيجة PREVIEW محاسبية.
                    </p>
                  </div>
                </div>
              </section>
            )}

            {result && (
              <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 className="text-lg font-black text-[#0B2A4A]">
                  نتيجة التشغيل
                </h3>

                <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                  <ResultCard
                    title="الأصول المؤهلة"
                    value={
                      result.eligible_assets_count
                    }
                  />

                  <ResultCard
                    title="تم ترحيلها"
                    value={
                      result.posted_count
                    }
                  />

                  <ResultCard
                    title="تعذر ترحيلها"
                    value={
                      result.failed_count
                    }
                  />

                  <ResultCard
                    title="إجمالي الإهلاك"
                    value={formatMoney(
                      result.total_depreciation
                    )}
                  />
                </div>

                {result.errors.length > 0 && (
                  <div className="mt-5">
                    <h4 className="font-black text-rose-800">
                      تفاصيل الأخطاء
                    </h4>

                    <div className="mt-3 space-y-2">
                      {result.errors.map(
                        (item, index) => (
                          <div
                            key={`${item.asset_id}-${index}`}
                            className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900"
                          >
                            الأصل رقم{" "}
                            {item.asset_id}:{" "}
                            {item.message}
                          </div>
                        )
                      )}
                    </div>
                  </div>
                )}
              </section>
            )}
          </div>
        </div>

        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <button
            type="button"
            onClick={() => reviewing ? void handleRun() : setReviewing(true)}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#0B2A4A] px-6 text-sm font-black text-white transition hover:bg-[#123D68] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {loading
              ? "جاري تشغيل الإهلاك..."
              : reviewing
                ? "تأكيد التشغيل والترحيل"
                : "مراجعة التشغيل"}
          </button>

          <button
            type="button"
            onClick={onClose}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 text-sm font-black text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
          >
            إغلاق
          </button>
        </footer>
      </div>
    </div>
  );
}

function FieldWrapper({
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

function ResultCard({
  title,
  value,
}: {
  title: string;
  value: number | string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center">
      <div className="text-xl font-black text-[#0B2A4A]">
        {value}
      </div>

      <div className="mt-1 text-xs font-bold text-slate-500">
        {title}
      </div>
    </div>
  );
}

function MessageBox({
  type,
  text,
  onClose,
}: {
  type: "success" | "error" | "warning" | "info";
  text: string;
  onClose: () => void;
}) {
  const classes = {
    success:
      "border-emerald-200 bg-emerald-50 text-emerald-900",
    error:
      "border-rose-200 bg-rose-50 text-rose-900",
    warning:
      "border-amber-200 bg-amber-50 text-amber-900",
    info:
      "border-blue-200 bg-blue-50 text-blue-900",
  };

  return (
    <div
      className={`flex items-start justify-between gap-4 rounded-2xl border p-4 font-bold ${classes[type]}`}
    >
      <div>{text}</div>

      <button
        type="button"
        onClick={onClose}
        className="font-black"
        aria-label="إغلاق الرسالة"
      >
        ×
      </button>
    </div>
  );
}

function currentMonth(): string {
  return new Date()
    .toISOString()
    .slice(0, 7);
}

function formatMoney(
  value: number | string
): string {
  const parsed = Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed.toLocaleString("ar-SA", {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
      })
    : "0.000";
}

function getApiError(
  error: unknown,
  fallback: string
): string {
  if (
    error &&
    typeof error === "object" &&
    "response" in error
  ) {
    const response = (
      error as {
        response?: {
          data?: {
            message?: unknown;
          };
        };
      }
    ).response;

    if (
      typeof response?.data?.message ===
      "string"
    ) {
      return response.data.message;
    }
  }

  return fallback;
}
