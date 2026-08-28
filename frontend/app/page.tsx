"use client";

import Link from "next/link";
import { useEffect, useState, type ReactNode } from "react";
import api from "./api";
import { AccessState, EmptyState, LoadingState, secondaryButtonClassName } from "@/components/ui/enterprise";
import { formatMoney, formatNumber, formatQuantity } from "@/lib/formatters";

type ActivityRecord = { id: number | string; customer_name?: string; supplier_name?: string; invoice_number?: string; invoice_date?: string; voucher_number?: string; payment_method?: string; total_amount?: number | string; amount?: number | string };
type DashboardData = { scope?: { scope_type?: string }; cards?: { sales?: number | string; purchases?: number | string; expenses?: number | string; profit?: number | string; open_cars?: number | string; stock_qty?: number | string; currency_decimal_places?: number }; currency_decimal_places?: number; latest_sales?: ActivityRecord[]; latest_purchases?: ActivityRecord[]; latest_vouchers?: ActivityRecord[]; top_cars?: Array<{ car_number?: string | null; total_sales?: number | string }> };

export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  async function loadDashboard() { setLoading(true); setError(null); try { const response = await api.get("/dashboard"); setData(response.data); } catch (requestError: any) { setError(requestError?.response?.data?.message || "تعذر تحميل ملخص لوحة التحكم."); } finally { setLoading(false); } }
  useEffect(() => { void loadDashboard(); }, []);

  if (loading && !data) return <DashboardSkeleton />;
  if (error && !data) return <AccessState title="تعذر تحميل مساحة العمل" description={error} tone="danger" action={<button type="button" onClick={() => void loadDashboard()} className={secondaryButtonClassName}>إعادة المحاولة</button>} />;

  const precision = Number(data?.currency_decimal_places ?? data?.cards?.currency_decimal_places ?? 2);
  const openCars = Number(data?.cards?.open_cars || 0);
  const kpis = [
    { label: "المبيعات", value: formatMoney(data?.cards?.sales, precision), href: "/sales" },
    { label: "المشتريات", value: formatMoney(data?.cards?.purchases, precision), href: "/purchases" },
    { label: "المصروفات", value: formatMoney(data?.cards?.expenses, precision), href: "/expenses" },
    { label: "صافي النتيجة", value: formatMoney(data?.cards?.profit, precision), tone: Number(data?.cards?.profit || 0) < 0 ? "danger" : "success" },
    { label: "السيارات المفتوحة", value: formatNumber(openCars), href: "/weighing", tone: openCars > 0 ? "warning" : "default" },
    { label: "كمية المخزون", value: formatQuantity(data?.cards?.stock_qty), href: "/inventory" },
  ];

  return <section dir="rtl" className="enterprise-dashboard space-y-3">
    <div className="flex flex-col justify-between gap-3 border-b border-slate-200 pb-3 sm:flex-row sm:items-center">
      <div><div className="flex items-center gap-2 text-[11px] text-slate-500"><span>الرئيسية</span><span>/</span><span>مساحة عمل الشركة</span></div><h1 className="mt-1 text-xl font-bold tracking-tight text-slate-950">مساحة العمل</h1><p className="mt-0.5 text-xs text-slate-500">مؤشرات التشغيل والعمليات الحديثة ضمن نطاق {data?.scope?.scope_type === "BRANCH" ? "الفرع الحالي" : "الشركة"}.</p></div>
      <div className="flex flex-wrap gap-2"><Link href="/weighing" className="enterprise-button enterprise-button-secondary">محطة الميزان</Link><Link href="/shipments" className="enterprise-button enterprise-button-primary">الشحنات</Link></div>
    </div>
    {error ? <AccessState title="تعذر تحديث بعض البيانات" description={error} tone="warning" action={<button type="button" onClick={() => void loadDashboard()} className={secondaryButtonClassName}>إعادة المحاولة</button>} /> : null}
    <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">{kpis.map((kpi) => <CompactKpi key={kpi.label} {...kpi} />)}</div>
    <div className="grid gap-3 xl:grid-cols-[minmax(0,1.55fr)_minmax(290px,.75fr)]">
      <EnterprisePanel title="بحاجة إلى إجراء" description="مهام تشغيلية ظاهرة من بيانات لوحة التحكم الحالية." action={<Link href="/weighing" className="text-[11px] font-semibold text-sky-800 hover:underline">فتح محطة الميزان</Link>}>
        <div className="divide-y divide-slate-100"><ActionRow label="السيارات المفتوحة" description={openCars > 0 ? "راجع الأوزان والحالة التشغيلية للسيارات المفتوحة." : "لا توجد سيارات مفتوحة حاليًا."} value={formatNumber(openCars)} href="/weighing" tone={openCars > 0 ? "warning" : "success"} /><ActionRow label="آخر مستندات البيع" description="السجلات الأحدث المتاحة للمراجعة في لوحة التحكم." value={formatNumber(data?.latest_sales?.length || 0)} href="/sales" /><ActionRow label="آخر مستندات الشراء" description="السجلات الأحدث المتاحة للمراجعة في لوحة التحكم." value={formatNumber(data?.latest_purchases?.length || 0)} href="/purchases" /></div>
      </EnterprisePanel>
      <EnterprisePanel title="التشغيل والمخزون" description="ملخص مباشر دون حسابات إضافية في الواجهة.">
        <div className="grid grid-cols-2 divide-x divide-x-reverse divide-slate-100"><OperationalValue label="السيارات المفتوحة" value={formatNumber(openCars)} href="/weighing" /><OperationalValue label="رصيد الحركة" value={formatQuantity(data?.cards?.stock_qty)} href="/inventory" /></div>
        <div className="border-t border-slate-100 px-3 py-2.5"><div className="mb-2 text-[11px] font-semibold text-slate-600">أعلى السيارات حسب المبيعات</div>{!data?.top_cars?.length ? <p className="text-[11px] text-slate-400">لا توجد بيانات متاحة.</p> : <div className="space-y-2">{data.top_cars.slice(0, 3).map((car, index) => <div key={`${car.car_number}-${index}`} className="flex items-center justify-between gap-3 text-[11px]"><span className="truncate text-slate-600">{car.car_number || "بدون سيارة"}</span><span className="sulb-numeric font-semibold text-slate-800">{formatMoney(car.total_sales, precision)}</span></div>)}</div>}</div>
      </EnterprisePanel>
    </div>
    <div className="grid gap-3 xl:grid-cols-2"><RecentTable title="آخر المبيعات" href="/sales" records={data?.latest_sales} type="sale" precision={precision} /><RecentTable title="آخر المشتريات" href="/purchases" records={data?.latest_purchases} type="purchase" precision={precision} /></div>
    <EnterprisePanel title="آخر السندات" action={<Link href="/vouchers" className="text-[11px] font-semibold text-sky-800 hover:underline">عرض جميع السندات</Link>}><RecentRows records={data?.latest_vouchers} type="voucher" precision={precision} /></EnterprisePanel>
  </section>;
}

