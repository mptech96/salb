"use client";

import { useEffect, useState } from "react";

import type { FixedAsset } from "../types";

import {
  createMaintenance,
  type MaintenancePayload,
} from "../services/fixedAssets";

import {
  firstValidationMessage,
  validateMaintenance,
} from "../validation";

type Props = {
  open: boolean;
  asset: FixedAsset | null;
  onClose: () => void;
  onCompleted?: () => void;
};

type MessageState = {
  type: "success" | "error" | "warning" | "info";
  text: string;
} | null;

export default function MaintenanceDialog({
  open,
  asset,
  onClose,
  onCompleted,
}: Props) {
  const [maintenanceDate, setMaintenanceDate] =
    useState(today());

  const [maintenanceType, setMaintenanceType] =
    useState("");

  const [supplierName, setSupplierName] =
    useState("");

  const [invoiceNumber, setInvoiceNumber] =
    useState("");

  const [maintenanceCost, setMaintenanceCost] =
    useState<number | string>("");

  const [costTreatment, setCostTreatment] =
    useState<"EXPENSE" | "CAPITALIZE">(
      "EXPENSE"
    );

  const [
    expenseAccountId,
    setExpenseAccountId,
  ] = useState<number | string>("");

  const [
    paymentAccountId,
    setPaymentAccountId,
  ] = useState<number | string>("");

  const [description, setDescription] =
    useState("");

  const [notes, setNotes] = useState("");

  const [loading, setLoading] =
    useState(false);

  const [message, setMessage] =
    useState<MessageState>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setMaintenanceDate(today());
    setMaintenanceType("");
    setSupplierName("");
    setInvoiceNumber("");
    setMaintenanceCost("");
    setCostTreatment("EXPENSE");
    setExpenseAccountId("");
    setPaymentAccountId("");
    setDescription("");
    setNotes("");
    setLoading(false);
    setMessage(null);
  }, [open, asset]);

  if (!open || !asset) {
    return null;
  }

  async function handleCreateMaintenance() {
    if (!asset) {
      setMessage({
        type: "error",
        text: "لم يتم تحديد الأصل المطلوب صيانته.",
      });

      return;
    }

    const payload: MaintenancePayload = {
      maintenance_date: maintenanceDate,

      maintenance_type:
        maintenanceType.trim() || null,

      supplier_name:
        supplierName.trim() || null,

      invoice_number:
        invoiceNumber.trim() || null,

      maintenance_cost: Number(
        maintenanceCost || 0
      ),

      cost_treatment: costTreatment,

      expense_account_id:
        expenseAccountId === ""
          ? null
          : Number(expenseAccountId),

      payment_account_id:
        paymentAccountId === ""
          ? null
          : Number(paymentAccountId),

      description:
        description.trim() || null,

      notes:
        notes.trim() || null,
    };

    const validation =
      validateMaintenance(payload);

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
      await createMaintenance(
        asset.id,
        payload
      );

      setMessage({
        type: "success",
        text: "تم فتح عملية صيانة للأصل بنجاح.",
      });

      onCompleted?.();

      window.setTimeout(() => {
        onClose();
      }, 700);
    } catch (error: unknown) {
      setMessage({
        type: "error",
        text: getApiError(
          error,
          "تعذر فتح عملية صيانة للأصل."
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
            فتح صيانة للأصل
          </h2>

          <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
            {asset.asset_code} —{" "}
            {asset.asset_name}
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
              <h3 className="font-black text-[#0B2A4A]">
                بيانات الأصل
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <InfoCard
                  title="كود الأصل"
                  value={asset.asset_code}
                />

                <InfoCard
                  title="اسم الأصل"
                  value={asset.asset_name}
                />

                <InfoCard
                  title="الحالة الحالية"
                  value={statusLabel(
                    asset.asset_status
                  )}
                />

                <InfoCard
                  title="الفرع"
                  value={
                    asset.branch_name ||
                    String(
                      asset.branch_id || "-"
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
                  title="القيمة الدفترية"
                  value={formatMoney(
                    asset.current_book_value
                  )}
                />
              </div>
            </section>

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <h3 className="font-black text-[#0B2A4A]">
                تفاصيل الصيانة
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="تاريخ الصيانة">
                  <input
                    type="date"
                    value={maintenanceDate}
                    onChange={(event) =>
                      setMaintenanceDate(
                        event.target.value
                      )
                    }
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="نوع الصيانة">
                  <input
                    type="text"
                    value={maintenanceType}
                    onChange={(event) =>
                      setMaintenanceType(
                        event.target.value
                      )
                    }
                    placeholder="مثال: صيانة دورية"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="اسم المورد">
                  <input
                    type="text"
                    value={supplierName}
                    onChange={(event) =>
                      setSupplierName(
                        event.target.value
                      )
                    }
                    placeholder="اختياري"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم الفاتورة">
                  <input
                    type="text"
                    value={invoiceNumber}
                    onChange={(event) =>
                      setInvoiceNumber(
                        event.target.value
                      )
                    }
                    placeholder="اختياري"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="تكلفة الصيانة">
                  <input
                    type="number"
                    min="0"
                    step="0.001"
                    value={maintenanceCost}
                    onChange={(event) =>
                      setMaintenanceCost(
                        event.target.value
                      )
                    }
                    placeholder="0.000"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="المعالجة المحاسبية">
                  <select
                    value={costTreatment}
                    onChange={(event) =>
                      setCostTreatment(
                        event.target.value as
                          | "EXPENSE"
                          | "CAPITALIZE"
                      )
                    }
                    className={inputClassName}
                  >
                    <option value="EXPENSE">
                      تحميلها كمصروف
                    </option>

                    <option value="CAPITALIZE">
                      رسملتها على الأصل
                    </option>
                  </select>
                </FieldWrapper>

                {costTreatment ===
                  "EXPENSE" && (
                  <FieldWrapper label="رقم حساب مصروف الصيانة">
                    <input
                      type="number"
                      value={expenseAccountId}
                      onChange={(event) =>
                        setExpenseAccountId(
                          event.target.value
                        )
                      }
                      placeholder="رقم الحساب"
                      className={inputClassName}
                    />
                  </FieldWrapper>
                )}

                <FieldWrapper label="رقم حساب السداد">
                  <input
                    type="number"
                    value={paymentAccountId}
                    onChange={(event) =>
                      setPaymentAccountId(
                        event.target.value
                      )
                    }
                    placeholder="نقد أو بنك"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <div className="md:col-span-2">
                  <FieldWrapper label="وصف الصيانة">
                    <textarea
                      value={description}
                      onChange={(event) =>
                        setDescription(
                          event.target.value
                        )
                      }
                      rows={3}
                      placeholder="وصف الأعمال المطلوبة..."
                      className={`${inputClassName} min-h-24 py-3`}
                    />
                  </FieldWrapper>
                </div>

                <div className="md:col-span-2">
                  <FieldWrapper label="ملاحظات">
                    <textarea
                      value={notes}
                      onChange={(event) =>
                        setNotes(
                          event.target.value
                        )
                      }
                      rows={3}
                      placeholder="أي ملاحظات إضافية..."
                      className={`${inputClassName} min-h-24 py-3`}
                    />
                  </FieldWrapper>
                </div>
              </div>
            </section>

            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold leading-7 text-amber-900">
              عند فتح الصيانة ستتغير حالة
              الأصل إلى «تحت الصيانة»، ولن
              يدخل الأصل ضمن تشغيل الإهلاك
              حتى إغلاق الصيانة وإعادته للحالة
              النشطة.
            </div>
          </div>
        </div>

        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <button
            type="button"
            onClick={handleCreateMaintenance}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#0B2A4A] px-6 text-sm font-black text-white transition hover:bg-[#123D68] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {loading
              ? "جاري فتح الصيانة..."
              : "فتح عملية الصيانة"}
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

function today(): string {
  return new Date()
    .toISOString()
    .slice(0, 10);
}

function formatMoney(
  value: number | string | null | undefined
): string {
  const parsed = Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed.toLocaleString("ar-SA", {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
      })
    : "0.000";
}

function statusLabel(
  status: FixedAsset["asset_status"]
): string {
  const labels: Record<
    FixedAsset["asset_status"],
    string
  > = {
    ACTIVE: "نشط",
    UNDER_MAINTENANCE: "تحت الصيانة",
    SOLD: "مباع",
    DISPOSED: "مشطوب",
  };

  return labels[status];
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

const inputClassName =
  "h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100";