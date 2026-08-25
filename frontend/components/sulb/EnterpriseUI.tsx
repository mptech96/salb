"use client";

import {ReactNode} from "react";
import { formatNumber, formatPercentage } from "@/lib/formatters";

export const latinDigits=(v:any)=>String(v??"")
  .replace(/[٠-٩]/g,(d)=>String("٠١٢٣٤٥٦٧٨٩".indexOf(d)))
  .replace(/[۰-۹]/g,(d)=>String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)));
export const fmt=(v:any,max=3)=>formatNumber(v,{maximumFractionDigits:max});
export const money=(v:any)=>formatNumber(v,{minimumFractionDigits:2,maximumFractionDigits:3});
export const percent=(v:any,max=3)=>formatPercentage(v,max);
export const nowLocal=()=>{const d=new Date();d.setMinutes(d.getMinutes()-d.getTimezoneOffset());return d.toISOString().slice(0,16)};
export const today=()=>new Date().toISOString().slice(0,10);

export function PageHero({eyebrow,title,description,actions}:{eyebrow?:string;title:string;description?:string;actions?:ReactNode}){
  return <div className="rounded-[28px] bg-gradient-to-l from-[#0B2A4A] via-[#123D68] to-[#0B2A4A] p-6 text-white shadow-lg">
    <div className="flex flex-wrap items-center justify-between gap-4"><div>{eyebrow&&<div className="text-sm font-bold text-blue-100">{eyebrow}</div>}<h1 className="mt-1 text-3xl font-black">{title}</h1>{description&&<p className="mt-2 max-w-4xl text-sm leading-7 text-blue-100">{description}</p>}</div>{actions&&<div className="flex flex-wrap gap-2">{actions}</div>}</div>
  </div>
}
export function Panel({title,sub,children,actions}:{title?:string;sub?:string;children:ReactNode;actions?:ReactNode}){return <div className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm">{(title||actions)&&<div className="mb-4 flex flex-wrap items-start justify-between gap-3"><div>{title&&<h2 className="text-lg font-black text-[#0B2A4A]">{title}</h2>}{sub&&<p className="mt-1 text-xs leading-6 text-slate-500">{sub}</p>}</div>{actions}</div>}{children}</div>}
export function Modal({title,children,onClose,wide=false,footer}:{title:string;children:ReactNode;onClose:()=>void;wide?:boolean;footer?:ReactNode}){return <div className="fixed inset-0 z-[220] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm"><div dir="rtl" className={`max-h-[95vh] w-full overflow-auto rounded-[28px] bg-white shadow-2xl ${wide?"max-w-7xl":"max-w-2xl"}`}><div className="sticky top-0 z-10 flex items-center justify-between border-b bg-white/95 p-5 backdrop-blur"><h2 className="text-xl font-black text-[#0B2A4A]">{title}</h2><button onClick={onClose} className="rounded-xl border px-4 py-2 font-bold">إغلاق</button></div><div className="p-5">{children}</div>{footer&&<div className="sticky bottom-0 border-t bg-white/95 p-4 backdrop-blur">{footer}</div>}</div></div>}
export function Field({label,children,hint}:{label:string;children:ReactNode;hint?:string}){return <label className="block"><span className="mb-1.5 block text-xs font-black text-slate-600">{label}</span>{children}{hint&&<span className="mt-1 block text-[11px] text-slate-400">{hint}</span>}</label>}
export const inputCls="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm tabular-nums outline-none transition focus:border-[#123D68] focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100";
export function Stat({label,value,sub}:{label:string;value:ReactNode;sub?:string}){return <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4"><div className="text-xs font-bold text-slate-500">{label}</div><div className="mt-1 text-xl font-black text-[#0B2A4A]">{value}</div>{sub&&<div className="mt-1 text-[11px] text-slate-400">{sub}</div>}</div>}
export function Badge({children,tone="slate"}:{children:ReactNode;tone?:"slate"|"blue"|"green"|"amber"|"red"|"purple"}){const m:any={slate:"bg-slate-100 text-slate-700",blue:"bg-blue-100 text-blue-800",green:"bg-emerald-100 text-emerald-800",amber:"bg-amber-100 text-amber-800",red:"bg-rose-100 text-rose-800",purple:"bg-violet-100 text-violet-800"};return <span className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${m[tone]}`}>{children}</span>}
export function Btn({children,onClick,kind="primary",disabled=false,type="button"}:{children:ReactNode;onClick?:()=>void|Promise<void>;kind?:"primary"|"success"|"danger"|"light"|"amber";disabled?:boolean;type?:"button"|"submit"}){const m:any={primary:"bg-[#0B2A4A] text-white hover:bg-[#123D68]",success:"bg-emerald-700 text-white hover:bg-emerald-800",danger:"bg-rose-700 text-white hover:bg-rose-800",light:"border bg-white text-slate-700 hover:bg-slate-50",amber:"bg-amber-500 text-white hover:bg-amber-600"};return <button type={type} onClick={()=>void onClick?.()} disabled={disabled} className={`rounded-xl px-4 py-2.5 text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-50 ${m[kind]}`}>{children}</button>}
export function Empty({text}:{text:string}){return <div className="p-8 text-center text-sm font-bold text-slate-400">{text}</div>}
