"use client";

import {useEffect,useMemo,useRef,useState} from "react";

export type SearchSelectOption={value:string|number;label:string;search?:string;disabled?:boolean;group?:string};
type Props={value?:string|number|null;onChange:(value:string)=>void;options:SearchSelectOption[];placeholder?:string;searchPlaceholder?:string;emptyText?:string;disabled?:boolean;className?:string;pageSize?:number};

export default function SearchSelect({value,onChange,options,placeholder="اختر",searchPlaceholder="ابحث...",emptyText="لا توجد نتائج",disabled=false,className="",pageSize=50}:Props){
  const [open,setOpen]=useState(false),[query,setQuery]=useState(""),[page,setPage]=useState(1);const root=useRef<HTMLDivElement>(null);
  const selected=options.find(o=>String(o.value)===String(value??""));
  const filtered=useMemo(()=>{const q=query.trim().toLocaleLowerCase("ar");if(!q)return options;return options.filter(o=>`${o.label} ${o.search||""}`.toLocaleLowerCase("ar").includes(q))},[options,query]);
  const pages=Math.max(1,Math.ceil(filtered.length/pageSize)),shown=filtered.slice((page-1)*pageSize,page*pageSize);
  useEffect(()=>{const close=(e:MouseEvent)=>{if(root.current&&!root.current.contains(e.target as Node))setOpen(false)};document.addEventListener("mousedown",close);return()=>document.removeEventListener("mousedown",close)},[]);
  useEffect(()=>{setPage(1)},[query,options.length]);useEffect(()=>{if(!open){setQuery("");setPage(1)}},[open]);
  let lastGroup="";
  return <div ref={root} className={`relative ${className}`}>
    <button type="button" disabled={disabled} onClick={()=>!disabled&&setOpen(v=>!v)} className="flex min-h-[44px] w-full items-center justify-between rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-right text-sm font-semibold text-slate-800 outline-none transition hover:border-slate-400 focus:border-[#0B2A4A] disabled:cursor-not-allowed disabled:bg-slate-100"><span className={`truncate ${selected?"":"text-slate-400"}`}>{selected?.label||placeholder}</span><span className="mr-2 text-xs text-slate-500">⌄</span></button>
    {open&&!disabled&&<div className="absolute z-[9999] mt-1 w-full min-w-[300px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
      <div className="border-b p-2"><input autoFocus value={query} onChange={e=>setQuery(e.target.value)} placeholder={searchPlaceholder} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-right text-sm outline-none focus:border-[#0B2A4A]"/><div className="mt-1 text-left text-[11px] text-slate-400" dir="ltr">{filtered.length.toLocaleString("en-US")} results</div></div>
      <div className="max-h-72 overflow-y-auto p-1">{shown.length===0?<div className="p-4 text-center text-sm text-slate-500">{emptyText}</div>:shown.map(o=>{const showGroup=!!o.group&&o.group!==lastGroup;if(o.group)lastGroup=o.group;return <div key={String(o.value)}>{showGroup&&<div className="px-3 pb-1 pt-2 text-[11px] font-black text-slate-400">{o.group}</div>}<button type="button" disabled={o.disabled} onClick={()=>{onChange(String(o.value));setOpen(false)}} className={`block w-full rounded-lg px-3 py-2.5 text-right text-sm transition ${String(o.value)===String(value??"")?"bg-blue-50 font-black text-[#0B2A4A]":"hover:bg-slate-50"} disabled:opacity-40`}>{o.label}</button></div>})}</div>
      {pages>1&&<div className="flex items-center justify-between border-t bg-slate-50 px-2 py-2 text-xs" dir="ltr"><button type="button" disabled={page<=1} onClick={()=>setPage(p=>Math.max(1,p-1))} className="rounded border bg-white px-3 py-1.5 font-black disabled:opacity-40">Prev</button><span>{page.toLocaleString("en-US")} / {pages.toLocaleString("en-US")}</span><button type="button" disabled={page>=pages} onClick={()=>setPage(p=>Math.min(pages,p+1))} className="rounded border bg-white px-3 py-1.5 font-black disabled:opacity-40">Next</button></div>}
    </div>}
  </div>
}
