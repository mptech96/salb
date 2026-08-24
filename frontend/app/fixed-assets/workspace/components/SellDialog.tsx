"use client";

import {
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import type { FixedAsset } from "../types";

import {
  sellAsset,
  type SellAssetPayload,
} from "../services/fixedAssets";

import {
  firstValidationMessage,
  validateSale,
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
| نافذة بيع الأصل
|--------------------------------------------------------------------------
*/

export default function SellDialog({
  open,
  asset,
  onClose,
  onCompleted,
}: Props) {
  /*
  |--------------------------------------------------------------------------
  | حقول البيع
  |--------------------------------------------------------------------------
  */

  const [saleDate, setSaleDate] =
    useState(today());

  const [saleAmount, setSaleAmount] =
    useState<number | string>("");

  const [
    collectionAccountId,
    setCollectionAccountId,
  ] = useState<number | string>("");

  const [
    assetAccountId,
    setAssetAccountId,
  ] = useState<number | string>("");

  const [
    accumulatedAccountId,
    setAccumulatedAccountId,
  ] = useState<number | string>("");

  const [
    disposalGainAccountId,
    setDisposalGainAccountId,
  ] = useState<number | string>("");

  const [
    disposalLossAccountId,
    setDisposalLossAccountId,
  ] = useState<number | string>("");

  const [referenceNo, setReferenceNo] =
    useState("");

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
  | إعادة ضبط النافذة عند فتحها
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    if (!open) {
      return;
    }

    setSaleDate(today());
    setSaleAmount("");
    setCollectionAccountId("");
    setAssetAccountId("");
    setAccumulatedAccountId("");
    setDisposalGainAccountId("");
    setDisposalLossAccountId("");
    setReferenceNo("");
    setNotes("");
    setLoading(false);
    setMessage(null);
  }, [open, asset]);

  /*
  |--------------------------------------------------------------------------
  | حساب الربح أو الخسارة المتوقعة
  |--------------------------------------------------------------------------
  */

  const expectedResult =
    useMemo(() => {
      const saleValue = Number(
        saleAmount || 0
      );

      const bookValue = Number(
        asset?.current_book_value || 0
      );

      const difference =
        saleValue - bookValue;

      if (difference > 0) {
        return {
          type: "GAIN" as const,
          label: "ربح متوقع من البيع",
          amount: difference,
        };
      }

      if (difference < 0) {
        return {
          type: "LOSS" as const,
          label: "خسارة متوقعة من البيع",
          amount: Math.abs(difference),
        };
      }

      return {
        type: "EQUAL" as const,
        label:
          "لا يوجد ربح أو خسارة متوقعة",
        amount: 0,
      };
    }, [
      saleAmount,
      asset?.current_book_value,
    ]);

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
  | تنفيذ عملية البيع
  |--------------------------------------------------------------------------
  */

  async function handleSellAsset() {
    if (!asset) {
      setMessage({
        type: "error",
        text: "لم يتم تحديد الأصل المطلوب بيعه.",
      });

      return;
    }

    const payload: SellAssetPayload = {
      sale_date: saleDate,

      sale_amount: Number(
        saleAmount || 0
      ),

      collection_account_id: Number(
        collectionAccountId || 0
      ),

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

      disposal_gain_account_id:
        disposalGainAccountId === ""
          ? null
          : Number(
              disposalGainAccountId
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
      validateSale(payload);

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
      await sellAsset(
        asset.id,
        payload
      );

      setMessage({
        type: "success",
        text: "تم بيع الأصل بنجاح.",
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
          "تعذر تنفيذ عملية بيع الأصل."
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
    <div className="fixed inset-0 z-[990] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
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
              <h2 className="text-2xl font-black text-[#0B2A4A]">
                بيع الأصل الثابت
              </h2>

              <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                {asset.asset_code} —{" "}
                {asset.asset_name}
              </p>
            </div>

            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-xl font-black text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="إغلاق نافذة البيع"
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
              <h3 className="font-black text-[#0B2A4A]">
                بيانات الأصل الحالية
              </h3>

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
                  title="الحالة الحالية"
                  value={statusLabel(
                    asset.asset_status
                  )}
                />
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | بيانات البيع الأساسية
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <h3 className="font-black text-[#0B2A4A]">
                بيانات عملية البيع
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="تاريخ البيع">
                  <input
                    type="date"
                    value={saleDate}
                    onChange={(event) =>
                      setSaleDate(
                        event.target.value
                      )
                    }
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="قيمة البيع">
                  <input
                    type="number"
                    min="0"
                    step="0.001"
                    value={saleAmount}
                    onChange={(event) =>
                      setSaleAmount(
                        event.target.value
                      )
                    }
                    placeholder="0.000"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم حساب التحصيل">
                  <input
                    type="number"
                    min="1"
                    value={
                      collectionAccountId
                    }
                    onChange={(event) =>
                      setCollectionAccountId(
                        event.target.value
                      )
                    }
                    placeholder="حساب النقد أو البنك"
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
                    placeholder="رقم العقد أو الفاتورة"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | نتيجة البيع المتوقعة
            |--------------------------------------------------------------------------
            */}

            <ExpectedResultCard
              type={expectedResult.type}
              label={expectedResult.label}
              amount={
                expectedResult.amount
              }
              saleAmount={Number(
                saleAmount || 0
              )}
              bookValue={Number(
                asset.current_book_value ||
                  0
              )}
            />

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
                  اترك الحسابات الاختيارية
                  فارغة عندما يقوم النظام
                  بجلبها تلقائيًا من بيانات
                  الأصل أو الإعدادات
                  المحاسبية.
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

                <FieldWrapper label="رقم حساب أرباح بيع الأصول">
                  <input
                    type="number"
                    min="1"
                    value={
                      disposalGainAccountId
                    }
                    onChange={(event) =>
                      setDisposalGainAccountId(
                        event.target.value
                      )
                    }
                    placeholder="يستخدم عند وجود ربح"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم حساب خسائر بيع الأصول">
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
                    placeholder="يستخدم عند وجود خسارة"
                    className={
                      inputClassName
                    }
                  />
                </FieldWrapper>
              </div>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | الملاحظات
            |--------------------------------------------------------------------------
            */}

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <FieldWrapper label="ملاحظات عملية البيع">
                <textarea
                  value={notes}
                  onChange={(event) =>
                    setNotes(
                      event.target.value
                    )
                  }
                  rows={4}
                  placeholder="بيانات المشتري أو تفاصيل البيع أو أي ملاحظات أخرى..."
                  className={`${inputClassName} min-h-28 py-3`}
                />
              </FieldWrapper>
            </section>

            {/*
            |--------------------------------------------------------------------------
            | التنبيه
            |--------------------------------------------------------------------------
            */}

            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold leading-7 text-rose-900">
              بعد اعتماد البيع ستتغير
              حالة الأصل إلى «مباع»، ولن
              يكون متاحًا للنقل أو الصيانة
              أو تشغيل الإهلاك مرة أخرى.
            </div>
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
            onClick={handleSellAsset}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#0B2A4A] px-6 text-sm font-black text-white transition hover:bg-[#123D68] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {loading
              ? "جاري تنفيذ البيع..."
              : "اعتماد بيع الأصل"}
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
| بطاقة نتيجة البيع
|--------------------------------------------------------------------------
*/

function ExpectedResultCard({
  type,
  label,
  amount,
  saleAmount,
  bookValue,
}: {
  type: "GAIN" | "LOSS" | "EQUAL";
  label: string;
  amount: number;
  saleAmount: number;
  bookValue: number;
}) {
  const className =
    type === "GAIN"
      ? "border-emerald-200 bg-emerald-50 text-emerald-900"
      : type === "LOSS"
        ? "border-rose-200 bg-rose-50 text-rose-900"
        : "border-slate-200 bg-slate-50 text-slate-800";

  return (
    <section
      className={`rounded-3xl border p-5 ${className}`}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <ResultValue
          title="القيمة الدفترية"
          value={formatMoney(bookValue)}
        />

        <ResultValue
          title="قيمة البيع"
          value={formatMoney(saleAmount)}
        />

        <ResultValue
          title={label}
          value={formatMoney(amount)}
        />
      </div>
    </section>
  );
}

/*
|--------------------------------------------------------------------------
| قيمة نتيجة البيع
|--------------------------------------------------------------------------
*/

function ResultValue({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div>
      <div className="text-xs font-bold opacity-70">
        {title}
      </div>

      <div className="mt-2 text-xl font-black">
        {value}
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

      <div className="mt-2 font-black text-slate-800">
        {value}
      </div>
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
| استخراج رسالة خطأ الـ API
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

  return fallback;
}

/*
|--------------------------------------------------------------------------
| تصميم الحقول
|--------------------------------------------------------------------------
*/

const inputClassName =
  "h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100";