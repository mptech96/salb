"use client";

import { useEffect, useState } from "react";
import api from "./api";
import { AccessState, EmptyState, LoadingState, PageHeader, StatCard, SurfaceCard, secondaryButtonClassName } from "@/components/ui/enterprise";
import { formatMoney, formatNumber, formatQuantity } from "@/lib/formatters";

type ActivityRecord = { id: number | string; customer_name?: string; supplier_name?: string; invoice_number?: string; voucher_number?: string; payment_method?: string; total_amount?: number | string; amount?: number | string };

export default function DashboardPage() {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function loadDashboard() {
    setLoading(true);
    setError(null);
    try {
      const response = await api.get("/dashboard");
      setData(response.data);
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || "تعذر تحميل ملخص لوحة التحكم.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void loadDashboard(); }, []);
  const precision = Number(data?.currency_decimal_places ?? data?.cards?.currency_decimal_places ?? 2);
  const cards = [
    { label: "إجمالي المبيعات", value: formatMoney(data?.cards?.sales, precision), href: "/sales" },
    { label: "إجمالي المشتريات", value: formatMoney(data?.cards?.purchases, precision), href: "/purchases" },
    { label: "إجمالي المصروفات", value: formatMoney(data?.cards?.expenses, precision), href: "/expenses" },
    { label: "صافي الربح", value: formatMoney(data?.cards?.profit, precision), tone: Number(data?.cards?.profit || 0) < 0 ? "negative" : "positive" },
    { label: "السيارات المفتوحة", value: formatNumber(data?.cards?.open_cars), href: "/cars" },
    { label: "كمية المخزون", value: formatQuantity(data?.cards?.stock_qty), href: "/inventory" },
  ] as const;

  return (
    <section dir="rtl" className="space-y-5">
      <PageHeader title="لوحة التحكم" description="ملخص تشغيلي للمبيعات والمشتريات والمصروفات وحركة المخزون." breadcrumbs={[{ label: "الرئيسية" }]} />
      {error && <AccessState title="تعذر تحميل لوحة التحكم" description={error} tone="danger" action={<button type="button" onClick={() => void loadDashboard()} className={secondaryButtonClassName}>إعادة المحاولة</button>} />}
      {loading && !data ? <SurfaceCard><LoadingState label="جاري تحميل ملخص الشركة..." /></SurfaceCard> : <>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {cards.map((card) => <StatCard key={card.label} label={card.label} value={card.value} href={"href" in card ? card.href : undefined} tone={"tone" in card ? card.tone : "neutral"} />)}
        </div>
        <div className="grid gap-4 xl:grid-cols-2">
          <ActivitySection title="آخر فواتير البيع" records={data?.latest_sales} precision={precision} type="sale" />
          <ActivitySection title="آخر فواتير الشراء" records={data?.latest_purchases} precision={precision} type="purchase" />
          <ActivitySection title="آخر السندات" records={data?.latest_vouchers} precision={precision} type="voucher" />
        </div>
      </>}
    </section>
  );
}

function ActivitySection({ title, records, precision, type }: { title: string; records?: ActivityRecord[]; precision: number; type: "sale" | "purchase" | "voucher" }) {
  return <SurfaceCard title={title}>{!records?.length ? <EmptyState title="لا توجد حركات حديثة" /> : <div className="divide-y divide-slate-100">{records.map(record => <div key={record.id} className="flex items-center justify-between gap-3 px-4 py-3"><div className="min-w-0"><p className="truncate text-sm font-medium text-slate-800">{type === "sale" ? record.customer_name || "—" : type === "purchase" ? record.supplier_name || "—" : record.voucher_number || record.id}</p><p className="mt-1 truncate text-xs text-slate-500">{type === "voucher" ? record.payment_method || "—" : record.invoice_number || "—"}</p></div><span className="sulb-numeric text-sm font-semibold text-slate-800">{formatMoney(type === "voucher" ? record.amount : record.total_amount, precision)}</span></div>)}</div>}</SurfaceCard>;
}
