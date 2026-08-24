"use client";

import { useEffect } from "react";

type DialogType = "success" | "error" | "warning" | "info" | "confirm";

type Props = {
  open: boolean;
  type?: DialogType;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  showCancel?: boolean;
  loading?: boolean;
  onConfirm: () => void | Promise<void>;
  onClose: () => void;
};

const dialogStyles: Record<
  DialogType,
  {
    icon: string;
    iconClass: string;
    buttonClass: string;
  }
> = {
  success: {
    icon: "✓",
    iconClass: "bg-emerald-100 text-emerald-700",
    buttonClass: "bg-emerald-600 hover:bg-emerald-700",
  },
  error: {
    icon: "!",
    iconClass: "bg-rose-100 text-rose-700",
    buttonClass: "bg-rose-600 hover:bg-rose-700",
  },
  warning: {
    icon: "!",
    iconClass: "bg-amber-100 text-amber-700",
    buttonClass: "bg-amber-600 hover:bg-amber-700",
  },
  info: {
    icon: "i",
    iconClass: "bg-blue-100 text-blue-700",
    buttonClass: "bg-[#0B2A4A] hover:bg-[#123D68]",
  },
  confirm: {
    icon: "?",
    iconClass: "bg-slate-100 text-[#0B2A4A]",
    buttonClass: "bg-[#0B2A4A] hover:bg-[#123D68]",
  },
};

export default function SystemDialog({
  open,
  type = "info",
  title,
  message,
  confirmText = "حسنًا",
  cancelText = "إلغاء",
  showCancel = false,
  loading = false,
  onConfirm,
  onClose,
}: Props) {
  useEffect(() => {
    if (!open) return;

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape" && !loading) {
        onClose();
      }
    }

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [loading, onClose, open]);

  if (!open) return null;

  const style = dialogStyles[type];

  return (
    <div
      dir="rtl"
      className="fixed inset-0 z-[250] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="system-dialog-title"
      aria-describedby="system-dialog-message"
    >
      <div className="w-full max-w-md rounded-[28px] border border-slate-200 bg-white p-6 shadow-2xl sm:p-7">
        <div className="flex flex-col items-center text-center">
          <div
            className={`flex h-16 w-16 items-center justify-center rounded-full text-3xl font-black ${style.iconClass}`}
          >
            {style.icon}
          </div>

          <h2
            id="system-dialog-title"
            className="mt-4 text-2xl font-black text-[#0B2A4A]"
          >
            {title}
          </h2>

          <p
            id="system-dialog-message"
            className="mt-3 whitespace-pre-line text-sm font-medium leading-7 text-slate-600"
          >
            {message}
          </p>
        </div>

        <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-center">
          {showCancel ? (
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="min-w-32 rounded-2xl border border-slate-300 bg-white px-5 py-3 font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {cancelText}
            </button>
          ) : null}

          <button
            type="button"
            onClick={() => void onConfirm()}
            disabled={loading}
            className={`min-w-32 rounded-2xl px-5 py-3 font-black text-white transition disabled:cursor-not-allowed disabled:opacity-50 ${style.buttonClass}`}
          >
            {loading ? "جاري التنفيذ..." : confirmText}
          </button>
        </div>
      </div>
    </div>
  );
}
