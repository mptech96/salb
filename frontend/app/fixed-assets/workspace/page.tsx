"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import { useRouter } from "next/navigation";
import { LifecycleStrip, WorkspaceNotice } from "@/components/design-system/LifecycleWorkspace";

import ActionMenu from "./components/ActionMenu";
import DepreciationDialog from "./components/DepreciationDialog";
import MaintenanceDialog from "./components/MaintenanceDialog";
import TransferDialog from "./components/TransferDialog";
import SellDialog from "./components/SellDialog";
import DisposeDialog from "./components/DisposeDialog";
import ReportsDrawer from "./components/ReportsDrawer";

import type {
  AssetStatus,
  FixedAsset,
} from "./types";

import {
  getAssets,
  getSummaryReport,
  type AssetListFilters,
  type AssetSummaryReport,
} from "./services/fixedAssets";

/*
|--------------------------------------------------------------------------
| أنواع الصفحة
|--------------------------------------------------------------------------
*/

type MessageState = {
  type:
    | "success"
    | "error"
    | "warning"
    | "info";

  text: string;
} | null;

/*
|--------------------------------------------------------------------------
| القيم الافتراضية
|--------------------------------------------------------------------------
*/

const emptySummary: AssetSummaryReport = {
  total_assets: 0,
  active_assets: 0,
  under_maintenance_assets: 0,
  sold_assets: 0,
  disposed_assets: 0,
  purchase_cost_total: 0,
  accumulated_depreciation_total: 0,
  book_value_total: 0,
  salvage_value_total: 0,
};

/*
|--------------------------------------------------------------------------
| الصفحة الرئيسية
|--------------------------------------------------------------------------
*/

