"use client";

import { useEffect, useState } from "react";

import type { FixedAsset } from "../types";

import {
  transferAsset,
  type TransferAssetPayload,
} from "../services/fixedAssets";

import {
  firstValidationMessage,
  validateTransfer,
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

export default function TransferDialog({
  open,
  asset,
  onClose,
  onCompleted,
}: Props) {
  const [toBranchId, setToBranchId] = useState<
    number | string
  >("");

  const [toWorkerId, setToWorkerId] = useState<
    number | string
  >("");

  const [toLocation, setToLocation] = useState("");

  const [transferDate, setTransferDate] =
    useState(today());

  const [referenceNo, setReferenceNo] =
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

    setToBranchId(asset?.branch_id || "");
    setToWorkerId(
      asset?.responsible_worker_id || ""
    );
    setToLocation(asset?.location || "");
    setTransferDate(today());
    setReferenceNo("");
    setNotes("");
    setLoading(false);
    setMessage(null);
  }, [open, asset]);

  if (!open || !asset) {
    return null;
  }

  async function handleTransfer() {
    if (!asset) {
  setMessage({
    type: "error",
    text: "لم يتم تحديد الأصل المطلوب نقله.",
  });

  return;
}
    const payload: TransferAssetPayload = {
      to_branch_id:
        toBranchId === ""
          ? null
          : Number(toBranchId),

      to_worker_id:
        toWorkerId === ""
          ? null
          : Number(toWorkerId),

      to_location:
        toLocation.trim() || null,

      transfer_date: transferDate,

      reference_no:
        referenceNo.trim() || null,

      notes:
        notes.trim() || null,
    };

    const validation =
      validateTransfer(payload);

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
      await transferAsset(
        asset.id,
        payload
      );

      setMessage({
        type: "success",
        text: "تم نقل الأصل وتحديث العهدة بنجاح.",
      });

      onCompleted?.();

      setTimeout(() => {
        onClose();
      }, 700);
    } catch (error: unknown) {
      setMessage({
        type: "error",
        text: getApiError(
          error,
          "تعذر نقل الأصل."
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
        className="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
      >
        <header className="border-b border-slate-200 px-5 py-4 sm:px-7">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            نقل الأصل
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
                الوضع الحالي
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <InfoCard
                  title="الفرع الحالي"
                  value={
                    asset.branch_name ||
                    String(
                      asset.branch_id || "-"
                    )
                  }
                />

                <InfoCard
                  title="الموقع الحالي"
                  value={
                    asset.location || "-"
                  }
                />

                <InfoCard
                  title="الموظف المسؤول"
                  value={
                    asset.responsible_worker_name ||
                    String(
                      asset.responsible_worker_id ||
                        "-"
                    )
                  }
                />
              </div>
            </section>

            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <h3 className="font-black text-[#0B2A4A]">
                بيانات النقل الجديدة
              </h3>

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <FieldWrapper label="رقم الفرع الجديد">
                  <input
                    type="number"
                    value={toBranchId}
                    onChange={(event) =>
                      setToBranchId(
                        event.target.value
                      )
                    }
                    placeholder="اتركه كما هو عند عدم التغيير"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم الموظف المسؤول">
                  <input
                    type="number"
                    value={toWorkerId}
                    onChange={(event) =>
                      setToWorkerId(
                        event.target.value
                      )
                    }
                    placeholder="اتركه فارغًا لإزالة العهدة"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="الموقع الجديد">
                  <input
                    type="text"
                    value={toLocation}
                    onChange={(event) =>
                      setToLocation(
                        event.target.value
                      )
                    }
                    placeholder="مثال: المستودع الرئيسي"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="تاريخ النقل">
                  <input
                    type="date"
                    value={transferDate}
                    onChange={(event) =>
                      setTransferDate(
                        event.target.value
                      )
                    }
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="رقم المرجع">
                  <input
                    type="text"
                    value={referenceNo}
                    onChange={(event) =>
                      setReferenceNo(
                        event.target.value
                      )
                    }
                    placeholder="اختياري"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <div className="md:col-span-2">
                  <FieldWrapper label="ملاحظات">
                    <textarea
                      value={notes}
                      onChange={(event) =>
                        setNotes(
                          event.target.value
                        )
                      }
                      placeholder="سبب النقل أو أي ملاحظات إضافية..."
                      rows={4}
                      className={`${inputClassName} min-h-28 py-3`}
                    />
                  </FieldWrapper>
                </div>
              </div>
            </section>
          </div>
        </div>

        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <button
            type="button"
            onClick={handleTransfer}
            disabled={loading}
            className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#0B2A4A] px-6 text-sm font-black text-white transition hover:bg-[#123D68] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {loading
              ? "جاري نقل الأصل..."
              : "تأكيد النقل"}
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