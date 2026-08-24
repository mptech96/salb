"use client";

import ERPEmpty from "@/components/erp/cards/ERPEmpty";
import ERPButton from "@/components/erp/buttons/ERPButton";

export type FixedAssetRow = {
  id: number;
  asset_code: string;
  asset_name: string;
  description?: string | null;

  category_id: number;
  category?: {
    id: number;
    category_code?: string;
    category_name?: string;
  } | null;

  branch_id?: number | null;
  branch_name?: string | null;

  location?: string | null;
  responsible_worker_id?: number | null;
  responsible_worker_name?: string | null;

  purchase_date?: string | null;
  purchase_cost: number | string;
  current_book_value: number | string;
  accumulated_depreciation: number | string;
  salvage_value: number | string;

  depreciation_method:
    | "STRAIGHT_LINE"
    | "DECLINING_BALANCE"
    | "NO_DEPRECIATION";

  last_depreciation_date?: string | null;

  asset_status:
    | "ACTIVE"
    | "UNDER_MAINTENANCE"
    | "SOLD"
    | "DISPOSED";

  is_active?: boolean | number;
};

type Props = {
  rows: FixedAssetRow[];
  loading?: boolean;

  onView: (row: FixedAssetRow) => void;
  onEdit: (row: FixedAssetRow) => void;
};

export default function AssetTable({
  rows,
  loading = false,
  onView,
  onEdit,
}: Props) {
  if (loading) {
    return (
      <div className="flex min-h-56 flex-col items-center justify-center gap-3 rounded-3xl border border-dashed bg-slate-50">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />

        <div className="font-bold text-slate-500">
          جاري تحميل الأصول الثابتة...
        </div>
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <ERPEmpty
        title="لا توجد أصول ثابتة"
        text="لم يتم العثور على أصول مطابقة للبحث الحالي."
      />
    );
  }

  return (
    <div className="hidden overflow-x-auto xl:block">
      <table className="w-full min-w-[1500px] text-right">
        <thead className="bg-slate-100 text-slate-700">
          <tr>
            <th className="p-4">كود الأصل</th>
            <th className="p-4">اسم الأصل</th>
            <th className="p-4">الفئة</th>
            <th className="p-4">الفرع / الموقع</th>
            <th className="p-4">المسؤول</th>
            <th className="p-4">تكلفة الشراء</th>
            <th className="p-4">مجمع الإهلاك</th>
            <th className="p-4">القيمة الدفترية</th>
            <th className="p-4">آخر إهلاك</th>
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
                {row.asset_code}
              </td>

              <td className="p-4">
                <div className="font-black text-slate-800">
                  {row.asset_name}
                </div>

                {row.description && (
                  <div className="mt-1 max-w-[260px] truncate text-sm font-semibold text-slate-500">
                    {row.description}
                  </div>
                )}
              </td>

              <td className="p-4">
                <div className="font-bold text-slate-800">
                  {row.category?.category_name || "-"}
                </div>

                <div className="mt-1 text-xs font-semibold text-slate-500">
                  {row.category?.category_code || ""}
                </div>
              </td>

              <td className="p-4">
                <div className="font-bold">
                  {row.branch_name || "الفرع الرئيسي"}
                </div>

                <div className="mt-1 text-sm text-slate-500">
                  {row.location || "-"}
                </div>
              </td>

              <td className="p-4 font-bold">
                {row.responsible_worker_name || "-"}
              </td>

              <td className="p-4 font-black">
                {money(row.purchase_cost)}
              </td>

              <td className="p-4 font-bold text-rose-700">
                {money(row.accumulated_depreciation)}
              </td>

              <td className="p-4 font-black text-emerald-700">
                {money(row.current_book_value)}
              </td>

              <td className="p-4">
                {formatDate(row.last_depreciation_date)}
              </td>

              <td className="p-4">
                <AssetStatusBadge status={row.asset_status} />
              </td>

              <td className="p-4">
                <div className="flex flex-wrap gap-2">
                  <ERPButton
                    type="secondary"
                    onClick={() => onView(row)}
                  >
                    التفاصيل
                  </ERPButton>

                  <ERPButton
                    type="secondary"
                    onClick={() => onEdit(row)}
                  >
                    تعديل
                  </ERPButton>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AssetStatusBadge({
  status,
}: {
  status: FixedAssetRow["asset_status"];
}) {
  const config = {
    ACTIVE: {
      label: "نشط",
      className: "bg-emerald-100 text-emerald-800",
    },
    UNDER_MAINTENANCE: {
      label: "تحت الصيانة",
      className: "bg-amber-100 text-amber-800",
    },
    SOLD: {
      label: "مباع",
      className: "bg-blue-100 text-blue-800",
    },
    DISPOSED: {
      label: "مشطوب",
      className: "bg-rose-100 text-rose-800",
    },
  };

  const current = config[status] || config.ACTIVE;

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${current.className}`}
    >
      {current.label}
    </span>
  );
}

function number(value: any): number {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function money(value: any): string {
  return number(value).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

function formatDate(value?: string | null): string {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleDateString("ar-SA");
}