"use client";

import type { ReactNode } from "react";
import { OperationalStatusBar, type OperationalStatus } from "./OperationalWorkspace";

export function DocumentStatusBar({ statuses, active, onChange }: { statuses: OperationalStatus[]; active: string; onChange: (id: string) => void }) {
  return <OperationalStatusBar statuses={statuses} active={active} onChange={onChange} />;
}

export function DocumentTabs({ tabs }: { tabs: string[] }) {
  return <div className="max-w-full overflow-x-auto"><div className="flex min-w-max gap-1 rounded-lg border border-slate-200 bg-white p-1">{tabs.map((tab, index) => <span key={tab} className={`rounded-md px-3 py-2 text-[11px] font-semibold ${index === 0 ? "bg-[var(--sulb-primary)] text-white" : "text-slate-600"}`}>{tab}</span>)}</div></div>;
}

export function InvoiceTotalsPanel({ currency, children }: { currency?: string; children: ReactNode }) {
  return <section className="mr-auto w-full max-w-md rounded-lg border border-slate-200 bg-white p-3"><div className="mb-2 flex items-center justify-between"><h3 className="text-xs font-bold text-slate-900">إجماليات المستند</h3><span className="text-[10px] font-semibold text-slate-500">{currency || ""}</span></div><div className="space-y-1.5 text-xs tabular-nums">{children}</div></section>;
}

export function TotalRow({ label, value, total = false }: { label: string; value: ReactNode; total?: boolean }) {
  return <div className={`flex items-center justify-between gap-4 ${total ? "mt-2 border-t border-slate-200 pt-2 text-sm font-black text-[var(--sulb-primary)]" : "text-slate-600"}`}><span>{label}</span><span>{value}</span></div>;
}
