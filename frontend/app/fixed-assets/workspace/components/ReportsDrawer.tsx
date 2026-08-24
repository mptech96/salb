"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import type {
  FixedAsset,
  FixedAssetDepreciation,
  FixedAssetMaintenance,
  FixedAssetMovement,
} from "../types";

import {
  getAssetsReport,
  getDepreciationReport,
  getMaintenanceReport,
  getMovementReport,
  getSummaryReport,
  type AssetListFilters,
  type AssetSummaryReport,
  type PaginationResult,
} from "../services/fixedAssets";

type ReportsDrawerProps = {
  open: boolean;
  onClose: () => void;
  defaultBranchId?: number | string | null;
};

type ReportTab =
  | "SUMMARY"
  | "ASSETS"
  | "DEPRECIATIONS"
  | "MAINTENANCES"
  | "MOVEMENTS";

type MessageState = {
  type: "error" | "warning" | "info";
  text: string;
} | null;

type PaginationState = {
  currentPage: number;
  lastPage: number;
  total: number;
};

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

const emptyPagination: PaginationState = {
  currentPage: 1,
  lastPage: 1,
  total: 0,
};

export default function ReportsDrawer({
  open,
  onClose,
  defaultBranchId = null,
}: ReportsDrawerProps) {
  const [activeTab, setActiveTab] =
    useState<ReportTab>("SUMMARY");

  const [branchId, setBranchId] =
    useState<string>(
      defaultBranchId === null ||
        defaultBranchId === undefined
        ? ""
        : String(defaultBranchId)
    );

  const [search, setSearch] = useState("");
  const [assetStatus, setAssetStatus] = useState("");
  const [assetId, setAssetId] = useState("");
  const [maintenanceStatus, setMaintenanceStatus] = useState("");
  const [movementType, setMovementType] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [monthFrom, setMonthFrom] = useState("");
  const [monthTo, setMonthTo] = useState("");

  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<MessageState>(null);
  const [summary, setSummary] =
    useState<AssetSummaryReport>(emptySummary);
  const [assets, setAssets] = useState<FixedAsset[]>([]);
  const [depreciations, setDepreciations] =
    useState<FixedAssetDepreciation[]>([]);
  const [maintenances, setMaintenances] =
    useState<FixedAssetMaintenance[]>([]);
  const [movements, setMovements] =
    useState<FixedAssetMovement[]>([]);
  const [pagination, setPagination] =
    useState<PaginationState>(emptyPagination);

  useEffect(() => {
    if (!open) return;

    setBranchId(
      defaultBranchId === null ||
        defaultBranchId === undefined
        ? ""
        : String(defaultBranchId)
    );
  }, [open, defaultBranchId]);

  const loadCurrentReport = useCallback(
    async (page = 1) => {
      if (!open) return;

      setLoading(true);
      setMessage(null);

      try {
        if (activeTab === "SUMMARY") {
          const result = await getSummaryReport({
            branch_id: branchId || undefined,
          });

          setSummary(result || emptySummary);
          setPagination(emptyPagination);
          return;
        }

        if (activeTab === "ASSETS") {
          const filters: AssetListFilters = {
            page,
            per_page: 20,
            search: search.trim() || undefined,
            branch_id: branchId || undefined,
            asset_status: assetStatus || undefined,
          };

          const result = await getAssetsReport(filters);
          setAssets(normalizeRows(result));
          setPagination(normalizePagination(result));
          return;
        }

        if (activeTab === "DEPRECIATIONS") {
          const result = await getDepreciationReport({
            page,
            per_page: 20,
            branch_id: branchId || undefined,
            asset_id: assetId || undefined,
            month_from: monthFrom || undefined,
            month_to: monthTo || undefined,
          });

          setDepreciations(normalizeRows(result));
          setPagination(normalizePagination(result));
          return;
        }

        if (activeTab === "MAINTENANCES") {
          const result = await getMaintenanceReport({
            page,
            per_page: 20,
            branch_id: branchId || undefined,
            asset_id: assetId || undefined,
            status: maintenanceStatus || undefined,
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
          });

          setMaintenances(normalizeRows(result));
          setPagination(normalizePagination(result));
          return;
        }

        const result = await getMovementReport({
          page,
          per_page: 20,
          branch_id: branchId || undefined,
          asset_id: assetId || undefined,
          movement_type: movementType || undefined,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
        });

        setMovements(normalizeRows(result));
        setPagination(normalizePagination(result));
      } catch (error: unknown) {
        setMessage({
          type: "error",
          text: getApiError(
            error,
            "تعذر تحميل بيانات التقرير."
          ),
        });
      } finally {
        setLoading(false);
      }
    },
    [
      open,
      activeTab,
      branchId,
      search,
      assetStatus,
      assetId,
      maintenanceStatus,
      movementType,
      dateFrom,
      dateTo,
      monthFrom,
      monthTo,
    ]
  );

  useEffect(() => {
    if (!open) return;
    loadCurrentReport(1);
  }, [open, activeTab, loadCurrentReport]);

  function resetFilters() {
    setSearch("");
    setAssetStatus("");
    setAssetId("");
    setMaintenanceStatus("");
    setMovementType("");
    setDateFrom("");
    setDateTo("");
    setMonthFrom("");
    setMonthTo("");
    setBranchId(
      defaultBranchId === null ||
        defaultBranchId === undefined
        ? ""
        : String(defaultBranchId)
    );
  }

  const visibleRowsCount = useMemo(() => {
    if (activeTab === "ASSETS") return assets.length;
    if (activeTab === "DEPRECIATIONS") return depreciations.length;
    if (activeTab === "MAINTENANCES") return maintenances.length;
    if (activeTab === "MOVEMENTS") return movements.length;
    return 0;
  }, [
    activeTab,
    assets.length,
    depreciations.length,
    maintenances.length,
    movements.length,
  ]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[1000] bg-slate-950/55 backdrop-blur-sm">
      <aside
        dir="rtl"
        className="absolute inset-y-0 right-0 flex w-full max-w-[1500px] flex-col bg-slate-50 shadow-2xl"
      >
        <header className="border-b border-slate-200 bg-white px-5 py-4 sm:px-7">
          <div className="flex items-start justify-between gap-4">
            <div>
              <div className="text-sm font-black text-emerald-700">
                SULB ERP
              </div>
              <h2 className="mt-1 text-2xl font-black text-[#0B2A4A] sm:text-3xl">
                مركز تقارير الأصول الثابتة
              </h2>
              <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
                متابعة الملخص المالي وسجل الأصول والإهلاك والصيانة والحركات من شاشة موحدة.
              </p>
            </div>

            <button
              type="button"
              onClick={onClose}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-xl font-black text-slate-500 transition hover:bg-slate-100"
              aria-label="إغلاق التقارير"
            >
              ×
            </button>
          </div>
        </header>

        <div className="border-b border-slate-200 bg-white px-4 py-3 sm:px-7">
          <div className="flex gap-2 overflow-x-auto pb-1">
            <TabButton active={activeTab === "SUMMARY"} onClick={() => setActiveTab("SUMMARY")}>
              الملخص
            </TabButton>
            <TabButton active={activeTab === "ASSETS"} onClick={() => setActiveTab("ASSETS")}>
              سجل الأصول
            </TabButton>
            <TabButton active={activeTab === "DEPRECIATIONS"} onClick={() => setActiveTab("DEPRECIATIONS")}>
              الإهلاك
            </TabButton>
            <TabButton active={activeTab === "MAINTENANCES"} onClick={() => setActiveTab("MAINTENANCES")}>
              الصيانة
            </TabButton>
            <TabButton active={activeTab === "MOVEMENTS"} onClick={() => setActiveTab("MOVEMENTS")}>
              الحركات
            </TabButton>
          </div>
        </div>

        <section className="border-b border-slate-200 bg-slate-50 p-4 sm:p-6">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <FieldWrapper label="رقم الفرع">
              <input
                type="number"
                value={branchId}
                onChange={(event) => setBranchId(event.target.value)}
                placeholder="كل الفروع"
                className={inputClassName}
              />
            </FieldWrapper>

            {activeTab === "ASSETS" && (
              <>
                <FieldWrapper label="البحث">
                  <input
                    type="text"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="الكود أو الاسم أو الموقع"
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="حالة الأصل">
                  <select
                    value={assetStatus}
                    onChange={(event) => setAssetStatus(event.target.value)}
                    className={inputClassName}
                  >
                    <option value="">كل الحالات</option>
                    <option value="ACTIVE">نشط</option>
                    <option value="UNDER_MAINTENANCE">تحت الصيانة</option>
                    <option value="SOLD">مباع</option>
                    <option value="DISPOSED">مشطوب</option>
                  </select>
                </FieldWrapper>
              </>
            )}

            {activeTab !== "SUMMARY" && activeTab !== "ASSETS" && (
              <FieldWrapper label="رقم الأصل">
                <input
                  type="number"
                  value={assetId}
                  onChange={(event) => setAssetId(event.target.value)}
                  placeholder="كل الأصول"
                  className={inputClassName}
                />
              </FieldWrapper>
            )}

            {activeTab === "DEPRECIATIONS" && (
              <>
                <FieldWrapper label="من شهر">
                  <input
                    type="month"
                    value={monthFrom}
                    onChange={(event) => setMonthFrom(event.target.value)}
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="إلى شهر">
                  <input
                    type="month"
                    value={monthTo}
                    onChange={(event) => setMonthTo(event.target.value)}
                    className={inputClassName}
                  />
                </FieldWrapper>
              </>
            )}

            {activeTab === "MAINTENANCES" && (
              <FieldWrapper label="حالة الصيانة">
                <input
                  type="text"
                  value={maintenanceStatus}
                  onChange={(event) => setMaintenanceStatus(event.target.value)}
                  placeholder="مثال: COMPLETED"
                  className={inputClassName}
                />
              </FieldWrapper>
            )}

            {activeTab === "MOVEMENTS" && (
              <FieldWrapper label="نوع الحركة">
                <input
                  type="text"
                  value={movementType}
                  onChange={(event) => setMovementType(event.target.value)}
                  placeholder="مثال: TRANSFER"
                  className={inputClassName}
                />
              </FieldWrapper>
            )}

            {(activeTab === "MAINTENANCES" || activeTab === "MOVEMENTS") && (
              <>
                <FieldWrapper label="من تاريخ">
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(event) => setDateFrom(event.target.value)}
                    className={inputClassName}
                  />
                </FieldWrapper>

                <FieldWrapper label="إلى تاريخ">
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(event) => setDateTo(event.target.value)}
                    className={inputClassName}
                  />
                </FieldWrapper>
              </>
            )}
          </div>

          <div className="mt-4 flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => loadCurrentReport(1)}
              disabled={loading}
              className="inline-flex min-h-11 items-center justify-center rounded-2xl bg-[#0B2A4A] px-5 text-sm font-black text-white transition hover:bg-[#123D68] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? "جاري التحميل..." : "تطبيق الفلاتر"}
            </button>

            <button
              type="button"
              onClick={resetFilters}
              disabled={loading}
              className="inline-flex min-h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-black text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
            >
              مسح الفلاتر
            </button>
          </div>
        </section>

        <div className="flex-1 overflow-y-auto p-4 sm:p-6">
          {message && (
            <MessageBox text={message.text} onClose={() => setMessage(null)} />
          )}

          {loading ? (
            <LoadingState />
          ) : (
            <>
              {activeTab === "SUMMARY" && <SummaryReport summary={summary} />}
              {activeTab === "ASSETS" && <AssetsReport assets={assets} />}
              {activeTab === "DEPRECIATIONS" && <DepreciationsReport rows={depreciations} />}
              {activeTab === "MAINTENANCES" && <MaintenancesReport rows={maintenances} />}
              {activeTab === "MOVEMENTS" && <MovementsReport rows={movements} />}
            </>
          )}
        </div>

        {activeTab !== "SUMMARY" && (
          <footer className="border-t border-slate-200 bg-white px-4 py-4 sm:px-7">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="text-sm font-bold text-slate-500">
                إجمالي النتائج: {formatNumber(pagination.total)} — المعروض: {formatNumber(visibleRowsCount)}
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={loading || pagination.currentPage <= 1}
                  onClick={() => loadCurrentReport(pagination.currentPage - 1)}
                  className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  السابق
                </button>

                <span className="rounded-xl bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">
                  {formatNumber(pagination.currentPage)} / {formatNumber(pagination.lastPage)}
                </span>

                <button
                  type="button"
                  disabled={loading || pagination.currentPage >= pagination.lastPage}
                  onClick={() => loadCurrentReport(pagination.currentPage + 1)}
                  className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  التالي
                </button>
              </div>
            </div>
          </footer>
        )}
      </aside>
    </div>
  );
}

