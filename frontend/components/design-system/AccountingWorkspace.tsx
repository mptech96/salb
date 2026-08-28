"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import {StatusBadge,SurfaceCard} from "@/components/ui/enterprise";

export function AccountingContextBar({ children }: { children: ReactNode }) {
  return <div className="flex max-w-full flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-[11px] text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,.03)]">{children}</div>;
}

export function FinancialMetric({ label, value, tone = "default" }: { label: string; value: ReactNode; tone?: "default" | "success" | "danger" }) {
  const color = tone === "success" ? "text-emerald-700" : tone === "danger" ? "text-rose-700" : "text-slate-950";
  return <div className="rounded-lg border border-slate-200 bg-white px-3 py-2.5"><div className="text-[10px] font-semibold text-slate-500">{label}</div><div className={`mt-1 text-lg font-black tabular-nums ${color}`}>{value}</div></div>;
}

export function AccountingShortcut({ href, title, description }: { href: string; title: string; description: string }) {
  return <Link href={href} className="group rounded-lg border border-slate-200 bg-white p-3 transition hover:border-sky-300 hover:shadow-sm"><div className="text-xs font-bold text-slate-900 group-hover:text-sky-800">{title}</div><div className="mt-1 text-[10px] leading-5 text-slate-500">{description}</div></Link>;
}

export function ReportExportBar({ children }: { children: ReactNode }) {
  return <div className="no-print flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white p-2">{children}</div>;
}

export function FinancialWorkflow({steps,active}:{steps:string[];active:number}){
  return <nav aria-label="مراحل المستند" className="max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-white p-2"><ol className="flex min-w-max items-center gap-1">{steps.map((step,index)=><li key={step} className="flex items-center"><span className={`inline-flex min-h-8 items-center rounded-md px-3 text-xs font-semibold ${index===active?"bg-[var(--sulb-primary)] text-white":index<active?"bg-emerald-50 text-emerald-700":"bg-slate-50 text-slate-500"}`}>{index+1}. {step}</span>{index<steps.length-1?<span aria-hidden className="mx-1 text-slate-300">←</span>:null}</li>)}</ol></nav>;
}

export function SetupHealth({complete,attention,missing}:{complete:number;attention:number;missing:number}){
  return <div className="grid grid-cols-3 gap-2"><div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3"><div className="text-[10px] font-semibold text-emerald-700">مكتمل</div><div className="mt-1 text-xl font-black tabular-nums text-emerald-800">{complete}</div></div><div className="rounded-lg border border-amber-200 bg-amber-50 p-3"><div className="text-[10px] font-semibold text-amber-700">يحتاج انتباه</div><div className="mt-1 text-xl font-black tabular-nums text-amber-800">{attention}</div></div><div className="rounded-lg border border-rose-200 bg-rose-50 p-3"><div className="text-[10px] font-semibold text-rose-700">ربط مفقود</div><div className="mt-1 text-xl font-black tabular-nums text-rose-800">{missing}</div></div></div>;
}

export function FinancialReview({title="ملخص المراجعة",items}:{title?:string;items:Array<{label:string;value:ReactNode;tone?:"neutral"|"success"|"warning"|"danger"}>}){
  return <SurfaceCard title={title}><dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4 lg:grid-cols-4">{items.map(item=><div key={item.label} className="min-w-0"><dt className="text-[10px] font-semibold text-slate-500">{item.label}</dt><dd className="mt-1 truncate text-sm font-bold tabular-nums text-slate-900">{item.value}</dd>{item.tone?<StatusBadge tone={item.tone}>{item.tone==="success"?"سليم":item.tone==="warning"?"مراجعة":"تنبيه"}</StatusBadge>:null}</div>)}</dl></SurfaceCard>;
}

export function FinancialNotice({children,tone="info"}:{children:ReactNode;tone?:"info"|"warning"|"danger"|"success"}){
  const styles={info:"border-sky-200 bg-sky-50 text-sky-900",warning:"border-amber-200 bg-amber-50 text-amber-900",danger:"border-rose-200 bg-rose-50 text-rose-900",success:"border-emerald-200 bg-emerald-50 text-emerald-900"};
  return <div className={`rounded-lg border px-3 py-2.5 text-xs leading-6 ${styles[tone]}`}>{children}</div>;
}
