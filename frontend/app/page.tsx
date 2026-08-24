"use client";

import { useEffect, useState } from "react";
import api from "./api";

export default function DashboardPage() {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(false);

  const money = (v: any) => Number(v || 0).toFixed(3);

  useEffect(() => {
    setLoading(true);
    api.get("/dashboard")
      .then((res) => setData(res.data))
      .finally(() => setLoading(false));
  }, []);

  const cards = [
    { title: "إجمالي المبيعات", value: data?.cards?.sales },
    { title: "إجمالي المشتريات", value: data?.cards?.purchases },
    { title: "إجمالي المصروفات", value: data?.cards?.expenses },
    { title: "صافي الربح", value: data?.cards?.profit },
    { title: "السيارات المفتوحة", value: data?.cards?.open_cars, count: true },
    { title: "كمية المخزون", value: data?.cards?.stock_qty },
  ];

  return (
    <section dir="rtl" className="space-y-6">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <p className="text-sm text-blue-100">لوحة التحكم</p>
        <h1 className="mt-2 text-4xl font-bold">نظام السكراب</h1>
        <p className="mt-2 text-blue-100">ملخص حي للمبيعات والمشتريات والمخزون والأرباح.</p>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {cards.map((card) => (
          <div key={card.title} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="text-sm font-bold text-slate-500">{card.title}</div>
            <div className="mt-3 text-3xl font-black text-[#0B2A4A]">
              {loading ? "..." : card.count ? Number(card.value || 0) : money(card.value)}
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <Box title="آخر فواتير البيع">
          {data?.latest_sales?.map((r: any) => (
            <Row key={r.id} title={r.customer_name || "-"} sub={r.invoice_number} amount={r.total_amount} />
          ))}
        </Box>

        <Box title="آخر فواتير الشراء">
          {data?.latest_purchases?.map((r: any) => (
            <Row key={r.id} title={r.supplier_name || "-"} sub={r.invoice_number} amount={r.total_amount} />
          ))}
        </Box>

        <Box title="آخر السندات">
          {data?.latest_vouchers?.map((r: any) => (
            <Row key={r.id} title={r.voucher_number || r.id} sub={r.payment_method} amount={r.amount} />
          ))}
        </Box>

        <Box title="أعلى السيارات مبيعًا">
          {data?.top_cars?.map((r: any, i: number) => (
            <Row key={i} title={r.car_number || "الحوش / عام"} sub="إجمالي بيع" amount={r.total_sales} />
          ))}
        </Box>
      </div>
    </section>
  );
}

function Box({ title, children }: any) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="mb-4 text-xl font-bold text-[#0B2A4A]">{title}</h2>
      <div className="space-y-3">{children || <div className="text-slate-500">لا توجد بيانات</div>}</div>
    </div>
  );
}

function Row({ title, sub, amount }: any) {
  return (
    <div className="flex items-center justify-between rounded-2xl bg-slate-50 p-4">
      <div>
        <div className="font-bold text-slate-800">{title}</div>
        <div className="text-sm text-slate-500">{sub || "-"}</div>
      </div>
      <div className="font-black text-[#0B2A4A]">{Number(amount || 0).toFixed(3)}</div>
    </div>
  );
}