function SummaryReport({ summary }: { summary: AssetSummaryReport }) {
  return (
    <div className="space-y-5">
      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard title="إجمالي الأصول" value={formatNumber(summary.total_assets)} subtitle={`نشط: ${formatNumber(summary.active_assets)}`} />
        <SummaryCard title="تكلفة الشراء" value={formatMoney(summary.purchase_cost_total)} subtitle="التكلفة التاريخية" />
        <SummaryCard title="مجمع الإهلاك" value={formatMoney(summary.accumulated_depreciation_total)} subtitle="إجمالي الإهلاك" />
        <SummaryCard title="القيمة الدفترية" value={formatMoney(summary.book_value_total)} subtitle={`القيمة التخريدية: ${formatMoney(summary.salvage_value_total)}`} />
      </section>

      <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatusCard title="نشط" value={summary.active_assets} />
        <StatusCard title="تحت الصيانة" value={summary.under_maintenance_assets} />
        <StatusCard title="مباع" value={summary.sold_assets} />
        <StatusCard title="مشطوب" value={summary.disposed_assets} />
      </section>
    </div>
  );
}

function AssetsReport({ assets }: { assets: FixedAsset[] }) {
  if (assets.length === 0) return <EmptyReport />;

  return (
    <ReportTable headers={["الكود", "اسم الأصل", "الفئة", "الفرع", "تكلفة الشراء", "مجمع الإهلاك", "القيمة الدفترية", "الحالة"]}>
      {assets.map((asset) => (
        <tr key={asset.id} className="border-t border-slate-100 hover:bg-slate-50">
          <Cell strong>{asset.asset_code}</Cell>
          <Cell>{asset.asset_name}</Cell>
          <Cell>{asset.category?.category_name || "-"}</Cell>
          <Cell>{asset.branch_name || asset.branch_id || "-"}</Cell>
          <Cell>{formatMoney(asset.purchase_cost)}</Cell>
          <Cell>{formatMoney(asset.accumulated_depreciation)}</Cell>
          <Cell>{formatMoney(asset.current_book_value)}</Cell>
          <Cell>{assetStatusLabel(asset.asset_status)}</Cell>
        </tr>
      ))}
    </ReportTable>
  );
}

