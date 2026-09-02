"use client";

export type PaginationMeta = {current_page:number;last_page:number;per_page:number;total:number;from?:number|null;to?:number|null};

export default function ServerPagination({meta,onPage,onPerPage}:{meta:PaginationMeta;onPage:(page:number)=>void;onPerPage:(size:number)=>void}) {
  return <div className="flex flex-col gap-3 border-t bg-slate-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between" dir="rtl">
    <div className="text-slate-600">{meta.total ? `${meta.from ?? 0}–${meta.to ?? 0} من ${meta.total}` : "لا توجد نتائج"}</div>
    <div className="flex items-center gap-2">
      <label className="flex items-center gap-2">لكل صفحة<select className="rounded-lg border bg-white px-2 py-1" value={meta.per_page} onChange={e=>onPerPage(Number(e.target.value))}>{[25,50,100].map(x=><option key={x}>{x}</option>)}</select></label>
      <button className="rounded-lg border bg-white px-3 py-1 disabled:opacity-40" disabled={meta.current_page<=1} onClick={()=>onPage(meta.current_page-1)}>السابق</button>
      <span className="tabular-nums">{meta.current_page} / {Math.max(meta.last_page,1)}</span>
      <button className="rounded-lg border bg-white px-3 py-1 disabled:opacity-40" disabled={meta.current_page>=meta.last_page} onClick={()=>onPage(meta.current_page+1)}>التالي</button>
    </div>
  </div>;
}
