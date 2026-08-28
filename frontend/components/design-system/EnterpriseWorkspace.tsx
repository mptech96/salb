"use client";

import { useEffect, type ReactNode } from "react";

export type WorkspaceTab = { id: string; label: string; hint?: string };

export function EnterpriseTabs({ tabs, active, onChange }: { tabs: WorkspaceTab[]; active: string; onChange: (id: string) => void }) {
  return <div className="max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-white p-1" role="tablist" aria-label="أقسام الصفحة"><div className="flex min-w-max gap-1">{tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={active === tab.id} onClick={() => onChange(tab.id)} className={`min-h-9 rounded-md px-3 text-xs font-semibold transition ${active === tab.id ? "bg-[var(--sulb-primary)] text-white shadow-sm" : "text-slate-600 hover:bg-slate-50 hover:text-slate-900"}`}>{tab.label}</button>)}</div></div>;
}

export function EnterpriseDrawer({ open, title, description, children, footer, onClose }: { open: boolean; title: string; description?: string; children: ReactNode; footer?: ReactNode; onClose: () => void }) {
  useEffect(() => { if (!open) return; const previous = document.body.style.overflow; document.body.style.overflow = "hidden"; const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); }; window.addEventListener("keydown", onKey); return () => { document.body.style.overflow = previous; window.removeEventListener("keydown", onKey); }; }, [onClose, open]);
  if (!open) return null;
  return <div className="fixed inset-0 z-[100]" role="presentation"><button type="button" aria-label="إغلاق اللوحة" onClick={onClose} className="absolute inset-0 bg-slate-950/45 backdrop-blur-[1px]" /><aside role="dialog" aria-modal="true" aria-label={title} className="absolute inset-y-0 left-0 flex w-full max-w-[720px] flex-col bg-white shadow-2xl"><header className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5"><div className="min-w-0"><h2 className="text-base font-bold text-slate-950">{title}</h2>{description ? <p className="mt-1 text-xs leading-5 text-slate-500">{description}</p> : null}</div><button type="button" onClick={onClose} className="enterprise-icon-button shrink-0" aria-label="إغلاق">×</button></header><div className="min-h-0 flex-1 overflow-y-auto bg-slate-50/60 p-3 sm:p-4">{children}</div>{footer ? <footer className="border-t border-slate-200 bg-white px-4 py-3 sm:px-5">{footer}</footer> : null}</aside></div>;
}

export function EnterpriseTable({ children, minWidth = 760 }: { children: ReactNode; minWidth?: number }) {
  return <div className="max-w-full overflow-x-auto"><table className="enterprise-table" style={{ minWidth }}>{children}</table></div>;
}

export function EnterpriseFilterBar({ children }: { children: ReactNode }) {
  return <div className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-2.5 shadow-[0_1px_2px_rgba(15,23,42,.03)] sm:flex-row sm:items-center">{children}</div>;
}

export function EnterpriseFormSection({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return <section className="rounded-lg border border-slate-200 bg-white"><div className="border-b border-slate-100 px-3 py-2.5"><h2 className="text-xs font-bold text-slate-900">{title}</h2>{description ? <p className="mt-0.5 text-[11px] leading-5 text-slate-500">{description}</p> : null}</div><div className="p-3 sm:p-4">{children}</div></section>;
}

export function EnterpriseField({ label, children, hint, required }: { label: string; children: ReactNode; hint?: string; required?: boolean }) {
  return <label className="block min-w-0"><span className="mb-1.5 block text-[11px] font-semibold text-slate-700">{label}{required ? <span className="mr-1 text-rose-600">*</span> : null}</span>{children}{hint ? <span className="mt-1 block text-[10px] leading-4 text-slate-500">{hint}</span> : null}</label>;
}

export function StickyActionBar({ dirty, children }: { dirty?: boolean; children: ReactNode }) {
  return <div className="sticky bottom-2 z-20 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white/95 px-3 py-2.5 shadow-[0_8px_30px_rgba(15,23,42,.12)] backdrop-blur"><div className="flex items-center gap-2 text-[11px] text-slate-500"><span className={`h-2 w-2 rounded-full ${dirty ? "bg-amber-500" : "bg-emerald-500"}`} /><span>{dirty ? "توجد تغييرات غير محفوظة" : "جميع التغييرات محفوظة"}</span></div><div className="flex flex-wrap gap-2">{children}</div></div>;
}