function DepreciationsReport({ rows }: { rows: FixedAssetDepreciation[] }) {
  if (rows.length === 0) return <EmptyReport />;

  return (
    <ReportTable headers={["الرقم", "شهر الإهلاك", "القيمة الافتتاحية", "قيمة الإهلاك", "القيمة الختامية"]}>
      {rows.map((row) => (
        <tr key={row.id} className="border-t border-slate-100 hover:bg-slate-50">
          <Cell strong>{formatNumber(row.id)}</Cell>
          <Cell>{row.depreciation_month}</Cell>
          <Cell>{formatMoney(row.opening_book_value)}</Cell>
          <Cell>{formatMoney(row.depreciation_amount)}</Cell>
          <Cell>{formatMoney(row.closing_book_value)}</Cell>
        </tr>
      ))}
    </ReportTable>
  );
}

function MaintenancesReport({ rows }: { rows: FixedAssetMaintenance[] }) {
  if (rows.length === 0) return <EmptyReport />;

  return (
    <ReportTable headers={["الرقم", "تاريخ الصيانة", "نوع الصيانة", "المورد", "التكلفة", "الحالة"]}>
      {rows.map((row) => (
        <tr key={row.id} className="border-t border-slate-100 hover:bg-slate-50">
          <Cell strong>{formatNumber(row.id)}</Cell>
          <Cell>{row.maintenance_date}</Cell>
          <Cell>{row.maintenance_type || "-"}</Cell>
          <Cell>{row.supplier_name || "-"}</Cell>
          <Cell>{formatMoney(row.maintenance_cost)}</Cell>
          <Cell>{row.status}</Cell>
        </tr>
      ))}
    </ReportTable>
  );
}

