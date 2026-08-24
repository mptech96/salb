"use client";

import ERPButton from "@/components/erp/buttons/ERPButton";
import type { AssetCategory } from "./CategoryTable";

type Props = {
  row: AssetCategory;
  onEdit: (row: AssetCategory) => void;
};

export default function CategoryCard({
  row,
  onEdit,
}: Props) {
  const active =
    row.is_active === true ||
    Number(row.is_active) === 1;

  return (
    <div className="rounded-3xl border bg-slate-50 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="font-black text-[#0B2A4A]">
            {row.category_name}
          </div>

          <div className="mt-1 text-sm font-bold text-slate-500">
            {row.category_code}
          </div>
        </div>

        <span
          className={`rounded-full px-3 py-1 text-xs font-black ${
            active
              ? "bg-emerald-100 text-emerald-800"
              : "bg-rose-100 text-rose-800"
          }`}
        >
          {active ? "نشطة" : "موقوفة"}
        </span>
      </div>

      {row.description && (
        <div className="mt-4 text-sm font-semibold leading-7 text-slate-600">
          {row.description}
        </div>
      )}

      <div className="mt-4 grid grid-cols-2 gap-2">
        <InfoItem
          title="طريقة الإهلاك"
          value={translateMethod(row.depreciation_method)}
        />

        <InfoItem
          title="العمر الإنتاجي"
          value={
            row.depreciation_method === "NO_DEPRECIATION"
              ? "-"
              : row.useful_life_months
              ? `${row.useful_life_months} شهر`
              : "-"
          }
        />

        <InfoItem
          title="النسبة السنوية"
          value={
            row.annual_depreciation_rate
              ? `${Number(
                  row.annual_depreciation_rate
                ).toLocaleString("ar-SA")} %`
              : "-"
          }
        />

        <InfoItem
          title="القيمة المتبقية"
          value={`${
            Number(
              row.default_salvage_percentage || 0
            ).toLocaleString("ar-SA")
          } %`}
        />
      </div>

      <div className="mt-4">
        <ERPButton
          type="secondary"
          onClick={() => onEdit(row)}
        >
          تعديل الفئة
        </ERPButton>
      </div>
    </div>
  );
}

function InfoItem({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border bg-white p-3">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-1 font-black text-slate-800">
        {value}
      </div>
    </div>
  );
}

function translateMethod(value: string): string {
  const map: Record<string, string> = {
    STRAIGHT_LINE: "القسط الثابت",
    DECLINING_BALANCE: "الرصيد المتناقص",
    NO_DEPRECIATION: "بدون إهلاك",
  };

  return map[value] || value;
}