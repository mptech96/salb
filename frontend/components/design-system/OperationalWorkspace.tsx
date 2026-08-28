"use client";

export type OperationalStatus = { id: string; label: string; count?: number; tone?: "neutral" | "warning" | "success" | "info" };

const tones = { neutral: "border-slate-200 bg-white text-slate-700", warning: "border-amber-200 bg-amber-50 text-amber-900", success: "border-emerald-200 bg-emerald-50 text-emerald-800", info: "border-sky-200 bg-sky-50 text-sky-800" };

export function OperationalStatusBar({ statuses, active, onChange }: { statuses: OperationalStatus[]; active?: string; onChange?: (id: string) => void }) {
  return <div className="max-w-full overflow-x-auto"><div className="flex min-w-max gap-2">{statuses.map((status) => <button key={status.id} type="button" onClick={() => onChange?.(status.id)} className={`min-w-[132px] rounded-lg border px-3 py-2 text-right transition ${active === status.id ? "ring-2 ring-[var(--sulb-primary)] ring-offset-1" : ""} ${tones[status.tone || "neutral"]}`}><span className="block text-[10px] font-semibold">{status.label}</span>{status.count !== undefined ? <span className="mt-0.5 block text-xl font-bold tabular-nums">{status.count.toLocaleString("ar-IQ")}</span> : null}</button>)}</div></div>;
}

export function NumericMetric({ label, value, unit, emphasis = false }: { label: string; value: string | number; unit?: string; emphasis?: boolean }) {
  return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5"><div className="text-[10px] font-semibold text-slate-500">{label}</div><div className={`mt-1 tabular-nums ${emphasis ? "text-2xl font-black text-[var(--sulb-primary)]" : "text-lg font-bold text-slate-900"}`}>{value}{unit ? <span className="mr-1 text-[10px] font-semibold text-slate-500">{unit}</span> : null}</div></div>;
}