function MovementsReport({ rows }: { rows: FixedAssetMovement[] }) {
  if (rows.length === 0) return <EmptyReport />;

  return (
    <ReportTable headers={["الرقم", "نوع الحركة", "تاريخ الحركة", "المبلغ", "المرجع", "الملاحظات"]}>
      {rows.map((row) => (
        <tr key={row.id} className="border-t border-slate-100 hover:bg-slate-50">
          <Cell strong>{formatNumber(row.id)}</Cell>
          <Cell>{row.movement_type}</Cell>
          <Cell>{row.movement_date}</Cell>
          <Cell>{formatMoney(row.amount)}</Cell>
          <Cell>{row.reference_no || "-"}</Cell>
          <Cell>{row.notes || "-"}</Cell>
        </tr>
      ))}
    </ReportTable>
  );
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`whitespace-nowrap rounded-2xl px-5 py-3 text-sm font-black transition ${active ? "bg-[#0B2A4A] text-white" : "border border-slate-200 bg-white text-slate-700 hover:bg-slate-100"}`}
    >
      {children}
    </button>
  );
}

function FieldWrapper({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-black text-slate-700">{label}</span>
      {children}
    </label>
  );
}

function SummaryCard({ title, value, subtitle }: { title: string; value: string; subtitle: string }) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-sm font-black text-slate-500">{title}</div>
      <div className="mt-3 text-2xl font-black text-[#0B2A4A]">{value}</div>
      <div className="mt-2 text-xs font-bold text-slate-500">{subtitle}</div>
    </div>
  );
}

