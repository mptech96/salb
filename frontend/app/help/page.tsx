"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { RESTART_TOUR_EVENT } from "@/components/experience/EnterpriseExperience";
import { filterNavigation, MANAGER_ROLES } from "@/components/navigation/access";
import { companyNavigation, platformNavigation, type NavigationGroup } from "@/components/navigation/menu";
import { accessibleHelpEntries } from "@/lib/help-content";
import { readSession } from "@/lib/session";

const QUICK_START = [
  ["مراجعة بيانات الشركة", "/settings"], ["مراجعة الفرع الرئيسي", "/branches"],
  ["إعداد الهوية والطباعة", "/settings/print-branding"], ["إضافة مستخدم", "/users"],
  ["مراجعة الصلاحيات", "/permissions-center"], ["إضافة مورد", "/suppliers"],
  ["إضافة عميل", "/customers"], ["إضافة صنف", "/items"],
  ["مراجعة السنة المالية", "/financial-years"], ["مراجعة الإعداد المالي", "/financial-setup"],
] as const;

export default function HelpCenterPage() {
  const [query, setQuery] = useState("");
  const [groups, setGroups] = useState<NavigationGroup[]>([]);
  const [platform, setPlatform] = useState(false);
  const [support, setSupport] = useState(false);
  useEffect(() => {
    const session = readSession(); if (!session) return;
    const role = String(session.user.role?.role_code || "").toUpperCase();
    const supportMode = session.user.is_support_mode === true;
    const platformAdmin = role === "SUPER_ADMIN" && !session.user.company_id && !supportMode;
    const features = ((session.subscription as { effective_entitlements?: { features?: Record<string, boolean> } } | null)?.effective_entitlements?.features) ?? {};
    setPlatform(platformAdmin); setSupport(supportMode);
    setGroups(platformAdmin ? platformNavigation : filterNavigation(companyNavigation, role, session.permissions, supportMode || MANAGER_ROLES.has(role), features));
  }, []);
  const entries = useMemo(() => accessibleHelpEntries(groups, platform).filter((entry) => !query.trim() || [entry.title, entry.category, entry.purpose, entry.when, ...entry.actions].join(" ").includes(query.trim())), [groups, platform, query]);
  const availablePaths = useMemo(() => new Set(groups.flatMap((group) => group.items.map((item) => item.href))), [groups]);
  const categories = useMemo(() => [...new Set(entries.map((entry) => entry.category))], [entries]);

  return <section dir="rtl" className="space-y-4">
    <header className="flex flex-col justify-between gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-end"><div><div className="text-[11px] text-slate-500">صلب ERP / المعرفة</div><h1 className="mt-1 text-xl font-bold text-slate-950">مركز المساعدة</h1><p className="mt-1 text-xs text-slate-500">إرشادات مختصرة مشتقة من الشاشات المتاحة لك حاليًا.</p></div><button type="button" onClick={() => window.dispatchEvent(new Event(RESTART_TOUR_EVENT))} className="enterprise-button enterprise-button-secondary">{support ? "تشغيل الجولة يدويًا" : "إعادة جولة التعريف"}</button></header>
    <label className="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 focus-within:border-sky-600 focus-within:ring-2 focus-within:ring-sky-100"><span aria-hidden="true">⌕</span><input value={query} onChange={(event) => setQuery(event.target.value)} className="min-w-0 flex-1 bg-transparent text-sm outline-none" placeholder="ابحث عن شاشة أو مهمة..." aria-label="البحث في مركز المساعدة" /></label>
    {!platform ? <section className="rounded-lg border border-slate-200 bg-white p-4"><div className="flex items-start justify-between gap-3"><div><h2 className="text-sm font-bold text-slate-900">ابدأ من هنا</h2><p className="mt-1 text-[11px] text-slate-500">قائمة تنقل للتجهيز؛ لا تعرض اكتمالًا تلقائيًا لأن APIs الحالية لا تثبت كل حالة بشكل موحد.</p></div><span className="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600">قائمة إرشادية</span></div><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{QUICK_START.filter(([, path]) => availablePaths.has(path) || [...availablePaths].some((allowed) => path.startsWith(`${allowed}/`))).map(([label, path], index) => <Link key={path} href={path} className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-sky-300 hover:bg-sky-50"><span className="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[9px]">{index + 1}</span>{label}</Link>)}</div></section> : null}
    {entries.length ? categories.map((category) => <section key={category} className="space-y-2"><h2 className="text-xs font-bold text-slate-700">{category}</h2><div className="grid gap-2 lg:grid-cols-2">{entries.filter((entry) => entry.category === category).map((entry) => <article key={entry.path} className="rounded-lg border border-slate-200 bg-white p-4"><div className="flex items-start justify-between gap-3"><div><h3 className="text-sm font-bold text-slate-900">{entry.title}</h3><p className="mt-1 text-xs leading-6 text-slate-600">{entry.purpose}</p></div><Link href={entry.path} className="shrink-0 rounded-md bg-sky-50 px-2 py-1 text-[10px] font-bold text-sky-800">فتح الشاشة</Link></div><div className="mt-3 border-t border-slate-100 pt-3 text-[11px] leading-6 text-slate-500"><strong className="text-slate-700">مهام شائعة:</strong> {entry.actions.join(" · ")}</div>{entry.caution ? <p className="mt-2 rounded-md bg-amber-50 px-2.5 py-2 text-[11px] text-amber-900">{entry.caution}</p> : null}</article>)}</div></section>) : <div className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">لا توجد نتيجة مطابقة ضمن الشاشات المتاحة لك.</div>}
  </section>;
}
