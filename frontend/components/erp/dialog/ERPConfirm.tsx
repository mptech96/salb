"use client";

export default function ERPConfirm({
  open,
  title = "تأكيد العملية",
  text = "هل تريد المتابعة؟",
  confirmText = "تأكيد",
  cancelText = "إلغاء",
  type = "warning",
  onConfirm,
  onCancel,
}: any) {
  if (!open) return null;

  const confirmClass =
    type === "danger"
      ? "bg-rose-600 hover:bg-rose-700"
      : "bg-[#0B2A4A] hover:bg-[#123D68]";

  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
      <div className="w-full max-w-md rounded-3xl bg-white p-6 text-right shadow-2xl" dir="rtl">
        <div className="mb-4 text-2xl font-black text-[#0B2A4A]">{title}</div>

        <div className="rounded-2xl border bg-slate-50 p-4 text-sm font-semibold leading-7 text-slate-700">
          {text}
        </div>

        <div className="mt-6 flex gap-3">
          <button
            onClick={onConfirm}
            className={`flex-1 rounded-2xl px-5 py-3 font-black text-white ${confirmClass}`}
          >
            {confirmText}
          </button>

          <button
            onClick={onCancel}
            className="flex-1 rounded-2xl bg-slate-200 px-5 py-3 font-black text-slate-700 hover:bg-slate-300"
          >
            {cancelText}
          </button>
        </div>
      </div>
    </div>
  );
}