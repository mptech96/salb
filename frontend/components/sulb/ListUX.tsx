"use client";

import {ReactNode,useEffect,useMemo,useState} from "react";

export function usePagedSearch<T>(rows:T[], text:(row:T)=>string, initialPageSize=25){
  const [query,setQuery]=useState(""),[page,setPage]=useState(1),[pageSize,setPageSize]=useState(initialPageSize);
  const filtered=useMemo(()=>{const q=query.trim().toLocaleLowerCase("ar");if(!q)return rows;return rows.filter(r=>text(r).toLocaleLowerCase("ar").includes(q))},[rows,query,text]);
  const totalPages=Math.max(1,Math.ceil(filtered.length/pageSize));
  useEffect(()=>setPage(1),[query,pageSize,rows.length]);
  useEffect(()=>{if(page>totalPages)setPage(totalPages)},[page,totalPages]);
  const paged=useMemo(()=>filtered.slice((page-1)*pageSize,page*pageSize),[filtered,page,pageSize]);
  return {query,setQuery,page,setPage,pageSize,setPageSize,filtered,paged,totalPages,total:filtered.length};
}

export function ListToolbar({query,setQuery,total,page,pageSize,setPageSize,placeholder="بحث...",extra}:{query:string;setQuery:(v:string)=>void;total:number;page:number;pageSize:number;setPageSize:(n:number)=>void;placeholder?:string;extra?:ReactNode}){
  return <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2"><div className="relative min-w-[240px] flex-1 md:max-w-md"><input value={query} onChange={e=>setQuery(e.target.value)} placeholder={placeholder} className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 pr-10 text-sm outline-none focus:border-[#0B2A4A]"/><span className="pointer-events-none absolute right-3 top-2.5 text-slate-400">⌕</span></div>{extra}</div>
    <div className="flex items-center gap-2 text-xs font-bold text-slate-600"><span>{total.toLocaleString("en-US")} نتيجة</span><span>•</span><span>صفحة {page.toLocaleString("en-US")}</span><select value={pageSize} onChange={e=>setPageSize(Number(e.target.value))} className="rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs"><option value={10}>10 / صفحة</option><option value={25}>25 / صفحة</option><option value={50}>50 / صفحة</option><option value={100}>100 / صفحة</option></select></div>
  </div>
}

export function Pager({page,totalPages,setPage}:{page:number;totalPages:number;setPage:(n:number)=>void}){
  if(totalPages<=1)return null;
  const start=Math.max(1,page-2),end=Math.min(totalPages,start+4),pages=[];for(let i=Math.max(1,end-4);i<=end;i++)pages.push(i);
  return <div className="mt-3 flex flex-wrap items-center justify-center gap-2" dir="ltr"><button type="button" disabled={page<=1} onClick={()=>setPage(1)} className="rounded-lg border px-3 py-2 text-xs font-black disabled:opacity-40">«</button><button type="button" disabled={page<=1} onClick={()=>setPage(page-1)} className="rounded-lg border px-3 py-2 text-xs font-black disabled:opacity-40">‹</button>{pages.map(p=><button type="button" key={p} onClick={()=>setPage(p)} className={`min-w-9 rounded-lg border px-3 py-2 text-xs font-black ${p===page?"bg-[#0B2A4A] text-white":"bg-white"}`}>{p}</button>)}<button type="button" disabled={page>=totalPages} onClick={()=>setPage(page+1)} className="rounded-lg border px-3 py-2 text-xs font-black disabled:opacity-40">›</button><button type="button" disabled={page>=totalPages} onClick={()=>setPage(totalPages)} className="rounded-lg border px-3 py-2 text-xs font-black disabled:opacity-40">»</button></div>
}

export function TableScroll({children,hint=true}:{children:ReactNode;hint?:boolean}){
  return <><div className="max-w-full overflow-x-auto overscroll-x-contain rounded-xl border border-slate-100 [scrollbar-gutter:stable]">{children}</div>{hint&&<div className="mt-1 text-[11px] text-slate-400">يمكن تمرير الجدول أفقيًا عند الحاجة، وأزرار الإجراء تبقى ثابتة للوصول السريع.</div>}</>
}

export const stickyActionHead="sticky left-0 z-20 bg-slate-100 p-3 shadow-[-6px_0_10px_-10px_rgba(15,23,42,.7)]";
export const stickyActionCell="sticky left-0 z-10 bg-white p-3 shadow-[-6px_0_10px_-10px_rgba(15,23,42,.7)] group-hover:bg-slate-50";
