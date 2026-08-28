"use client";
import { useEffect, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import { PageHeader } from "@/components/ui/enterprise";
import { AccountingContextBar, AccountingShortcut, FinancialMetric } from "@/components/design-system/AccountingWorkspace";
const m = (value: any) => Number(value || 0).toFixed(3);
export default function AccountingPage() {
  const [data, setData] = useState<any>(null); const [dialog, setDialog] = useState<any>({ open: false, title: "", message: "" });
  useEffect(() => { api.get("/accounting/overview").then((response) => setData(response.data.data)).catch((error) => setDialog({ open: true, title: "تعذر تحميل المحاسبة", message: error?.response?.data?.message || "حدث خطأ" })); }, []);
  const income = data?.income || {}, balance = data?.balance_sheet || {}, difference = Number(data?.trial_balance_difference || 0);
  return <section dir="rtl" className="space-y-4">
    <PageHeader title="المركز المحاسبي" description="بوابة قراءة وتنقل للبيانات الناتجة من القيود المرحلة؛ لا تنشئ هذه الصفحة أي أثر محاسبي." breadcrumbs={[{ label: "الرئيسية", href: "/" }, { label: "المحاسبة" }]} />
    <AccountingContextBar><span><b className="text-slate-900">النطاق:</b> الشركة والفرع من جلسة العمل الحالية</span><span><b className="text-slate-900">الفترة:</b> الفترة التي يعيدها تقرير المحاسبة الحالي</span><span><b className="text-slate-900">الدقة:</b> 3 منازل عشرية</span></AccountingContextBar>
    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6"><FinancialMetric label="الإيرادات" value={m(income.revenue_total)} /><FinancialMetric label="تكلفة الإيراد" value={m(income.cost_of_revenue_total)} /><FinancialMetric label="المصروفات" value={m(income.operating_expenses_total)} /><FinancialMetric label="صافي النتيجة" value={m(income.net_result)} tone={Number(income.net_result || 0) < 0 ? "danger" : "success"} /><FinancialMetric label="الأصول" value={m(balance.total_assets)} /><FinancialMetric label="الالتزامات + الحقوق" value={m(balance.total_liabilities_equity)} /></div>
    <section className="rounded-lg border border-slate-200 bg-white p-3"><h2 className="mb-3 text-xs font-bold text-slate-900">مساحات المحاسبة والتقارير</h2><div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4"><AccountingShortcut href="/journal-entries" title="القيود اليومية" description="عرض القيود اليدوية والآلية ومصادرها." /><AccountingShortcut href="/accounts" title="دليل الحسابات" description="الهيكل المحاسبي والحسابات المتاحة." /><AccountingShortcut href="/accounting-reports" title="ميزان المراجعة والقوائم" description="ميزان المراجعة والدخل والمركز المالي." /><AccountingShortcut href="/statements" title="دفتر الأستاذ وكشوف الحساب" description="الحسابات والعملاء والموردون والأرصدة المتحركة." /><AccountingShortcut href="/financial-years" title="السنوات المالية" description="عرض حالة السنة وإجراءات الإقفال الحالية." /><AccountingShortcut href="/opening-balances" title="الأرصدة الافتتاحية" description="مساحة الأرصدة الافتتاحية القائمة." /><AccountingShortcut href="/tax-reports" title="تقارير VAT" description="ضريبة المخرجات والمدخلات والمردودات." /><AccountingShortcut href="/accounting-integrity" title="سلامة المحاسبة" description="فحوصات الاتزان والتكامل المحاسبي." /></div></section>
    <div className={`rounded-lg border px-3 py-3 text-xs font-bold ${Math.abs(difference) < 0.001 ? "border-emerald-200 bg-emerald-50 text-emerald-800" : "border-rose-200 bg-rose-50 text-rose-800"}`}>فرق ميزان المراجعة: <span className="tabular-nums">{m(difference)}</span> {Math.abs(difference) < 0.001 ? "✓ متوازن" : "⚠ يحتاج مراجعة"}</div>
    <SystemDialog open={dialog.open} type="error" title={dialog.title} message={dialog.message} onClose={() => setDialog({ ...dialog, open: false })} onConfirm={() => setDialog({ ...dialog, open: false })} />
  </section>;
}
