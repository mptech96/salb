"use client";

import { useEffect, useState } from "react";

const POLICIES = [
  { id: "terms", title: "الشروط والأحكام", summary: "مسودة هيكلية — يلزم اعتماد قانوني لنطاق الخدمة والالتزامات والمسؤوليات." },
  { id: "privacy", title: "سياسة الخصوصية", summary: "مسودة هيكلية — يلزم تحديد مسؤول المعالجة وفئات البيانات ومدد الاحتفاظ وحقوق أصحاب البيانات." },
  { id: "billing", title: "سياسة الاشتراك والفوترة", summary: "مسودة هيكلية — يلزم اعتماد دورات الفوترة والضرائب والاستحقاق والتجديد." },
  { id: "acceptable-use", title: "سياسة الاستخدام المقبول", summary: "مسودة هيكلية — يلزم اعتماد الاستخدامات المحظورة وآلية التعامل مع المخالفات." },
  { id: "refund", title: "سياسة الإلغاء والاسترداد", summary: "مسودة هيكلية — يلزم اعتماد حالات الإلغاء والاسترداد والمهل النظامية." },
  { id: "disclaimer", title: "إخلاء المسؤولية", summary: "مسودة هيكلية — يلزم اعتماد حدود المسؤولية والتنبيهات الخاصة بالقرارات المحاسبية والتشغيلية." },
  { id: "entity", title: "معلومات المنشأة", summary: "غير مكتملة — لم توجد في المشروع هوية قانونية موثقة لمالك منصة صلب يمكن نشرها هنا." },
];

export default function LegalCenterPage() {
  const [active, setActive] = useState(POLICIES[0].id);
  useEffect(() => {
    const requested = window.location.hash.slice(1);
    if (POLICIES.some((item) => item.id === requested)) setActive(requested);
  }, []);
  const policy = POLICIES.find((item) => item.id === active)!;
  return <section dir="rtl" className="space-y-4"><header className="border-b border-slate-200 pb-4"><div className="text-[11px] text-slate-500">صلب ERP / الحوكمة</div><h1 className="mt-1 text-xl font-bold text-slate-950">المركز القانوني والسياسات</h1><p className="mt-1 text-xs text-slate-500">هيكل مراجعة غير منشور كنص قانوني نهائي. جميع الأقسام الموسومة «مسودة» تحتاج اعتمادًا قانونيًا.</p></header><div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-6 text-amber-950"><strong>تنبيه مهم:</strong> لا تمثل هذه الصفحة شروطًا قانونية نهائية. لم تُضف التزامات أو وعود أو سياسات غير موجودة في المشروع.</div><div className="grid min-w-0 gap-3 lg:grid-cols-[240px_minmax(0,1fr)]"><nav className="flex gap-2 overflow-x-auto rounded-lg border border-slate-200 bg-white p-2 lg:block lg:space-y-1" aria-label="أقسام السياسات">{POLICIES.map((item) => <button key={item.id} type="button" onClick={() => { setActive(item.id); history.replaceState(null, "", `#${item.id}`); }} className={`shrink-0 rounded-md px-3 py-2 text-right text-xs font-semibold lg:w-full ${active === item.id ? "bg-[var(--sulb-primary)] text-white" : "text-slate-600 hover:bg-slate-50"}`}>{item.title}</button>)}</nav><article id={policy.id} className="min-h-72 rounded-lg border border-slate-200 bg-white p-5"><div className="flex flex-wrap items-center justify-between gap-2"><h2 className="text-lg font-bold text-slate-950">{policy.title}</h2><span className="rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold text-amber-800">DRAFT · غير معتمد</span></div><p className="mt-4 text-sm leading-7 text-slate-600">{policy.summary}</p><div className="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4"><h3 className="text-xs font-bold text-slate-800">المطلوب قبل النشر</h3><ul className="mt-2 list-inside list-disc space-y-1 text-xs leading-6 text-slate-600"><li>مراجعة وصياغة مستشار قانوني ضمن نطاق الدول التي تقدم فيها الخدمة.</li><li>تحديد الاسم القانوني والسجل والرقم الضريبي والعنوان والبريد الرسمي والهاتف والنطاق لمالك المنصة.</li><li>اعتماد تاريخ السريان وإدارة الإصدارات وآلية قبول المستخدم.</li></ul></div></article></div></section>;
}