function CompactKpi({ label, value, href, tone = "default" }: { label: string; value: string; href?: string; tone?: string }) { const colors: Record<string, string> = { default: "text-slate-950", success: "text-emerald-700", danger: "text-rose-700", warning: "text-amber-700" }; const content = <><div className="text-[10px] font-medium text-slate-500">{label}</div><div dir="ltr" className={`mt-1 truncate text-right text-base font-bold tabular-nums ${colors[tone]}`}>{value}</div></>; const classes = "min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-[0_1px_2px_rgba(15,23,42,.03)] transition hover:border-slate-300"; return href ? <Link href={href} className={classes}>{content}</Link> : <div className={classes}>{content}</div>; }
function EnterprisePanel({ title, description, action, children }: { title: string; description?: string; action?: ReactNode; children: ReactNode }) { return <section className="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,.03)]"><div className="flex min-h-11 items-center justify-between gap-3 border-b border-slate-100 px-3 py-2"><div className="min-w-0"><h2 className="text-xs font-bold text-slate-900">{title}</h2>{description ? <p className="mt-0.5 truncate text-[10px] text-slate-500">{description}</p> : null}</div>{action}</div>{children}</section>; }
function ActionRow({ label, description, value, href, tone = "default" }: { label: string; description: string; value: string; href: string; tone?: "default" | "warning" | "success" }) { const dot = tone === "warning" ? "bg-amber-500" : tone === "success" ? "bg-emerald-500" : "bg-sky-600"; return <Link href={href} className="flex items-center gap-3 px-3 py-2.5 transition hover:bg-slate-50"><span className={`h-2 w-2 shrink-0 rounded-full ${dot}`} /><div className="min-w-0 flex-1"><div className="text-xs font-semibold text-slate-800">{label}</div><div className="mt-0.5 truncate text-[10px] text-slate-500">{description}</div></div><span className="sulb-numeric min-w-8 text-left text-sm font-bold text-slate-800">{value}</span><span className="text-slate-300">‹</span></Link>; }
function OperationalValue({ label, value, href }: { label: string; value: string; href: string }) { return <Link href={href} className="min-w-0 px-3 py-3 transition hover:bg-slate-50"><div className="text-[10px] text-slate-500">{label}</div><div dir="ltr" className="mt-1 truncate text-right text-lg font-bold tabular-nums text-slate-900">{value}</div></Link>; }
function RecentTable({ title, href, records, type, precision }: { title: string; href: string; records?: ActivityRecord[]; type: "sale" | "purchase"; precision: number }) { return <EnterprisePanel title={title} action={<Link href={href} className="text-[11px] font-semibold text-sky-800 hover:underline">عرض الكل</Link>}><RecentRows records={records} type={type} precision={precision} /></EnterprisePanel>; }
function RecentRows({ records, type, precision }: { records?: ActivityRecord[]; type: "sale" | "purchase" | "voucher"; precision: number }) { if (!records?.length) return <EmptyState title="لا توجد حركات حديثة" />; return <div className="max-w-full overflow-x-auto"><table className="enterprise-table min-w-[520px]"><thead><tr><th>المرجع</th><th>{type === "sale" ? "العميل" : type === "purchase" ? "المورد" : "طريقة الدفع"}</th><th>التاريخ</th><th className="text-left">القيمة</th></tr></thead><tbody>{records.map((record) => <tr key={record.id}><td className="font-semibold text-slate-800">{type === "voucher" ? record.voucher_number || `#${record.id}` : record.invoice_number || `#${record.id}`}</td><td>{type === "sale" ? record.customer_name || "—" : type === "purchase" ? record.supplier_name || "—" : record.payment_method || "—"}</td><td>{record.invoice_date || "—"}</td><td dir="ltr" className="text-left font-semibold tabular-nums text-slate-800">{formatMoney(type === "voucher" ? record.amount : record.total_amount, precision)}</td></tr>)}</tbody></table></div>; }
function DashboardSkeleton() { return <section className="space-y-3" dir="rtl"><div className="h-16 animate-pulse rounded-lg bg-slate-200" /><div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">{Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-16 animate-pulse rounded-lg bg-slate-200" />)}</div><div className="rounded-lg border border-slate-200 bg-white"><LoadingState label="جاري تجهيز مساحة العمل..." /></div></section>; }