export default function FixedAssetsWorkspacePage() {
  const router = useRouter();

  /*
  |--------------------------------------------------------------------------
  | بيانات الأصول والملخص
  |--------------------------------------------------------------------------
  */

  const [assets, setAssets] =
    useState<FixedAsset[]>([]);

  const [summary, setSummary] =
    useState<AssetSummaryReport>(
      emptySummary
    );

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
  | حالات التحميل والرسائل
  |--------------------------------------------------------------------------
  */

  const [loading, setLoading] =
    useState(true);

  const [refreshing, setRefreshing] =
    useState(false);

  const [message, setMessage] =
    useState<MessageState>(null);

  /*
  |--------------------------------------------------------------------------
  | نافذة تشغيل الإهلاك
  |--------------------------------------------------------------------------
  */

  const [
    showDepreciationDialog,
    setShowDepreciationDialog,
  ] = useState(false);

  /*
  |--------------------------------------------------------------------------
  | نافذة نقل الأصل
  |--------------------------------------------------------------------------
  */

  const [
    selectedTransferAsset,
    setSelectedTransferAsset,
  ] = useState<FixedAsset | null>(null);

  const [
    showTransferDialog,
    setShowTransferDialog,
  ] = useState(false);

  /*
  |--------------------------------------------------------------------------
  | نافذة صيانة الأصل
  |--------------------------------------------------------------------------
  */

  const [
    selectedMaintenanceAsset,
    setSelectedMaintenanceAsset,
  ] = useState<FixedAsset | null>(null);

  const [
    showMaintenanceDialog,
    setShowMaintenanceDialog,
  ] = useState(false);

  /*
|--------------------------------------------------------------------------
| نافذة بيع الأصل
|--------------------------------------------------------------------------
*/

const [
  selectedSellAsset,
  setSelectedSellAsset,
] = useState<FixedAsset | null>(null);

const [
  showSellDialog,
  setShowSellDialog,
] = useState(false);

/*
|--------------------------------------------------------------------------
| نافذة شطب الأصل
|--------------------------------------------------------------------------
*/

const [
  selectedDisposeAsset,
  setSelectedDisposeAsset,
] = useState<FixedAsset | null>(null);

const [
  showDisposeDialog,
  setShowDisposeDialog,
] = useState(false);

/*
|--------------------------------------------------------------------------
| نافذة التقارير
|--------------------------------------------------------------------------
*/

const [
    showReportsDrawer,
    setShowReportsDrawer,
] = useState(false);
  /*
  |--------------------------------------------------------------------------
  | تحميل البيانات
  |--------------------------------------------------------------------------
  */

  const loadData = useCallback(
    async (showRefresh = false) => {
      if (showRefresh) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }

      setMessage(null);

      try {
        const filters: AssetListFilters = {
          search:
            search.trim() || undefined,

          asset_status:
            status || undefined,

          branch_id:
            branchId || undefined,

          per_page: 100,
        };

        const [
          assetsResult,
          summaryResult,
        ] = await Promise.all([
          getAssets(filters),

          getSummaryReport({
            branch_id:
              branchId || undefined,
          }),
        ]);

        setAssets(
          Array.isArray(
            assetsResult.data
          )
            ? assetsResult.data
            : []
        );

        setSummary(
          summaryResult ||
            emptySummary
        );
      } catch (error: unknown) {
        setAssets([]);
        setSummary(emptySummary);

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
    loadData();
  }, [loadData]);

  /*
  |--------------------------------------------------------------------------
  | تصفية البيانات في الواجهة
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
  | فتح وإغلاق نافذة النقل
  |--------------------------------------------------------------------------
  */

  function openTransferDialog(
    asset: FixedAsset
  ) {
    setSelectedTransferAsset(asset);
    setShowTransferDialog(true);
  }

  function closeTransferDialog() {
    setShowTransferDialog(false);
    setSelectedTransferAsset(null);
  }

  /*
  |--------------------------------------------------------------------------
  | فتح وإغلاق نافذة الصيانة
  |--------------------------------------------------------------------------
  */

  function openMaintenanceDialog(
    asset: FixedAsset
  ) {
    setSelectedMaintenanceAsset(
      asset
    );

    setShowMaintenanceDialog(true);
  }

  function closeMaintenanceDialog() {
    setShowMaintenanceDialog(false);

    setSelectedMaintenanceAsset(
      null
    );
  }
  /*
|--------------------------------------------------------------------------
| فتح وإغلاق نافذة البيع
|--------------------------------------------------------------------------
*/

function openSellDialog(
  asset: FixedAsset
) {
  setSelectedSellAsset(asset);
  setShowSellDialog(true);
}

function closeSellDialog() {
  setShowSellDialog(false);
  setSelectedSellAsset(null);
}

/*
|--------------------------------------------------------------------------
| فتح وإغلاق نافذة شطب الأصل
|--------------------------------------------------------------------------
*/

function openDisposeDialog(
  asset: FixedAsset
) {
  setSelectedDisposeAsset(asset);
  setShowDisposeDialog(true);
}

function closeDisposeDialog() {
  setShowDisposeDialog(false);
  setSelectedDisposeAsset(null);
}

  /*
  |--------------------------------------------------------------------------
  | واجهة الصفحة
  |--------------------------------------------------------------------------
  */

  return (
    <main
      dir="rtl"
      className="min-h-screen bg-slate-50 p-4 sm:p-6"
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
                مساحة إدارة الأصول
                الثابتة
              </h1>

              <p className="mt-3 max-w-3xl text-sm font-semibold leading-7 text-slate-500">
                متابعة الأصول وقيمتها
                الدفترية والإهلاك
                والصيانة والنقل والبيع
                والشطب والتقارير من
                شاشة موحدة.
              </p>
            </div>

            <div className="flex flex-wrap gap-2">
              <ActionButton
                secondary
                onClick={() =>
                  router.push(
                    "/fixed-assets/categories"
                  )
                }
              >
                فئات الأصول
              </ActionButton>

              <ActionButton
                secondary
                onClick={() =>
                  router.push(
                    "/fixed-assets/assets"
                  )
                }
              >
                سجل الأصول
              </ActionButton>

              <ActionButton
                secondary
                disabled={refreshing}
                onClick={() =>
                  loadData(true)
                }
              >
                {refreshing
                  ? "جاري التحديث..."
                  : "تحديث البيانات"}
              </ActionButton>

              <ActionButton
                secondary
                onClick={() =>
                  setShowDepreciationDialog(
                    true
                  )
                }
              >
                تشغيل الإهلاك
              </ActionButton>

               <ActionButton
    secondary
    onClick={() =>
        setShowReportsDrawer(true)
    }
>
    التقارير
</ActionButton>
              <ActionButton
                onClick={() =>
                  router.push(
                    "/fixed-assets/assets"
                  )
                }
              >
                + تسجيل أصل جديد
              </ActionButton>
            </div>
          </div>
        </header>

        <LifecycleStrip title="دورة الأصل التشغيلية والمالية" steps={[{label:"سجل الأصل"},{label:"القيمة الدفترية"},{label:"الإهلاك"},{label:"الصيانة / النقل"},{label:"البيع / الشطب"},{label:"القيد والتاريخ"}]}/>
        <WorkspaceNotice tone="warning">القيم الدفترية ومجمع الإهلاك ونتائج الحركات مصدرها الخادم. استخدم المراجعة قبل الإهلاك أو البيع أو الشطب، ولا تعتمد على تقدير واجهة المستخدم كقيد محاسبي.</WorkspaceNotice>

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
        | بطاقات الملخص المالي
        |--------------------------------------------------------------------------
        */}

        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard
            title="إجمالي الأصول"
            value={formatNumber(
              summary.total_assets
            )}
            subtitle={`نشط: ${formatNumber(
              summary.active_assets
            )}`}
          />

          <SummaryCard
            title="تكلفة شراء الأصول"
            value={formatMoney(
              summary.purchase_cost_total
            )}
            subtitle="إجمالي التكلفة التاريخية"
          />

          <SummaryCard
            title="مجمع الإهلاك"
            value={formatMoney(
              summary
                .accumulated_depreciation_total
            )}
            subtitle="إجمالي الإهلاك المرحّل"
          />

          <SummaryCard
            title="القيمة الدفترية"
            value={formatMoney(
              summary.book_value_total
            )}
            subtitle={`القيمة المتبقية: ${formatMoney(
              summary
                .salvage_value_total
            )}`}
          />
        </section>

        {/*
        |--------------------------------------------------------------------------
        | حالات الأصول
        |--------------------------------------------------------------------------
        */}

        <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <StatusCounter
            title="الأصول النشطة"
            value={
              summary.active_assets
            }
          />

          <StatusCounter
            title="تحت الصيانة"
            value={
              summary
                .under_maintenance_assets
            }
          />

          <StatusCounter
            title="الأصول المباعة"
            value={
              summary.sold_assets
            }
          />

          <StatusCounter
            title="الأصول المشطوبة"
            value={
              summary.disposed_assets
            }
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
                placeholder="الكود أو الاسم أو الفئة أو الموقع..."
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
        | جدول الأصول
        |--------------------------------------------------------------------------
        */}

        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-xl font-black text-[#0B2A4A]">
                سجل الأصول الثابتة
              </h2>

              <p className="mt-1 text-sm font-semibold text-slate-500">
                عدد النتائج:{" "}
                {formatNumber(
                  filteredAssets.length
                )}
              </p>
            </div>

            <ActionButton
              secondary
              onClick={() =>
                router.push(
                  "/fixed-assets/assets"
                )
              }
            >
              فتح الإدارة التفصيلية
            </ActionButton>
          </div>

          {loading ? (
            <LoadingState />
          ) : filteredAssets.length ===
            0 ? (
            <EmptyState
              onCreate={() =>
                router.push(
                  "/fixed-assets/assets"
                )
              }
            />
          ) : (
            <>
              <AssetsDesktopTable
    assets={filteredAssets}
    onView={(asset)=>
        router.push(`/fixed-assets/assets?asset=${asset.id}`)
    }

    onTransfer={openTransferDialog}

    onMaintenance={openMaintenanceDialog}

    onSell={openSellDialog}
    onDispose={openDisposeDialog}
/>

              <AssetsMobileList
              assets={filteredAssets}

              onView={(asset)=>
              router.push(`/fixed-assets/assets?asset=${asset.id}`)
              }

              onTransfer={openTransferDialog}

             onMaintenance={openMaintenanceDialog}

             onSell={openSellDialog}
             onDispose={openDisposeDialog}
             />
            </>
           )}
        </section>
      </div>

      {/*
      |--------------------------------------------------------------------------
      | نافذة تشغيل الإهلاك
      |--------------------------------------------------------------------------
      */}

      <DepreciationDialog
        open={
          showDepreciationDialog
        }
        defaultBranchId={
          branchId || null
        }
        onClose={() =>
          setShowDepreciationDialog(
            false
          )
        }
        onCompleted={() => {
          loadData(true);
        }}
      />

      {/*
      |--------------------------------------------------------------------------
      | نافذة نقل الأصل
      |--------------------------------------------------------------------------
      */}

      <TransferDialog
        open={showTransferDialog}
        asset={
          selectedTransferAsset
        }
        onClose={
          closeTransferDialog
        }
        onCompleted={() => {
          loadData(true);
        }}
      />

      {/*
      |--------------------------------------------------------------------------
      | نافذة صيانة الأصل
      |--------------------------------------------------------------------------
      */}

      <MaintenanceDialog
        open={
          showMaintenanceDialog
        }
        asset={
          selectedMaintenanceAsset
        }
        onClose={
          closeMaintenanceDialog
        }
        onCompleted={() => {
          loadData(true);
        }}
      />

      <SellDialog
        open={showSellDialog}
        asset={selectedSellAsset}
        onClose={closeSellDialog}
        onCompleted={() => {
          loadData(true);
        }}
      />

      {/*
      |--------------------------------------------------------------------------
      | نافذة شطب الأصل
      |--------------------------------------------------------------------------
      */}

      <DisposeDialog
        open={showDisposeDialog}
        asset={selectedDisposeAsset}
        onClose={closeDisposeDialog}
        onCompleted={() => {
          loadData(true);
        }}
      />
      <ReportsDrawer
    open={showReportsDrawer}
    defaultBranchId={
        branchId || null
    }
    onClose={() =>
        setShowReportsDrawer(false)
    }
/>
    </main>
  );
}