function StatusCard({ title, value }: { title: string; value: number }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 text-center shadow-sm">
      <div className="text-2xl font-black text-slate-800">{formatNumber(value)}</div>
      <div className="mt-1 text-xs font-black text-slate-500">{title}</div>
    </div>
  );
}

function ReportTable({ headers, children }: { headers: string[]; children: ReactNode }) {
  return (
    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1000px] text-right">
          <thead className="bg-slate-100">
            <tr>
              {headers.map((header) => (
                <th key={header} className="p-4 text-sm font-black text-slate-700">{header}</th>
              ))}
            </tr>
          </thead>
          <tbody>{children}</tbody>
        </table>
      </div>
    </div>
  );
}

function Cell({ children, strong = false }: { children: ReactNode; strong?: boolean }) {
  return (
    <td className={`p-4 text-sm text-slate-700 ${strong ? "font-black text-[#0B2A4A]" : "font-semibold"}`}>
      {children}
    </td>
  );
}

function LoadingState() {
  return (
    <div className="flex min-h-80 flex-col items-center justify-center gap-4">
      <div className="h-12 w-12 animate-spin rounded-full border-4 border-slate-200 border-t-[#0B2A4A]" />
      <div className="font-black text-slate-500">جاري تحميل التقرير...</div>
    </div>
  );
}

function EmptyReport() {
  return (
    <div className="flex min-h-72 items-center justify-center rounded-3xl border border-dashed border-slate-300 bg-white p-6 text-center">
      <div>
        <div className="text-xl font-black text-[#0B2A4A]">لا توجد بيانات</div>
        <p className="mt-2 text-sm font-semibold text-slate-500">لا توجد نتائج مطابقة للفلاتر المحددة.</p>
      </div>
    </div>
  );
}

function MessageBox({ text, onClose }: { text: string; onClose: () => void }) {
  return (
    <div className="mb-5 flex items-start justify-between gap-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-900">
      <div>{text}</div>
      <button type="button" onClick={onClose} className="font-black" aria-label="إغلاق الرسالة">×</button>
    </div>
  );
}

function normalizeRows<T>(result: PaginationResult<T>): T[] {
  return Array.isArray(result.data) ? result.data : [];
}

function normalizePagination<T>(result: PaginationResult<T>): PaginationState {
  return {
    currentPage: Number(result.current_page || 1),
    lastPage: Number(result.last_page || 1),
    total: Number(result.total || result.data?.length || 0),
  };
}

function assetStatusLabel(status: FixedAsset["asset_status"]): string {
  const labels: Record<FixedAsset["asset_status"], string> = {
    ACTIVE: "نشط",
    UNDER_MAINTENANCE: "تحت الصيانة",
    SOLD: "مباع",
    DISPOSED: "مشطوب",
  };

  return labels[status];
}

function formatNumber(value: number | string | null | undefined): string {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed.toLocaleString("ar-SA") : "0";
}

function formatMoney(value: number | string | null | undefined): string {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed)
    ? parsed.toLocaleString("ar-SA", {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
      })
    : "0.000";
}

function getApiError(error: unknown, fallback: string): string {
  if (error && typeof error === "object" && "response" in error) {
    const response = (
      error as {
        response?: {
          data?: {
            message?: unknown;
            errors?: Record<string, string[] | string>;
          };
        };
      }
    ).response;

    if (typeof response?.data?.message === "string") {
      return response.data.message;
    }

    const errors = response?.data?.errors;

    if (errors && typeof errors === "object") {
      const firstError = Object.values(errors)[0];
      if (Array.isArray(firstError)) return firstError[0] || fallback;
      if (typeof firstError === "string") return firstError;
    }
  }

  if (error instanceof Error && error.message) return error.message;
  return fallback;
}

const inputClassName =
  "h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 font-semibold text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#0B2A4A] focus:ring-4 focus:ring-slate-100";
