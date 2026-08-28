"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { accessibleHelpEntries, findHelpEntry } from "@/lib/help-content";
import type { NavigationGroup } from "@/components/navigation/menu";
import type { SessionUser } from "@/lib/session";

const ONBOARDING_VERSION = "phase8-v1";
export const OPEN_HELP_EVENT = "sulb-open-context-help";
export const RESTART_TOUR_EVENT = "sulb-restart-onboarding-tour";

type TourStep = { title: string; body: string; selector: string };

export default function EnterpriseExperience({ pathname, user, groups, isPlatformAdmin, isSupportMode }: { pathname: string; user: SessionUser; groups: NavigationGroup[]; isPlatformAdmin: boolean; isSupportMode: boolean }) {
  const [welcomeOpen, setWelcomeOpen] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  const [tourOpen, setTourOpen] = useState(false);
  const [stepIndex, setStepIndex] = useState(0);
  const [targetRect, setTargetRect] = useState<DOMRect | null>(null);
  const storageKey = `sulb:onboarding:${ONBOARDING_VERSION}:user:${user.id}`;
  const entries = useMemo(() => accessibleHelpEntries(groups, isPlatformAdmin), [groups, isPlatformAdmin]);
  const currentHelp = findHelpEntry(pathname);
  const availableModules = useMemo(() => groups.map((group) => group.label), [groups]);
  const steps = useMemo<TourStep[]>(() => {
    const base: TourStep[] = [
      { title: "القائمة الرئيسية", body: "تجمع الوحدات المتاحة لك فقط حسب دورك وصلاحياتك وباقة شركتك.", selector: "[data-tour='sidebar']" },
      { title: "سياق العمل", body: isPlatformAdmin ? "أنت الآن في بوابة إدارة المنصة، خارج سياق أي شركة." : "تحقق دائمًا من الشركة والفرع الحاليين قبل تنفيذ أي إجراء.", selector: "[data-tour='page-context']" },
      { title: "البحث السريع", body: "ابحث في الشاشات التي يحق لك الوصول إليها وانتقل إليها مباشرة.", selector: "[data-tour='quick-search']" },
    ];
    const platformSteps = [
      { title: "مركز إدارة المنصة", body: "من هنا تتابع مركز النظام والشركات والباقات والاشتراكات والفواتير والمدفوعات.", selector: "[data-tour-group='platform-overview']" },
      { title: "الرقابة ووضع الدعم", body: "راجع سجل عمليات المنصة، وادخل إلى الشركة فقط عبر Support Mode مصرح وواضح ومسجل.", selector: "[data-tour-group='platform-control']" },
    ].filter((step) => groups.some((group) => step.selector.includes(`'${group.id}'`)));
    const companySteps = groups.map((group) => ({ title: group.label, body: `من هنا تصل إلى ${group.items.map((item) => item.label).slice(0, 3).join("، ")}.`, selector: `[data-tour-group='${group.id}']` }));
    const groupSteps = isPlatformAdmin ? platformSteps : companySteps;
    return [...base, ...groupSteps, { title: "المساعدة", body: "افتح شرح الشاشة الحالية أو مركز المساعدة، ويمكنك إعادة هذه الجولة في أي وقت.", selector: "[data-tour='help']" }];
  }, [groups, isPlatformAdmin]);

  const complete = useCallback(() => {
    localStorage.setItem(storageKey, JSON.stringify({ completed: true, version: ONBOARDING_VERSION, completed_at: new Date().toISOString() }));
    setWelcomeOpen(false); setTourOpen(false); setStepIndex(0);
  }, [storageKey]);

  const startTour = useCallback(() => { setWelcomeOpen(false); setHelpOpen(false); setStepIndex(0); setTourOpen(true); }, []);

  useEffect(() => {
    if (isSupportMode) return;
    const timer = window.setTimeout(() => { if (!localStorage.getItem(storageKey)) setWelcomeOpen(true); }, 450);
    return () => window.clearTimeout(timer);
  }, [isSupportMode, storageKey]);

  useEffect(() => {
    const openHelp = () => setHelpOpen(true);
    const restart = () => startTour();
    window.addEventListener(OPEN_HELP_EVENT, openHelp);
    window.addEventListener(RESTART_TOUR_EVENT, restart);
    return () => { window.removeEventListener(OPEN_HELP_EVENT, openHelp); window.removeEventListener(RESTART_TOUR_EVENT, restart); };
  }, [startTour]);

  useEffect(() => {
    if (!tourOpen) { setTargetRect(null); return; }
    const update = () => setTargetRect(document.querySelector(steps[stepIndex]?.selector)?.getBoundingClientRect() ?? null);
    update(); window.addEventListener("resize", update); window.addEventListener("scroll", update, true);
    return () => { window.removeEventListener("resize", update); window.removeEventListener("scroll", update, true); };
  }, [stepIndex, steps, tourOpen]);

  useEffect(() => {
    if (!welcomeOpen && !helpOpen && !tourOpen) return;
    const keydown = (event: KeyboardEvent) => { if (event.key === "Escape") { setWelcomeOpen(false); setHelpOpen(false); setTourOpen(false); } };
    window.addEventListener("keydown", keydown); return () => window.removeEventListener("keydown", keydown);
  }, [helpOpen, tourOpen, welcomeOpen]);

  return <>
    {welcomeOpen ? <div className="fixed inset-0 z-[210] grid place-items-center bg-slate-950/55 p-4 backdrop-blur-sm" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sulb-welcome-title">
      <div className="w-full max-w-xl rounded-xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6">
        <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-[var(--sulb-primary)] text-lg font-black text-white">ص</div>
        <h2 id="sulb-welcome-title" className="mt-4 text-xl font-bold text-slate-950">مرحبًا بك في صلب</h2>
        <p className="mt-2 text-sm leading-7 text-slate-600">صلب منصة متكاملة لإدارة العمليات والمخزون والمبيعات والمشتريات والميزان والمحاسبة والموارد والأصول من مساحة عمل واحدة.</p>
        <dl className="mt-4 grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs sm:grid-cols-3"><Info label={isPlatformAdmin ? "النطاق" : "الشركة"} value={isPlatformAdmin ? "إدارة المنصة" : user.company_name || "—"} /><Info label="الفرع" value={isPlatformAdmin ? "خارج سياق الشركات" : user.branch_name || "جميع الفروع"} /><Info label="الدور" value={user.role?.role_name || "—"} /></dl>
        <div className="mt-4"><div className="text-[11px] font-semibold text-slate-500">الوحدات المتاحة</div><div className="mt-2 flex flex-wrap gap-1.5">{availableModules.map((module) => <span key={module} className="rounded-md bg-sky-50 px-2 py-1 text-[11px] font-semibold text-sky-800">{module}</span>)}</div></div>
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row"><button type="button" onClick={complete} className="enterprise-button enterprise-button-secondary flex-1">استكشف بنفسي</button><button type="button" onClick={startTour} className="enterprise-button enterprise-button-primary flex-1">ابدأ الجولة</button></div>
      </div>
    </div> : null}

    {tourOpen ? <div className="fixed inset-0 z-[220] pointer-events-none" dir="rtl" aria-live="polite">
      <div className="absolute inset-0 bg-slate-950/55" />
      {targetRect ? <div className="absolute rounded-lg ring-4 ring-sky-400 ring-offset-4 ring-offset-white/90 transition-[inset,width,height] motion-reduce:transition-none" style={{ top: Math.max(8, targetRect.top), left: Math.max(8, targetRect.left), width: Math.min(targetRect.width, window.innerWidth - 16), height: Math.min(targetRect.height, window.innerHeight - 16) }} /> : null}
      <div className="pointer-events-auto absolute inset-x-3 bottom-3 mx-auto w-auto max-w-lg rounded-xl border border-slate-200 bg-white p-4 shadow-2xl sm:bottom-6">
        <div className="flex items-center justify-between gap-3"><span className="text-[11px] font-bold text-sky-700">{stepIndex + 1} من {steps.length}</span><button type="button" onClick={complete} className="text-xs font-semibold text-slate-500 hover:text-slate-900">تخطي</button></div>
        <h2 className="mt-2 text-base font-bold text-slate-950">{steps[stepIndex]?.title}</h2><p className="mt-1 text-xs leading-6 text-slate-600">{steps[stepIndex]?.body}</p>
        <div className="mt-4 flex items-center gap-2"><button type="button" disabled={stepIndex === 0} onClick={() => setStepIndex((v) => Math.max(0, v - 1))} className="enterprise-button enterprise-button-secondary disabled:opacity-40">السابق</button><button type="button" onClick={() => stepIndex === steps.length - 1 ? complete() : setStepIndex((v) => v + 1)} className="enterprise-button enterprise-button-primary">{stepIndex === steps.length - 1 ? "إنهاء" : "التالي"}</button></div>
      </div>
    </div> : null}

    {helpOpen ? <div className="fixed inset-0 z-[215]" dir="rtl"><button type="button" aria-label="إغلاق المساعدة" onClick={() => setHelpOpen(false)} className="absolute inset-0 bg-slate-950/45" /><aside className="absolute inset-y-0 left-0 flex w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="context-help-title">
      <header className="flex items-start justify-between border-b border-slate-200 p-4"><div><div className="text-[10px] font-bold text-sky-700">مساعدة سياقية</div><h2 id="context-help-title" className="mt-1 text-lg font-bold text-slate-950">{currentHelp?.title || "مساعدة صلب"}</h2></div><button type="button" onClick={() => setHelpOpen(false)} className="enterprise-icon-button" aria-label="إغلاق">×</button></header>
      <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 text-sm leading-7 text-slate-600">{currentHelp ? <><HelpSection title="ما وظيفة الشاشة؟" text={currentHelp.purpose} /><HelpSection title="متى تستخدمها؟" text={currentHelp.when} /><div><h3 className="text-xs font-bold text-slate-900">أهم الإجراءات</h3><ul className="mt-1 list-inside list-disc text-xs">{currentHelp.actions.map((action) => <li key={action}>{action}</li>)}</ul></div>{currentHelp.outcome ? <HelpSection title="ماذا يحدث بعد الإجراء؟" text={currentHelp.outcome} /> : null}{currentHelp.caution ? <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><strong>تنبيه:</strong> {currentHelp.caution}</div> : null}</> : <p>افتح مركز المساعدة للوصول إلى الأدلة المتاحة ضمن صلاحياتك.</p>}</div>
      <footer className="grid gap-2 border-t border-slate-200 p-4 sm:grid-cols-2"><Link href="/help" onClick={() => setHelpOpen(false)} className="enterprise-button enterprise-button-primary">مركز المساعدة</Link>{!isSupportMode ? <button type="button" onClick={startTour} className="enterprise-button enterprise-button-secondary">إعادة الجولة</button> : null}</footer>
    </aside></div> : null}
  </>;
}

function Info({ label, value }: { label: string; value: string }) { return <div className="min-w-0"><dt className="text-[10px] text-slate-500">{label}</dt><dd className="mt-0.5 truncate font-semibold text-slate-800">{value}</dd></div>; }
function HelpSection({ title, text }: { title: string; text: string }) { return <div><h3 className="text-xs font-bold text-slate-900">{title}</h3><p className="mt-1 text-xs">{text}</p></div>; }
