"use client";

import {
  useEffect,
  useState,
  type ReactNode,
} from "react";

import type { FixedAsset } from "../types";

import {
  disposeAsset,
  type DisposeAssetPayload,
} from "../services/fixedAssets";

import {
  firstValidationMessage,
  validateDisposal,
} from "../validation";

/*
|--------------------------------------------------------------------------
| خصائص النافذة
|--------------------------------------------------------------------------
*/

type Props = {
  open: boolean;
  asset: FixedAsset | null;
  onClose: () => void;
  onCompleted?: () => void;
};

/*
|--------------------------------------------------------------------------
| نوع الرسالة
|--------------------------------------------------------------------------
*/

type MessageState = {
  type:
    | "success"
    | "error"
    | "warning"
    | "info";

  text: string;
} | null;

/*
|--------------------------------------------------------------------------
| نافذة شطب الأصل
|--------------------------------------------------------------------------
*/

export default function DisposeDialog({
  open,
  asset,
  onClose,
  onCompleted,
}: Props) {
  /*
  |--------------------------------------------------------------------------
  | حقول عملية الشطب
  |--------------------------------------------------------------------------
  */

  const [
    disposalDate,
    setDisposalDate,
  ] = useState(today());

  const [
    assetAccountId,
    setAssetAccountId,
  ] = useState<number | string>("");

  const [
    accumulatedAccountId,
    setAccumulatedAccountId,
  ] = useState<number | string>("");

  const [
    disposalLossAccountId,
    setDisposalLossAccountId,
  ] = useState<number | string>("");

  const [
    referenceNo,
    setReferenceNo,
  ] = useState("");

  const [notes, setNotes] =
    useState("");

  /*
  |--------------------------------------------------------------------------
  | حالات التنفيذ
  |--------------------------------------------------------------------------
  */

  const [loading, setLoading] =
    useState(false);

  const [message, setMessage] =
    useState<MessageState>(null);

  /*
  |--------------------------------------------------------------------------
  | إعادة ضبط البيانات عند فتح النافذة
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    if (!open) {
      return;
    }

    setDisposalDate(today());
    setAssetAccountId("");
    setAccumulatedAccountId("");
    setDisposalLossAccountId("");
    setReferenceNo("");
    setNotes("");
    setLoading(false);
    setMessage(null);
  }, [open, asset]);

  /*
  |--------------------------------------------------------------------------
  | عدم عرض النافذة
  |--------------------------------------------------------------------------
  */

  if (!open || !asset) {
    return null;
  }

  /*
  |--------------------------------------------------------------------------
  | تنفيذ عملية الشطب
  |--------------------------------------------------------------------------
  */

  async function handleDisposeAsset() {
    if (!asset) {
      setMessage({
        type: "error",
        text: "لم يتم تحديد الأصل المطلوب شطبه.",
      });

      return;
    }

    const payload: DisposeAssetPayload = {
      disposal_date: disposalDate,

      asset_account_id:
        assetAccountId === ""
          ? null
          : Number(assetAccountId),

      accumulated_account_id:
        accumulatedAccountId === ""
          ? null
          : Number(
              accumulatedAccountId
            ),

      disposal_loss_account_id:
        disposalLossAccountId === ""
          ? null
          : Number(
              disposalLossAccountId
            ),

      reference_no:
        referenceNo.trim() || null,

      notes:
        notes.trim() || null,
    };

    const validation =
      validateDisposal(payload);

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
    setMessage(null);

    try {
      await disposeAsset(
        asset.id,
        payload
      );

      setMessage({
        type: "success",
        text: "تم شطب الأصل بنجاح.",
      });

      onCompleted?.();

      window.setTimeout(() => {
        onClose();
      }, 800);
    } catch (error: unknown) {
      setMessage({
        type: "error",
        text: getApiError(
          error,
          "تعذر تنفيذ عملية شطب الأصل."
        ),
      });
    } finally {
      setLoading(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | واجهة النافذة
  |--------------------------------------------------------------------------
  */

  return (
    <div className="fixed inset-0 z-[995] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
      <div
        dir="rtl"
        className="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
      >
        {/*
        |--------------------------------------------------------------------------
        | رأس النافذة
        |--------------------------------------------------------------------------
        */}

        <header className="border-b border-slate-200 px-5 py-4 sm:px-7">
          <div className="flex items-start justify-between gap-4">
            <div>
              <div className="flex flex-wrap items-center gap-3">
                <h2 className="text-2xl font-black text-[#0B2A4A]">
                  شطب الأصل الثابت
                </h2>

                <span className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-black text-rose-700">
                  عملية نهائية
                </span>
              </div>

              <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                {asset.asset_code} —{" "}
                {asset.asset_name}
              </p>
            </div>

            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-xl font-black text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="إغلاق نافذة الشطب"
            >
              ×
            </button>
          </div>
        </header>

        {/*
        |--------------------------------------------------------------------------
        | محتوى النافذة
        |--------------------------------------------------------------------------
        */}

        <div className="flex-1 overflow-y-auto p-5 sm:p-7">
          <div className="space-y-5">
            {/*
            |--------------------------------------------------------------------------
            | الرسائل
            |--------------------------------------------------------------------------
            */}

            {message && (
              <MessageBox
                type={message.type}
                text={message.text}
                onClose={() =>
                  setMessage(null)
                }
              />
            )}

            {/*
            |--------------------------------------------------------------------------
            | معلومات الأصل
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <div>
                <h3 className="font-black text-[#0B2A4A]">
                  بيانات الأصل الحالية
                </h3>

                <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                  راجع معلومات الأصل قبل
                  تنفيذ عملية الشطب.
                </p>
              </div>

              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <InfoCard
                  title="كود الأصل"
                  value={asset.asset_code}
                />

                <InfoCard
                  title="اسم الأصل"
                  value={asset.asset_name}
                />

                <InfoCard
                  title="الفئة"
                  value={
                    asset.category
                      ?.category_name || "-"
                  }
                />

                <InfoCard
                  title="حالة الأصل"
                  value={statusLabel(
                    asset.asset_status
                  )}
                />

                <InfoCard
                  title="تكلفة الشراء"
                  value={formatMoney(
                    asset.purchase_cost
                  )}
                />

                <InfoCard
                  title="مجمع الإهلاك"
                  value={formatMoney(
                    asset
                      .accumulated_depreciation
                  )}
                />

                <InfoCard
                  title="القيمة الدفترية"
                  value={formatMoney(
                    asset
                      .current_book_value
                  )}
                />

                <InfoCard
                  title="القيمة التخريدية"
                  value={formatMoney(
                    asset.salvage_value
                  )}
                />

                <InfoCard
                  title="الفرع"
                  value={
                    asset.branch_name ||
                    String(
                      asset.branch_id ||
                        "-"
                    )
                  }
                />

                <InfoCard
                  title="الموقع"
                  value={
                    asset.location || "-"
                  }
                />

                <InfoCard
                  title="الموظف المسؤول"
                  value={
                    asset
                      .responsible_worker_name ||
                    "-"
                  }
                />

                <InfoCard
                  title="طريقة الإهلاك"
                  value={depreciationMethodLabel(
                    asset
                      .depreciation_method
                  )}
                />
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | بيانات الشطب
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div>
                <h3 className="font-black text-[#0B2A4A]">
                  بيانات عملية الشطب
                </h3>

                <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                  أدخل تاريخ الشطب والمرجع
                  والملاحظات المتعلقة بسبب
                  إخراج الأصل من الخدمة.
                </p>
              </div>

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="تاريخ الشطب">
                  <input
                    type="date"
                    value={disposalDate}
                    onChange={(event) =>
                      setDisposalDate(
                        event.target.value
                      )
                    }
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="الرقم المرجعي">
                  <input
                    type="text"
                    value={referenceNo}
                    onChange={(event) =>
                      setReferenceNo(
                        event.target.value
                      )
                    }
                    placeholder="رقم قرار الشطب أو المحضر"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | الحسابات المحاسبية
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div>
                <h3 className="font-black text-[#0B2A4A]">
                  الحسابات المحاسبية
                </h3>

                <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                  اترك الحقول الاختيارية
                  فارغة عندما يقوم النظام
                  بجلب الحسابات تلقائيًا من
                  إعدادات الأصل أو إعدادات
                  المحاسبة.
                </p>
              </div>

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="رقم حساب الأصل">
                  <input
                    type="number"
                    min="1"
                    value={assetAccountId}
                    onChange={(event) =>
                      setAssetAccountId(
                        event.target.value
                      )
                    }
                    placeholder="اختياري"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم حساب مجمع الإهلاك">
                  <input
                    type="number"
                    min="1"
                    value={
                      accumulatedAccountId
                    }
                    onChange={(event) =>
                      setAccumulatedAccountId(
                        event.target.value
                      )
                    }
                    placeholder="اختياري"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم حساب خسائر شطب الأصول">
                  <input
                    type="number"
                    min="1"
                    value={
                      disposalLossAccountId
                    }
                    onChange={(event) =>
                      setDisposalLossAccountId(
                        event.target.value
                      )
                    }
                    placeholder="يستخدم لإثبات القيمة الدفترية المتبقية"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | الأثر المالي
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-amber-200 bg-amber-50 p-5">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <h3 className="font-black text-amber-900">
                    الأثر المالي المتوقع
                  </h3>

                  <p className="mt-2 text-sm font-semibold leading-7 text-amber-800">
                    القيمة الدفترية المتبقية
                    ستتم معالجتها محاسبيًا
                    كخسارة شطب، وفق الحساب
                    المحدد أو إعدادات النظام.
                  </p>
                </div>

                <div className="rounded-2xl border border-amber-200 bg-white px-6 py-4 text-center">
                  <div className="text-xs font-bold text-amber-700">
                    القيمة الدفترية
                    المتبقية
                  </div>

                  <div className="mt-2 text-2xl font-black text-amber-950">
                    {formatMoney(
                      asset
                        .current_book_value
                    )}
                  </div>
                </div>
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | ملخص نتيجة العملية
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
              <h3 className="font-black text-[#0B2A4A]">
                نتيجة عملية الشطب
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <ResultItem text="ستتغير حالة الأصل إلى مشطوب." />

                <ResultItem text="لن يكون الأصل متاحًا للبيع." />

                <ResultItem text="لن يكون الأصل متاحًا للنقل." />

                <ResultItem text="لن يكون الأصل متاحًا للصيانة." />

                <ResultItem text="لن يتم احتساب إهلاك جديد على الأصل." />

                <ResultItem text="سيبقى سجل الأصل محفوظًا لأغراض التقارير والمراجعة." />
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | الملاحظات
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <FieldWrapper label="ملاحظات عملية الشطب">
                <textarea
                  value={notes}
                  onChange={(event) =>
                    setNotes(
                      event.target.value
                    )
                  }
                  rows={5}
                  placeholder="اكتب سبب الشطب، حالة الأصل، رقم المحضر، قرار اللجنة أو أي تفاصيل إضافية..."
                  className={`${inputClassName} min-h-32 py-3`}
                />
              </FieldWrapper>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | التنبيه النهائي
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border-2 border-rose-300 bg-rose-50 p-5">
              <div className="flex items-start gap-4">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-rose-100 text-xl font-black text-rose-700">
                  !
                </div>

                <div>
                  <h3 className="font-black text-rose-900">
                    تنبيه مهم قبل
                    الاعتماد
                  </h3>

                  <p className="mt-2 text-sm font-semibold leading-7 text-rose-800">
                    عملية الشطب نهائية.
                    بعد اعتمادها لن يصبح
                    الأصل متاحًا ضمن العمليات
                    التشغيلية المعتادة، لذلك
                    تأكد من صحة الأصل
                    والتاريخ والحسابات قبل
                    المتابعة.
                  </p>
                </div>
              </div>
            </section>
          </div>
        </div>

        {/*
        |--------------------------------------------------------------------------
        | أزرار النافذة
        |--------------------------------------------------------------------------
        */}

        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <button
            type="button"
            onClick={handleDisposeAsset}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-rose-700 px-6 text-sm font-black text-white transition hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {loading
              ? "جاري تنفيذ الشطب..."
              : "اعتماد شطب الأصل"}
          </button>

          <button
            type="button"
            onClick={onClose}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 text-sm font-black text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
          >
            إلغاء
          </button>
        </footer>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| غلاف الحقل
|--------------------------------------------------------------------------
*/

function FieldWrapper({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
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

/*
|--------------------------------------------------------------------------
| بطاقة معلومات الأصل
|--------------------------------------------------------------------------
*/

function InfoCard({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-2 break-words font-black text-slate-800">
        {value}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| نتيجة عملية الشطب
|--------------------------------------------------------------------------
*/

function ResultItem({
  text,
}: {
  text: string;
}) {
  return (
    <div className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4">
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-black text-emerald-700">
        ✓
      </span>

      <span className="text-sm font-bold leading-7 text-slate-700">
        {text}
      </span>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| صندوق الرسالة
|--------------------------------------------------------------------------
*/

function MessageBox({
  type,
  text,
  onClose,
}: {
  type:
    | "success"
    | "error"
    | "warning"
    | "info";

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
      <div className="leading-7">
        {text}
      </div>

      <button
        type="button"
        onClick={onClose}
        className="shrink-0 font-black"
        aria-label="إغلاق الرسالة"
      >
        ×
      </button>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| تاريخ اليوم
|--------------------------------------------------------------------------
*/

function today(): string {
  return new Date()
    .toISOString()
    .slice(0, 10);
}

/*
|--------------------------------------------------------------------------
| تنسيق المبالغ
|--------------------------------------------------------------------------
*/

function formatMoney(
  value:
    | number
    | string
    | null
    | undefined
): string {
  const parsed = Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed.toLocaleString(
        "ar-SA",
        {
          minimumFractionDigits: 3,
          maximumFractionDigits: 3,
        }
      )
    : "0.000";
}

/*
|--------------------------------------------------------------------------
| اسم حالة الأصل
|--------------------------------------------------------------------------
*/

function statusLabel(
  status: FixedAsset["asset_status"]
): string {
  const labels: Record<
    FixedAsset["asset_status"],
    string
  > = {
    ACTIVE: "نشط",
    UNDER_MAINTENANCE:
      "تحت الصيانة",
    SOLD: "مباع",
    DISPOSED: "مشطوب",
  };

  return labels[status];
}

/*
|--------------------------------------------------------------------------
| اسم طريقة الإهلاك
|--------------------------------------------------------------------------
*/

function depreciationMethodLabel(
  method: FixedAsset["depreciation_method"]
): string {
  const labels: Record<
    FixedAsset["depreciation_method"],
    string
  > = {
    STRAIGHT_LINE:
      "القسط الثابت",

    DECLINING_BALANCE:
      "الرصيد المتناقص",

    NO_DEPRECIATION:
      "بدون إهلاك",
  };

  return labels[method];
}

/*
|--------------------------------------------------------------------------
| استخراج رسالة خطأ API
|--------------------------------------------------------------------------
*/

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

            errors?: Record<
              string,
              string[] | string
            >;
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

    const errors =
      response?.data?.errors;

    if (
      errors &&
      typeof errors === "object"
    ) {
      const firstError =
        Object.values(errors)[0];

      if (Array.isArray(firstError)) {
        return (
          firstError[0] ||
          fallback
        );
      }

      if (
        typeof firstError ===
        "string"
      ) {
        return firstError;
      }
    }
  }

  if (
    error instanceof Error &&
    error.message
  ) {
    return error.message;
  }

  return fallback;
}

/*
|--------------------------------------------------------------------------
| تصميم الحقول
|--------------------------------------------------------------------------
*/

const inputClassName =
  "h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100 disabled:cursor-not-allowed disabled:bg-slate-100";