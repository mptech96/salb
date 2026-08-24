"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import { useRouter } from "next/navigation";

import AssetCard from "./components/AssetCard";

import type { FixedAssetRow } from "./components/AssetTable";

import {
  getAssets,
  type AssetListFilters,
} from "../workspace/services/fixedAssets";

/*
|--------------------------------------------------------------------------
| أنواع الصفحة
|--------------------------------------------------------------------------
*/

type MessageState = {
  type: "error" | "success" | "info";
  text: string;
} | null;

type DialogMode =
  | "view"
  | "edit"
  | null;

/*
|--------------------------------------------------------------------------
| صفحة الإدارة التفصيلية للأصول
|--------------------------------------------------------------------------
*/

export default function FixedAssetsPage() {
  const router = useRouter();

  /*
  |--------------------------------------------------------------------------
  | بيانات الأصول
  |--------------------------------------------------------------------------
  */

  const [assets, setAssets] =
    useState<FixedAssetRow[]>([]);

  const [loading, setLoading] =
    useState(true);

  const [refreshing, setRefreshing] =
    useState(false);

  const [message, setMessage] =
    useState<MessageState>(null);

  /*
  |--------------------------------------------------------------------------
  | الفلاتر
  |--------------------------------------------------------------------------
  */

  const [search, setSearch] =
    useState("");

  const [status, setStatus] =
    useState("");

  const [branchId, setBranchId] =
    useState("");

  /*
  |--------------------------------------------------------------------------
  | الأصل المحدد
  |--------------------------------------------------------------------------
  */

  const [
    selectedAsset,
    setSelectedAsset,
  ] =
    useState<FixedAssetRow | null>(
      null
    );

  const [
    dialogMode,
    setDialogMode,
  ] = useState<DialogMode>(null);

  /*
  |--------------------------------------------------------------------------
  | تحميل بيانات الأصول
  |--------------------------------------------------------------------------
  */

  const loadAssets = useCallback(
    async (
      showRefreshing = false
    ) => {
      if (showRefreshing) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }

      setMessage(null);

      try {
        const filters: AssetListFilters =
          {
            search:
              search.trim() ||
              undefined,

            asset_status:
              status || undefined,

            branch_id:
              branchId || undefined,

            per_page: 100,
          };

        const response =
          await getAssets(filters);

        const rows =
          extractAssetRows(response);

        setAssets(rows);
      } catch (error: unknown) {
        setAssets([]);

        setMessage({
          type: "error",
          text: getApiError(
            error,
            "تعذر تحميل بيانات الأصول الثابتة."
          ),
        });
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [
      search,
      status,
      branchId,
    ]
  );

  useEffect(() => {
    loadAssets();
  }, [loadAssets]);

  /*
  |--------------------------------------------------------------------------
  | تصفية إضافية داخل الواجهة
  |--------------------------------------------------------------------------
  */

  const filteredAssets =
    useMemo(() => {
      const normalizedSearch =
        search
          .trim()
          .toLowerCase();

      return assets.filter(
        (asset) => {
          if (!asset) {
            return false;
          }

          const matchesSearch =
            !normalizedSearch ||
            [
              asset.asset_code,
              asset.asset_name,
              asset.description,
              asset.category
                ?.category_name,
              asset.location,
              asset.branch_name,
              asset
                .responsible_worker_name,
            ]
              .filter(Boolean)
              .join(" ")
              .toLowerCase()
              .includes(
                normalizedSearch
              );

          const matchesStatus =
            !status ||
            asset.asset_status ===
              status;

          const matchesBranch =
            !branchId ||
            Number(
              asset.branch_id
            ) === Number(branchId);

          return (
            matchesSearch &&
            matchesStatus &&
            matchesBranch
          );
        }
      );
    }, [
      assets,
      search,
      status,
      branchId,
    ]);

  /*
  |--------------------------------------------------------------------------
  | إحصائيات الصفحة
  |--------------------------------------------------------------------------
  */

  const stats = useMemo(() => {
    return {
      total: assets.length,

      active: assets.filter(
        (asset) =>
          asset.asset_status ===
          "ACTIVE"
      ).length,

      maintenance: assets.filter(
        (asset) =>
          asset.asset_status ===
          "UNDER_MAINTENANCE"
      ).length,

      sold: assets.filter(
        (asset) =>
          asset.asset_status ===
          "SOLD"
      ).length,

      disposed: assets.filter(
        (asset) =>
          asset.asset_status ===
          "DISPOSED"
      ).length,

      totalBookValue:
        assets.reduce(
          (total, asset) =>
            total +
            toNumber(
              asset.current_book_value
            ),
          0
        ),
    };
  }, [assets]);

  /*
  |--------------------------------------------------------------------------
  | فتح التفاصيل
  |--------------------------------------------------------------------------
  */

  function openViewDialog(
    asset: FixedAssetRow
  ) {
    setSelectedAsset(asset);
    setDialogMode("view");
  }

  /*
  |--------------------------------------------------------------------------
  | فتح التعديل
  |--------------------------------------------------------------------------
  */

  function openEditDialog(
    asset: FixedAssetRow
  ) {
    setSelectedAsset(asset);
    setDialogMode("edit");
  }

  /*
  |--------------------------------------------------------------------------
  | إغلاق النافذة
  |--------------------------------------------------------------------------
  */

  function closeDialog() {
    setDialogMode(null);
    setSelectedAsset(null);
  }

  /*
  |--------------------------------------------------------------------------
  | واجهة الصفحة
  |--------------------------------------------------------------------------
  */

  return (
    <main
      dir="rtl"
      className="min-h-screen bg-slate-50"
    >
      <div className="mx-auto max-w-[1700px] space-y-5">
        {/*
        |--------------------------------------------------------------------------
        | رأس الصفحة
        |--------------------------------------------------------------------------
        */}

        <header className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
          <div className="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div>
              <div className="mb-2 text-sm font-black text-emerald-700">
                SULB ERP
              </div>

              <h1 className="text-2xl font-black text-[#0B2A4A] sm:text-3xl">
                الإدارة التفصيلية
                للأصول الثابتة
              </h1>

              <p className="mt-3 max-w-3xl text-sm font-semibold leading-7 text-slate-500">
                عرض الأصول الثابتة
                والبحث فيها ومتابعة
                تكلفتها وقيمتها
                الدفترية وحالتها
                والفرع والمسؤول عنها.
              </p>
            </div>

            <div className="flex flex-wrap gap-2">
              <PageButton
                secondary
                onClick={() =>
                  router.push(
                    "/fixed-assets/workspace"
                  )
                }
              >
                العودة إلى مساحة
                الأصول
              </PageButton>

              <PageButton
                secondary
                disabled={refreshing}
                onClick={() =>
                  loadAssets(true)
                }
              >
                {refreshing
                  ? "جاري التحديث..."
                  : "تحديث البيانات"}
              </PageButton>

              <PageButton
                onClick={() => {
                  setSelectedAsset(
                    null
                  );

                  setDialogMode(
                    "edit"
                  );
                }}
              >
                + تسجيل أصل جديد
              </PageButton>
            </div>
          </div>
        </header>

        {/*
        |--------------------------------------------------------------------------
        | الرسائل
        |--------------------------------------------------------------------------
        */}

        {message && (
          <MessageBox
            type={message.type}
            text={message.text}
            onClose={() =>
              setMessage(null)
            }
          />
        )}

        {/*
        |--------------------------------------------------------------------------
        | الإحصائيات
        |--------------------------------------------------------------------------
        */}

        <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
          <StatCard
            title="إجمالي الأصول"
            value={formatNumber(
              stats.total
            )}
          />

          <StatCard
            title="الأصول النشطة"
            value={formatNumber(
              stats.active
            )}
          />

          <StatCard
            title="تحت الصيانة"
            value={formatNumber(
              stats.maintenance
            )}
          />

          <StatCard
            title="الأصول المباعة"
            value={formatNumber(
              stats.sold
            )}
          />

          <StatCard
            title="الأصول المشطوبة"
            value={formatNumber(
              stats.disposed
            )}
          />

          <StatCard
            title="القيمة الدفترية"
            value={money(
              stats.totalBookValue
            )}
          />
        </section>

        {/*
        |--------------------------------------------------------------------------
        | الفلاتر
        |--------------------------------------------------------------------------
        */}

        <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <FieldWrapper label="البحث">
              <input
                type="text"
                value={search}
                onChange={(event) =>
                  setSearch(
                    event.target.value
                  )
                }
                placeholder="اسم الأصل أو الكود أو الفئة أو الموقع..."
                className={
                  inputClassName
                }
              />
            </FieldWrapper>

            <FieldWrapper label="حالة الأصل">
              <select
                value={status}
                onChange={(event) =>
                  setStatus(
                    event.target.value
                  )
                }
                className={
                  inputClassName
                }
              >
                <option value="">
                  كل الحالات
                </option>

                <option value="ACTIVE">
                  نشط
                </option>

                <option value="UNDER_MAINTENANCE">
                  تحت الصيانة
                </option>

                <option value="SOLD">
                  مباع
                </option>

                <option value="DISPOSED">
                  مشطوب
                </option>
              </select>
            </FieldWrapper>

            <FieldWrapper label="رقم الفرع">
              <input
                type="number"
                value={branchId}
                onChange={(event) =>
                  setBranchId(
                    event.target.value
                  )
                }
                placeholder="كل الفروع"
                className={
                  inputClassName
                }
              />
            </FieldWrapper>
          </div>
        </section>

        {/*
        |--------------------------------------------------------------------------
        | سجل الأصول
        |--------------------------------------------------------------------------
        */}

        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-xl font-black text-[#0B2A4A]">
                سجل الأصول
              </h2>

              <p className="mt-1 text-sm font-semibold text-slate-500">
                عدد النتائج:{" "}
                {formatNumber(
                  filteredAssets.length
                )}
              </p>
            </div>

            {(search ||
              status ||
              branchId) && (
              <PageButton
                secondary
                onClick={() => {
                  setSearch("");
                  setStatus("");
                  setBranchId("");
                }}
              >
                مسح الفلاتر
              </PageButton>
            )}
          </div>

          {loading ? (
            <LoadingState />
          ) : filteredAssets.length ===
            0 ? (
            <EmptyState
              onRefresh={() =>
                loadAssets(true)
              }
            />
          ) : (
            <div className="grid grid-cols-1 gap-4 p-5 md:grid-cols-2 2xl:grid-cols-3">
              {filteredAssets.map(
                (asset, index) => {
                  if (!asset) {
                    return null;
                  }

                  return (
                    <AssetCard
                      key={
                        asset.id ??
                        `${asset.asset_code}-${index}`
                      }
                      row={asset}
                      onView={
                        openViewDialog
                      }
                      onEdit={
                        openEditDialog
                      }
                    />
                  );
                }
              )}
            </div>
          )}
        </section>
      </div>

      {/*
      |--------------------------------------------------------------------------
      | نافذة التفاصيل
      |--------------------------------------------------------------------------
      */}

      {dialogMode === "view" &&
        selectedAsset && (
          <AssetDetailsModal
            asset={selectedAsset}
            onClose={closeDialog}
            onEdit={() =>
              setDialogMode("edit")
            }
          />
        )}

      {/*
      |--------------------------------------------------------------------------
      | نافذة التعديل أو الإضافة
      |--------------------------------------------------------------------------
      */}

      {dialogMode === "edit" && (
        <AssetEditNoticeModal
          asset={selectedAsset}
          onClose={closeDialog}
        />
      )}
    </main>
  );
}

/*
|--------------------------------------------------------------------------
| استخراج صفوف الأصول مهما كان شكل استجابة API
|--------------------------------------------------------------------------
*/

function extractAssetRows(
  response: unknown
): FixedAssetRow[] {
  if (
    !response ||
    typeof response !== "object"
  ) {
    return [];
  }

  const source = response as Record<
    string,
    unknown
  >;

  let rawRows: unknown =
    source.data ??
    source.assets ??
    source.items ??
    [];

  if (
    rawRows &&
    typeof rawRows === "object" &&
    !Array.isArray(rawRows)
  ) {
    const nested = rawRows as Record<
      string,
      unknown
    >;

    rawRows =
      nested.data ??
      nested.assets ??
      nested.items ??
      [];
  }

  if (!Array.isArray(rawRows)) {
    return [];
  }

  const result: FixedAssetRow[] = [];

  rawRows.forEach((item: unknown) => {
    const normalized =
      normalizeAssetRow(item);

    if (normalized) {
      result.push(normalized);
    }
  });

  return result;
}

/*
|--------------------------------------------------------------------------
| توحيد سجل الأصل
|--------------------------------------------------------------------------
*/

function normalizeAssetRow(
  item: unknown
): FixedAssetRow | null {
  if (
    !item ||
    typeof item !== "object"
  ) {
    return null;
  }

  const record =
    item as Record<
      string,
      unknown
    >;

  const nestedAsset =
    record.asset &&
    typeof record.asset ===
      "object"
      ? (record.asset as Record<
          string,
          unknown
        >)
      : null;

  const source =
    nestedAsset ?? record;

  if (
    !source.id &&
    !source.asset_code &&
    !source.asset_name
  ) {
    return null;
  }

  return source as unknown as FixedAssetRow;
}

/*
|--------------------------------------------------------------------------
| نافذة عرض التفاصيل
|--------------------------------------------------------------------------
*/

function AssetDetailsModal({
  asset,
  onClose,
  onEdit,
}: {
  asset: FixedAssetRow;
  onClose: () => void;
  onEdit: () => void;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
      <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
          <div>
            <h2 className="text-xl font-black text-[#0B2A4A]">
              {asset.asset_name ||
                "تفاصيل الأصل"}
            </h2>

            <div className="mt-1 text-sm font-bold text-slate-500">
              {asset.asset_code ||
                "-"}
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-xl font-black text-slate-700 hover:bg-slate-200"
          >
            ×
          </button>
        </div>

        <div className="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2">
          <DetailValue
            title="الفئة"
            value={
              asset.category
                ?.category_name ||
              "-"
            }
          />

          <DetailValue
            title="الحالة"
            value={assetStatusLabel(
              asset.asset_status
            )}
          />

          <DetailValue
            title="تكلفة الشراء"
            value={money(
              asset.purchase_cost
            )}
          />

          <DetailValue
            title="القيمة المتبقية"
            value={money(
              asset.salvage_value
            )}
          />

          <DetailValue
            title="مجمع الإهلاك"
            value={money(
              asset
                .accumulated_depreciation
            )}
          />

          <DetailValue
            title="القيمة الدفترية"
            value={money(
              asset.current_book_value
            )}
          />

          <DetailValue
            title="تاريخ الشراء"
            value={formatDate(
              asset.purchase_date
            )}
          />

          <DetailValue
            title="آخر إهلاك"
            value={formatDate(
              asset
                .last_depreciation_date
            )}
          />

          <DetailValue
            title="الفرع"
            value={
              asset.branch_name ||
              String(
                asset.branch_id ||
                  "-"
              )
            }
          />

          <DetailValue
            title="الموقع"
            value={
              asset.location || "-"
            }
          />

          <DetailValue
            title="المسؤول"
            value={
              asset
                .responsible_worker_name ||
              "-"
            }
          />

          <DetailValue
            title="طريقة الإهلاك"
            value={
              asset
                .depreciation_method ||
              "-"
            }
          />
        </div>

        {asset.description && (
          <div className="mx-5 mb-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div className="text-xs font-bold text-slate-500">
              الوصف
            </div>

            <div className="mt-2 whitespace-pre-wrap font-semibold leading-7 text-slate-800">
              {asset.description}
            </div>
          </div>
        )}

        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 p-5">
          <PageButton
            secondary
            onClick={onClose}
          >
            إغلاق
          </PageButton>

          <PageButton
            onClick={onEdit}
          >
            تعديل الأصل
          </PageButton>
        </div>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| نافذة الإضافة والتعديل المؤقتة
|--------------------------------------------------------------------------
*/

function AssetEditNoticeModal({
  asset,
  onClose,
}: {
  asset: FixedAssetRow | null;
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
      <div className="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 p-5">
          <h2 className="text-xl font-black text-[#0B2A4A]">
            {asset
              ? "تعديل الأصل"
              : "تسجيل أصل جديد"}
          </h2>

          <button
            type="button"
            onClick={onClose}
            className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-xl font-black text-slate-700"
          >
            ×
          </button>
        </div>

        <div className="p-5">
          <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 font-semibold leading-7 text-blue-900">
            شاشة السجل أصبحت سليمة
            وتستقبل بيانات الأصول
            مباشرة. سيتم ربط نموذج
            الإضافة والتعديل الموجود
            لديك في ملف
            AssetDialog.tsx في الخطوة
            التالية، دون تغيير تحميل
            البيانات أو تصميم الصفحة.
          </div>
        </div>

        <div className="flex justify-end border-t border-slate-200 p-5">
          <PageButton
            secondary
            onClick={onClose}
          >
            إغلاق
          </PageButton>
        </div>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| بطاقة إحصائية
|--------------------------------------------------------------------------
*/

function StatCard({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 text-center shadow-sm">
      <div className="text-2xl font-black text-[#0B2A4A]">
        {value}
      </div>

      <div className="mt-1 text-xs font-bold text-slate-500">
        {title}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| قيمة داخل التفاصيل
|--------------------------------------------------------------------------
*/

function DetailValue({
  title,
  value,
}: {
  title: string;
  value: string;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>

      <div className="mt-2 break-words font-black text-slate-800">
        {value}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| غلاف الحقل
|--------------------------------------------------------------------------
*/

function FieldWrapper({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-black text-slate-700">
        {label}
      </span>

      {children}
    </label>
  );
}

/*
|--------------------------------------------------------------------------
| زر الصفحة
|--------------------------------------------------------------------------
*/

function PageButton({
  children,
  onClick,
  secondary = false,
  disabled = false,
}: {
  children: React.ReactNode;
  onClick: () => void;
  secondary?: boolean;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex min-h-11 items-center justify-center rounded-2xl px-5 text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-50 ${
        secondary
          ? "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"
          : "bg-[#0B2A4A] text-white hover:bg-[#123D68]"
      }`}
    >
      {children}
    </button>
  );
}

/*
|--------------------------------------------------------------------------
| حالة التحميل
|--------------------------------------------------------------------------
*/

function LoadingState() {
  return (
    <div className="flex min-h-72 flex-col items-center justify-center gap-4">
      <div className="h-11 w-11 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />

      <div className="font-bold text-slate-500">
        جاري تحميل الأصول
        الثابتة...
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| حالة عدم وجود بيانات
|--------------------------------------------------------------------------
*/

function EmptyState({
  onRefresh,
}: {
  onRefresh: () => void;
}) {
  return (
    <div className="flex min-h-72 flex-col items-center justify-center p-6 text-center">
      <div className="text-xl font-black text-[#0B2A4A]">
        لا توجد أصول ثابتة
      </div>

      <p className="mt-3 max-w-md text-sm font-semibold leading-7 text-slate-500">
        لا توجد بيانات مطابقة
        للفلاتر الحالية، أو لم يتم
        تسجيل أصول بعد.
      </p>

      <div className="mt-5">
        <PageButton
          secondary
          onClick={onRefresh}
        >
          إعادة تحميل البيانات
        </PageButton>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| صندوق الرسائل
|--------------------------------------------------------------------------
*/

function MessageBox({
  type,
  text,
  onClose,
}: {
  type:
    | "error"
    | "success"
    | "info";

  text: string;
  onClose: () => void;
}) {
  const classes = {
    error:
      "border-rose-200 bg-rose-50 text-rose-900",

    success:
      "border-emerald-200 bg-emerald-50 text-emerald-900",

    info:
      "border-blue-200 bg-blue-50 text-blue-900",
  };

  return (
    <div
      className={`flex items-start justify-between gap-4 rounded-2xl border p-4 font-bold ${classes[type]}`}
    >
      <div>{text}</div>

      <button
        type="button"
        onClick={onClose}
        className="font-black"
      >
        ×
      </button>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| اسم حالة الأصل
|--------------------------------------------------------------------------
*/

function assetStatusLabel(
  status:
    | FixedAssetRow["asset_status"]
    | null
    | undefined
): string {
  const labels: Record<
    string,
    string
  > = {
    ACTIVE: "نشط",
    UNDER_MAINTENANCE:
      "تحت الصيانة",
    SOLD: "مباع",
    DISPOSED: "مشطوب",
  };

  return status
    ? labels[status] ||
        String(status)
    : "-";
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
| تنسيق العدد
|--------------------------------------------------------------------------
*/

function formatNumber(
  value: unknown
): string {
  return toNumber(
    value
  ).toLocaleString("ar-SA");
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

/*
|--------------------------------------------------------------------------
| استخراج رسالة خطأ API
|--------------------------------------------------------------------------
*/

function getApiError(
  error: unknown,
  fallback: string
): string {
  if (
    error &&
    typeof error === "object" &&
    "response" in error
  ) {
    const response = (
      error as {
        response?: {
          data?: {
            message?: unknown;
          };
        };
      }
    ).response;

    if (
      typeof response?.data
        ?.message === "string"
    ) {
      return response.data.message;
    }
  }

  return fallback;
}

/*
|--------------------------------------------------------------------------
| تصميم الحقول
|--------------------------------------------------------------------------
*/

const inputClassName =
  "h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100";