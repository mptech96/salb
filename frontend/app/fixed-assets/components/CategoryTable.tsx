"use client";

import ERPEmpty from "@/components/erp/cards/ERPEmpty";
import ERPButton from "@/components/erp/buttons/ERPButton";

export type AssetCategory = {
  id: number;
  category_code: string;
  category_name: string;
  description?: string | null;
  depreciation_method:
    | "STRAIGHT_LINE"
    | "DECLINING_BALANCE"
    | "NO_DEPRECIATION";
  useful_life_months?: number | null;
  annual_depreciation_rate?: number | string | null;
  default_salvage_percentage?: number | string | null;
  is_active?: boolean | number;
  created_at?: string | null;
};

type Props = {
  rows: AssetCategory[];
  loading?: boolean;
  onEdit: (row: AssetCategory) => void;
};

export default function CategoryTable({
  rows,
  loading = false,
  onEdit,
}: Props) {
  if (loading) {
    return (
      <div className="flex min-h-52 flex-col items-center justify-center gap-3 rounded-3xl border border-dashed bg-slate-50">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />

        <div className="font-bold text-slate-500">
          جاري تحميل فئات الأصول...
        </div>
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <ERPEmpty
        title="لا توجد فئات أصول"
        text="لم يتم العثور على فئات مطابقة للبحث الحالي."
      />
    );
  }

  return (
    <div className="hidden overflow-x-auto lg:block">
      <table className="w-full min-w-[1100px] text-right">
        <thead className="bg-slate-100 text-slate-700">
          <tr>
            <th className="p-4">الكود</th>
            <th className="p-4">اسم الفئة</th>
            <th className="p-4">طريقة الإهلاك</th>
            <th className="p-4">العمر الإنتاجي</th>
            <th className="p-4">النسبة السنوية</th>
            <th className="p-4">القيمة المتبقية</th>
            <th className="p-4">الحالة</th>
            <th className="p-4">الإجراءات</th>
          </tr>
        </thead>

        <tbody>
          {rows.map((row) => (
            <tr
              key={row.id}
              className="border-t transition hover:bg-slate-50"
            >
              <td className="p-4 font-black text-[#0B2A4A]">
                {row.category_code}
              </td>

              <td className="p-4">
                <div className="font-black text-slate-800">
                  {row.category_name}
                </div>

                {row.description && (
                  <div className="mt-1 max-w-[280px] truncate text-sm font-semibold text-slate-500">
                    {row.description}
                  </div>
                )}
              </td>

              <td className="p-4">
                <DepreciationBadge
                  method={row.depreciation_method}
                />
              </td>

              <td className="p-4 font-bold">
                {row.depreciation_method === "NO_DEPRECIATION"
                  ? "-"
                  : row.useful_life_months
                  ? `${row.useful_life_months} شهر`
                  : "-"}
              </td>

              <td className="p-4 font-bold">
                {row.depreciation_method === "NO_DEPRECIATION"
                  ? "-"
                  : row.annual_depreciation_rate
                  ? `${Number(
                      row.annual_depreciation_rate
                    ).toLocaleString("ar-SA")} %`
                  : "-"}
              </td>

              <td className="p-4 font-bold">
                {row.default_salvage_percentage
                  ? `${Number(
                      row.default_salvage_percentage
                    ).toLocaleString("ar-SA")} %`
                  : "0 %"}
              </td>

              <td className="p-4">
                <StatusBadge
                  active={
                    row.is_active === true ||
                    Number(row.is_active) === 1
                  }
                />
              </td>

              <td className="p-4">
                <ERPButton
                  type="secondary"
                  onClick={() => onEdit(row)}
                >
                  تعديل
                </ERPButton>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DepreciationBadge({
  method,
}: {
  method: AssetCategory["depreciation_method"];
}) {
  const config = {
    STRAIGHT_LINE: {
      label: "القسط الثابت",
      className: "bg-blue-100 text-blue-800",
    },
    DECLINING_BALANCE: {
      label: "الرصيد المتناقص",
      className: "bg-purple-100 text-purple-800",
    },
    NO_DEPRECIATION: {
      label: "بدون إهلاك",
      className: "bg-slate-200 text-slate-700",
    },
  };

  const current = config[method] || config.STRAIGHT_LINE;

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${current.className}`}
    >
      {current.label}
    </span>
  );
}

function StatusBadge({ active }: { active: boolean }) {
  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${
        active
          ? "bg-emerald-100 text-emerald-800"
          : "bg-rose-100 text-rose-800"
      }`}
    >
      {active ? "نشطة" : "موقوفة"}
    </span>
  );
}