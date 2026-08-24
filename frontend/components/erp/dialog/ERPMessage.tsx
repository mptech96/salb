"use client";

type MsgType = "success" | "error" | "info" | "warning";

type ERPMessageProps = {
  msg: { type?: MsgType; title?: string; text?: string } | null;
  onClose: () => void;
};

export default function ERPMessage({ msg, onClose }: ERPMessageProps) {
  if (!msg) return null;

  const styles: Record<MsgType, string> = {
    success: "border-emerald-200 bg-emerald-50 text-emerald-800",
    error: "border-rose-200 bg-rose-50 text-rose-800",
    info: "border-blue-200 bg-blue-50 text-blue-800",
    warning: "border-amber-200 bg-amber-50 text-amber-800",
  };

  return (
    <div className={`rounded-3xl border p-4 shadow-sm ${styles[msg.type || "info"]}`}>
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="text-lg font-black">{msg.title}</div>
          <div className="mt-1 text-sm font-semibold">{msg.text}</div>
        </div>

        <button
          onClick={onClose}
          className="rounded-xl bg-white/70 px-3 py-1 text-sm font-black"
        >
          ×
        </button>
      </div>
    </div>
  );
}