/*
|--------------------------------------------------------------------------
| بطاقات الملخص
|--------------------------------------------------------------------------
*/

function SummaryCard({
  title,
  value,
  subtitle,
}: {
  title: string;
  value: string;
  subtitle: string;
}) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-sm font-black text-slate-500">
        {title}
      </div>

      <div className="mt-3 text-2xl font-black text-[#0B2A4A]">
        {value}
      </div>

      <div className="mt-2 text-xs font-semibold text-slate-500">
        {subtitle}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| عدادات الحالات
|--------------------------------------------------------------------------
*/

function StatusCounter({
  title,
  value,
}: {
  title: string;
  value: number;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 text-center shadow-sm">
      <div className="text-2xl font-black text-slate-800">
        {formatNumber(value)}
      </div>

      <div className="mt-1 text-xs font-bold text-slate-500">
        {title}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| جدول الكمبيوتر
|--------------------------------------------------------------------------
*/

function AssetsDesktopTable({
  assets,
  onView,
  onTransfer,
  onMaintenance,
  onSell,
  onDispose,
}: {
  assets: FixedAsset[];

  onView: (
    asset: FixedAsset
  ) => void;

  onTransfer: (
    asset: FixedAsset
  ) => void;

  onMaintenance: (
    asset: FixedAsset
  ) => void;

  onSell: (
    asset: FixedAsset
  ) => void;

  onDispose: (
    asset: FixedAsset
  ) => void;
}) {
  return (
    <div className="hidden overflow-x-auto xl:block">
      <table className="w-full min-w-[1500px] text-right">
        <thead className="bg-slate-100">
          <tr className="text-sm font-black text-slate-700">
            <th className="p-4">
              الكود
            </th>

            <th className="p-4">
              الأصل
            </th>

            <th className="p-4">
              الفئة
            </th>

            <th className="p-4">
              الفرع
            </th>

            <th className="p-4">
              تكلفة الشراء
            </th>

            <th className="p-4">
              مجمع الإهلاك
            </th>

            <th className="p-4">
              القيمة الدفترية
            </th>

            <th className="p-4">
              الحالة
            </th>

            <th className="p-4">
              الإجراءات
            </th>
          </tr>
        </thead>

        <tbody>
          {assets.map((asset) => (
            <tr
              key={asset.id}
              className="border-t border-slate-100 transition hover:bg-slate-50"
            >
              <td className="p-4 font-black text-[#0B2A4A]">
                {asset.asset_code}
              </td>

              <td className="p-4">
                <div className="font-black text-slate-800">
                  {asset.asset_name}
                </div>

                <div className="mt-1 text-xs font-semibold text-slate-500">
                  {asset.location ||
                    "-"}
                </div>
              </td>

              <td className="p-4 font-bold text-slate-700">
                {asset.category
                  ?.category_name ||
                  "-"}
              </td>

              <td className="p-4 font-bold text-slate-700">
                {asset.branch_name ||
                  asset.branch_id ||
                  "-"}
              </td>

              <td className="p-4 font-black">
                {formatMoney(
                  asset.purchase_cost
                )}
              </td>

              <td className="p-4 font-black text-rose-700">
                {formatMoney(
                  asset
                    .accumulated_depreciation
                )}
              </td>

              <td className="p-4 font-black text-emerald-700">
                {formatMoney(
                  asset
                    .current_book_value
                )}
              </td>

              <td className="p-4">
                <StatusBadge
                  status={
                    asset.asset_status
                  }
                />
              </td>

              <td className="p-4">
                <ActionMenu
                  disabled={
                    asset.asset_status ===
                      "SOLD" ||
                    asset.asset_status ===
                      "DISPOSED"
                  }
                  onView={() =>
                    onView(asset)
                  }
                  onTransfer={() =>
                    onTransfer(asset)
                  }
                  onMaintenance={() =>
                    onMaintenance(asset)
                  }
                  onSell={() =>
                    onSell(asset)
                  }
                  onDispose={() =>
                     onDispose(asset)
                    }

                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| بطاقات الجوال
|--------------------------------------------------------------------------
*/

function AssetsMobileList({
  assets,
  onView,
  onTransfer,
  onMaintenance,
  onSell,
  onDispose,
}: {
  assets: FixedAsset[];

  onView: (
    asset: FixedAsset
  ) => void;

  onTransfer: (
    asset: FixedAsset
  ) => void;

  onMaintenance: (
    asset: FixedAsset
  ) => void;

  onSell: (
    asset: FixedAsset
  ) => void;

  onDispose: (
    asset: FixedAsset
  ) => void;
})
{
  return (
    <div className="space-y-3 p-4 xl:hidden">
      {assets.map((asset) => (
        <article
          key={asset.id}
          className="rounded-3xl border border-slate-200 bg-slate-50 p-4"
        >
          <div className="flex items-start justify-between gap-3">
            <div>
              <h3 className="font-black text-[#0B2A4A]">
                {asset.asset_name}
              </h3>

              <div className="mt-1 text-sm font-bold text-slate-500">
                {asset.asset_code}
              </div>
            </div>

            <StatusBadge
              status={
                asset.asset_status
              }
            />
          </div>

          <div className="mt-4 grid grid-cols-2 gap-2">
            <MobileValue
              title="الفئة"
              value={
                asset.category
                  ?.category_name ||
                "-"
              }
            />

            <MobileValue
              title="القيمة الدفترية"
              value={formatMoney(
                asset
                  .current_book_value
              )}
            />

            <MobileValue
              title="مجمع الإهلاك"
              value={formatMoney(
                asset
                  .accumulated_depreciation
              )}
            />

            <MobileValue
              title="الموقع"
              value={
                asset.location || "-"
              }
            />
          </div>

          <div className="mt-4">
            <ActionMenu
              disabled={
                asset.asset_status ===
                  "SOLD" ||
                asset.asset_status ===
                  "DISPOSED"
              }
              onView={() =>
                onView(asset)
              }
              onTransfer={() =>
                onTransfer(asset)
              }
              onMaintenance={() =>
                onMaintenance(asset)
              }
              onSell={() =>
                onSell(asset)
              }
              onDispose={() =>
                onDispose(asset)
              }
            />
          </div>
        </article>
      ))}
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| قيمة داخل بطاقة الجوال
|--------------------------------------------------------------------------
*/

function MobileValue({
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

      <div className="mt-1 text-sm font-black text-slate-800">
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

function StatusBadge({
  status,
}: {
  status: AssetStatus;
}) {
  const settings: Record<
    AssetStatus,
    {
      label: string;
      className: string;
    }
  > = {
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
  };

  const current =
    settings[status];

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${current.className}`}
    >
      {current.label}
    </span>
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
  children: ReactNode;
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
| الزر العام
|--------------------------------------------------------------------------
*/

function ActionButton({
  children,
  onClick,
  secondary = false,
  disabled = false,
}: {
  children: ReactNode;
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
  onCreate,
}: {
  onCreate: () => void;
}) {
  return (
    <div className="flex min-h-72 flex-col items-center justify-center p-6 text-center">
      <div className="text-xl font-black text-[#0B2A4A]">
        لا توجد أصول ثابتة
      </div>

      <p className="mt-3 max-w-md text-sm font-semibold leading-7 text-slate-500">
        أنشئ فئة أصل أولًا، ثم
        سجل أول أصل حتى تبدأ
        عمليات النقل والصيانة
        والإهلاك.
      </p>

      <div className="mt-5">
        <ActionButton
          onClick={onCreate}
        >
          تسجيل أول أصل
        </ActionButton>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| صندوق الرسالة
|--------------------------------------------------------------------------
*/

function MessageBox({
  type,
  text,
  onClose,
}: {
  type:
    | "success"
    | "error"
    | "warning"
    | "info";

  text: string;
  onClose: () => void;
}) {
  const classes = {
    success:
      "border-emerald-200 bg-emerald-50 text-emerald-900",

    error:
      "border-rose-200 bg-rose-50 text-rose-900",

    warning:
      "border-amber-200 bg-amber-50 text-amber-900",

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
        aria-label="إغلاق الرسالة"
      >
        ×
      </button>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| تنسيق الأرقام
|--------------------------------------------------------------------------
*/

function formatNumber(
  value:
    | number
    | string
    | null
    | undefined
): string {
  const parsed =
    Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed.toLocaleString(
        "ar-SA"
      )
    : "0";
}

/*
|--------------------------------------------------------------------------
| تنسيق المبالغ
|--------------------------------------------------------------------------
*/

function formatMoney(
  value:
    | number
    | string
    | null
    | undefined
): string {
  const parsed =
    Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed.toLocaleString(
        "ar-SA",
        {
          minimumFractionDigits: 3,
          maximumFractionDigits: 3,
        }
      )
    : "0.000";
}

/*
|--------------------------------------------------------------------------
| استخراج رسالة خطأ الـ API
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
      typeof response
        ?.data?.message === "string"
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
