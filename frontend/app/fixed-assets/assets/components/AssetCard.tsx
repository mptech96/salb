"use client";

import ERPButton from "@/components/erp/buttons/ERPButton";
import type { FixedAssetRow } from "./AssetTable";
/*
|--------------------------------------------------------------------------
| نوع بيانات متوافق مع أشكال استجابة النظام
|--------------------------------------------------------------------------
*/

type CompatibleAssetRow = FixedAssetRow & {
  asset?: FixedAssetRow | null;
};

type Props = {
  /*
  |--------------------------------------------------------------------------
  | ندعم الاسمين row و asset
  |--------------------------------------------------------------------------
  */

  row?: CompatibleAssetRow | null;
  asset?: CompatibleAssetRow | null;

  onView: (row: FixedAssetRow) => void;
  onEdit: (row: FixedAssetRow) => void;
};

/*
|--------------------------------------------------------------------------
| بطاقة الأصل
|--------------------------------------------------------------------------
*/

export default function AssetCard({
  row,
  asset: assetProp,
  onView,
  onEdit,
}: Props) {
  /*
  |--------------------------------------------------------------------------
  | توحيد شكل البيانات
  |--------------------------------------------------------------------------
  |
  | الحالات المدعومة:
  |
  | row.asset_name
  | row.asset.asset_name
  | asset.asset_name
  | asset.asset.asset_name
  |
  */

  const source =
    row ??
    assetProp ??
    null;

  const asset: FixedAssetRow | null =
    source?.asset ??
    source ??
    null;

  /*
  |--------------------------------------------------------------------------
  | عدم رسم سجل فارغ
  |--------------------------------------------------------------------------
  */

  if (!asset) {
    return null;
  }

  return (
    <div className="rounded-3xl border border-slate-200 bg-slate-50 p-4">
      {/*
      |--------------------------------------------------------------------------
      | رأس البطاقة
      |--------------------------------------------------------------------------
      */}

      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="truncate font-black text-[#0B2A4A]">
            {asset.asset_name ||
              "أصل بدون اسم"}
          </div>

          <div className="mt-1 text-sm font-bold text-slate-500">
            {asset.asset_code || "-"}
          </div>
        </div>

        <AssetStatusBadge
          status={asset.asset_status}
        />
      </div>

      {/*
      |--------------------------------------------------------------------------
      | الفئة
      |--------------------------------------------------------------------------
      */}

      <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-3">
        <div className="text-xs font-bold text-slate-500">
          الفئة
        </div>

        <div className="mt-1 font-black text-slate-800">
          {asset.category
            ?.category_name || "-"}
        </div>
      </div>

      {/*
      |--------------------------------------------------------------------------
      | بيانات الأصل
      |--------------------------------------------------------------------------
      */}

      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <MiniValue
          title="تكلفة الشراء"
          value={money(
            asset.purchase_cost
          )}
        />

        <MiniValue
          title="القيمة الدفترية"
          value={money(
            asset.current_book_value
          )}
        />

        <MiniValue
          title="مجمع الإهلاك"
          value={money(
            asset.accumulated_depreciation
          )}
        />

        <MiniValue
          title="آخر إهلاك"
          value={formatDate(
            asset.last_depreciation_date
          )}
        />

        <MiniValue
          title="الفرع"
          value={
            asset.branch_name ||
            (
              asset.branch_id !== null &&
              asset.branch_id !==
                undefined
                ? String(
                    asset.branch_id
                  )
                : ""
            ) ||
            "الفرع الرئيسي"
          }
        />

        <MiniValue
          title="المسؤول"
          value={
            asset
              .responsible_worker_name ||
            "-"
          }
        />
      </div>

      {/*
      |--------------------------------------------------------------------------
      | الإجراءات
      |--------------------------------------------------------------------------
      */}

      <div className="mt-4 flex flex-wrap gap-2">
        <ERPButton
          type="secondary"
          onClick={() =>
            onView(asset)
          }
        >
          التفاصيل
        </ERPButton>

        <ERPButton
          type="secondary"
          onClick={() =>
            onEdit(asset)
          }
        >
          تعديل
        </ERPButton>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| قيمة مصغرة
|--------------------------------------------------------------------------
*/

function MiniValue({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-3">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-1 break-words font-black text-slate-800">
        {value}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| شارة حالة الأصل
|--------------------------------------------------------------------------
*/

function AssetStatusBadge({
  status,
}: {
  status:
    | FixedAssetRow["asset_status"]
    | null
    | undefined;
}) {
  const config = {
    ACTIVE: {
      label: "نشط",
      className:
        "bg-emerald-100 text-emerald-800",
    },

    UNDER_MAINTENANCE: {
      label: "تحت الصيانة",
      className:
        "bg-amber-100 text-amber-800",
    },

    SOLD: {
      label: "مباع",
      className:
        "bg-blue-100 text-blue-800",
    },

    DISPOSED: {
      label: "مشطوب",
      className:
        "bg-rose-100 text-rose-800",
    },
  } as const;

  const normalizedStatus:
    keyof typeof config =
    status &&
    Object.prototype.hasOwnProperty.call(
      config,
      status
    )
      ? (status as keyof typeof config)
      : "ACTIVE";

  const current =
    config[normalizedStatus];

  return (
    <span
      className={`shrink-0 rounded-full px-3 py-1 text-xs font-black ${current.className}`}
    >
      {current.label}
    </span>
  );
}

/*
|--------------------------------------------------------------------------
| تحويل القيمة إلى رقم
|--------------------------------------------------------------------------
*/

function toNumber(
  value: unknown
): number {
  const parsed = Number(
    value ?? 0
  );

  return Number.isFinite(parsed)
    ? parsed
    : 0;
}

/*
|--------------------------------------------------------------------------
| تنسيق المبالغ
|--------------------------------------------------------------------------
*/

function money(
  value: unknown
): string {
  return toNumber(
    value
  ).toLocaleString("ar-SA", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

/*
|--------------------------------------------------------------------------
| تنسيق التاريخ
|--------------------------------------------------------------------------
*/

function formatDate(
  value?: string | null
): string {
  if (!value) {
    return "-";
  }

  const date = new Date(value);

  if (
    Number.isNaN(date.getTime())
  ) {
    return String(value);
  }

  return date.toLocaleDateString(
    "ar-SA"
  );
}