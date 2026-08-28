"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import type { NavigationGroup } from "@/components/navigation/menu";
import { StatusBadge } from "@/components/ui/enterprise";

type SupportContext = {
  companyName: string;
  accessMode: string;
  ticket?: string | null;
  expiry?: string | null;
  onExit: () => void;
};

export default function EnterpriseTopbar({
  title,
  companyName,
  branchName,
  userName,
  roleName,
  isPlatformAdmin,
  groups,
  onOpenMenu,
  onHelp,
  support,
}: {
  title: string;
  companyName?: string | null;
  branchName?: string | null;
  userName?: string | null;
  roleName?: string | null;
  isPlatformAdmin: boolean;
  groups: NavigationGroup[];
  onOpenMenu: () => void;
  onHelp: () => void;
  support?: SupportContext;
}) {
  const [query, setQuery] = useState("");
  const results = useMemo(() => {
    const value = query.trim();
    if (value.length < 2) return [];
    return groups.flatMap((group) => group.items.map((item) => ({ ...item, group: group.label })))
      .filter((item) => item.label.includes(value) || item.group.includes(value))
      .slice(0, 6);
  }, [groups, query]);

  return (
    <header className="sticky top-0 z-30 border-b border-[var(--sulb-border)] bg-white/95 backdrop-blur">
      {support ? (
        <div className="flex min-h-9 flex-wrap items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] text-amber-950 sm:px-5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-bold">وضع الدعم</span><span>{support.companyName}</span>
            <StatusBadge tone={support.accessMode === "READ_ONLY" ? "warning" : "danger"}>{support.accessMode === "READ_ONLY" ? "قراءة فقط" : "قراءة وكتابة"}</StatusBadge>
            {support.ticket ? <span className="hidden sm:inline">المرجع: {support.ticket}</span> : null}
          </div>
          <button type="button" onClick={support.onExit} className="rounded border border-amber-300 bg-white px-2 py-1 font-semibold">إنهاء الدعم</button>
        </div>
      ) : null}

      <div className="flex h-14 items-center gap-3 px-3 sm:px-5">
        <button type="button" onClick={onOpenMenu} className="enterprise-icon-button lg:hidden" aria-label="فتح القائمة">☰</button>
        <div className="min-w-0 shrink-0" data-tour="page-context">
          <div className="truncate text-sm font-bold text-slate-900">{title}</div>
          <div className="truncate text-[10px] text-slate-500">{isPlatformAdmin ? "إدارة منصة صلب" : [companyName, branchName].filter(Boolean).join(" · ") || "بوابة الشركة"}</div>
        </div>

        <div className="relative mx-auto hidden w-full max-w-md md:block" data-tour="quick-search">
          <label className="flex h-9 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 focus-within:border-sky-600 focus-within:bg-white focus-within:ring-2 focus-within:ring-sky-100">
            <span className="text-slate-400">⌕</span>
            <input value={query} onChange={(event) => setQuery(event.target.value)} className="min-w-0 flex-1 bg-transparent text-xs outline-none placeholder:text-slate-400" placeholder="بحث سريع في وحدات النظام..." aria-label="بحث سريع" />
            <kbd className="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[9px] text-slate-400">⌘ K</kbd>
          </label>
          {query.trim().length >= 2 ? (
            <div className="absolute inset-x-0 top-11 overflow-hidden rounded-md border border-slate-200 bg-white shadow-xl">
              {results.length ? results.map((item) => (
                <Link key={item.href} href={item.href} onClick={() => setQuery("")} className="flex items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-xs last:border-0 hover:bg-slate-50">
                  <span className="font-medium text-slate-800">{item.label}</span><span className="text-[10px] text-slate-400">{item.group}</span>
                </Link>
              )) : <div className="px-3 py-4 text-center text-xs text-slate-500">لا توجد شاشة مطابقة ضمن صلاحياتك.</div>}
            </div>
          ) : null}
        </div>

        <button type="button" onClick={onHelp} data-tour="help" className="enterprise-icon-button shrink-0" aria-label="مساعدة الشاشة" title="مساعدة الشاشة">؟</button>
        <div className="flex min-w-0 items-center gap-2 border-r border-slate-200 pr-3">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--sulb-primary)] text-xs font-bold text-white">{(userName || "ص").slice(0, 1)}</div>
          <div className="hidden min-w-0 sm:block"><div className="max-w-32 truncate text-xs font-semibold text-slate-800">{userName}</div><div className="max-w-32 truncate text-[10px] text-slate-500">{roleName || (isPlatformAdmin ? "مدير المنصة" : "مستخدم الشركة")}</div></div>
        </div>
      </div>
    </header>
  );
}
