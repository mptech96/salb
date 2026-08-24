import api from "../../../api";

import type {
  FixedAsset,
  FixedAssetDepreciation,
  FixedAssetMaintenance,
  FixedAssetMovement,
} from "../types";

/*
|--------------------------------------------------------------------------
| الأنواع المساعدة
|--------------------------------------------------------------------------
*/

export type PaginationResult<T> = {
  current_page?: number;
  data: T[];
  first_page_url?: string | null;
  from?: number | null;
  last_page?: number;
  last_page_url?: string | null;
  links?: unknown[];
  next_page_url?: string | null;
  path?: string;
  per_page?: number;
  prev_page_url?: string | null;
  to?: number | null;
  total?: number;
};

export type AssetListFilters = {
  page?: number;
  per_page?: number;
  search?: string;
  branch_id?: number | string;
  category_id?: number | string;
  asset_status?: string;
};

export type CreateAssetPayload = {
  asset_code: string;
  asset_name: string;
  category_id: number;

  description?: string | null;
  branch_id?: number | null;
  location?: string | null;
  cost_center_id?: number | null;
  responsible_worker_id?: number | null;

  serial_number?: string | null;
  barcode?: string | null;

  purchase_date?: string | null;
  purchase_cost: number;
  salvage_value: number;

  depreciation_method:
    | "STRAIGHT_LINE"
    | "DECLINING_BALANCE"
    | "NO_DEPRECIATION";

  useful_life_months?: number | null;
  annual_depreciation_rate?: number | null;
  depreciation_start_date?: string | null;

  asset_account_id?: number | null;
  accumulated_account_id?: number | null;
  expense_account_id?: number | null;

  purchase_invoice_id?: number | null;
  reference_no?: string | null;
  notes?: string | null;
};

export type TransferAssetPayload = {
  to_branch_id?: number | null;
  to_worker_id?: number | null;
  to_location?: string | null;
  transfer_date: string;
  reference_no?: string | null;
  notes?: string | null;
};

export type MaintenancePayload = {
  maintenance_date: string;
  maintenance_type?: string | null;
  supplier_name?: string | null;
  invoice_number?: string | null;
  maintenance_cost: number;

  cost_treatment:
    | "EXPENSE"
    | "CAPITALIZE";

  expense_account_id?: number | null;
  payment_account_id?: number | null;
  description?: string | null;
  notes?: string | null;
};

export type DepreciationRunPayload = {
  depreciation_month: string;
  branch_id?: number | null;
};

export type SellAssetPayload = {
  sale_date: string;
  sale_amount: number;
  collection_account_id: number;

  asset_account_id?: number | null;
  accumulated_account_id?: number | null;
  disposal_gain_account_id?: number | null;
  disposal_loss_account_id?: number | null;

  reference_no?: string | null;
  notes?: string | null;
};

export type DisposeAssetPayload = {
  disposal_date: string;

  asset_account_id?: number | null;
  accumulated_account_id?: number | null;
  disposal_loss_account_id?: number | null;

  reference_no?: string | null;
  notes?: string | null;
};

export type AssetSummaryReport = {
  total_assets: number;
  active_assets: number;
  under_maintenance_assets: number;
  sold_assets: number;
  disposed_assets: number;

  purchase_cost_total: number;
  accumulated_depreciation_total: number;
  book_value_total: number;
  salvage_value_total: number;
};

export type DepreciationRunResult = {
  depreciation_month: string;
  company_id: number;
  branch_id?: number | null;

  eligible_assets_count: number;
  posted_count: number;
  failed_count: number;
  total_depreciation: number;

  results: Array<Record<string, unknown>>;
  errors: Array<{
    asset_id: number;
    message: string;
  }>;
};

/*
|--------------------------------------------------------------------------
| الأصول
|--------------------------------------------------------------------------
*/

export async function getAssets(
  filters: AssetListFilters = {}
): Promise<PaginationResult<FixedAsset>> {
  const response = await api.get("/fixed-assets", {
    params: cleanParams(filters),
  });

  const payload = response?.data?.data;

  if (Array.isArray(payload)) {
    return {
      data: payload,
      total: payload.length,
    };
  }

  return {
    ...payload,
    data: Array.isArray(payload?.data)
      ? payload.data
      : [],
  };
}

export async function getAsset(
  assetId: number
): Promise<FixedAsset> {
  const response = await api.get(
    `/fixed-assets/${assetId}`
  );

  return response.data.data;
}

export async function createAsset(
  payload: CreateAssetPayload
): Promise<FixedAsset> {
  const response = await api.post(
    "/fixed-assets",
    payload
  );

  return response.data.data;
}

/*
| ملاحظة:
| يجب أن يكون PUT /fixed-assets/{id} موجودًا في الباك
| قبل استخدام التعديل من الواجهة.
*/
export async function updateAsset(
  assetId: number,
  payload: Partial<CreateAssetPayload>
): Promise<FixedAsset> {
  const response = await api.put(
    `/fixed-assets/${assetId}`,
    payload
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| نقل الأصل
|--------------------------------------------------------------------------
*/

export async function transferAsset(
  assetId: number,
  payload: TransferAssetPayload
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-assets/${assetId}/transfer`,
    payload
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| الصيانة
|--------------------------------------------------------------------------
*/

export async function createMaintenance(
  assetId: number,
  payload: MaintenancePayload
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-assets/${assetId}/maintenance`,
    payload
  );

  return response.data.data;
}

export async function approveMaintenance(
  maintenanceId: number
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-asset-maintenance/${maintenanceId}/approve`
  );

  return response.data.data;
}

export async function completeMaintenance(
  maintenanceId: number
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-asset-maintenance/${maintenanceId}/complete`
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| الإهلاك
|--------------------------------------------------------------------------
*/

export async function runDepreciation(
  payload: DepreciationRunPayload
): Promise<DepreciationRunResult> {
  const response = await api.post(
    "/fixed-assets/depreciation/run",
    payload
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| بيع الأصل
|--------------------------------------------------------------------------
*/

export async function sellAsset(
  assetId: number,
  payload: SellAssetPayload
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-assets/${assetId}/sell`,
    payload
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| شطب الأصل
|--------------------------------------------------------------------------
*/

export async function disposeAsset(
  assetId: number,
  payload: DisposeAssetPayload
): Promise<Record<string, unknown>> {
  const response = await api.post(
    `/fixed-assets/${assetId}/dispose`,
    payload
  );

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| التقارير
|--------------------------------------------------------------------------
*/

export async function getSummaryReport(
  filters: {
    branch_id?: number | string;
  } = {}
): Promise<AssetSummaryReport> {
  const response = await api.get(
    "/fixed-assets/reports/summary",
    {
      params: cleanParams(filters),
    }
  );

  return response.data.data;
}

export async function getAssetsReport(
  filters: AssetListFilters = {}
): Promise<PaginationResult<FixedAsset>> {
  const response = await api.get(
    "/fixed-assets/reports/assets",
    {
      params: cleanParams(filters),
    }
  );

  return normalizePagination<FixedAsset>(
    response.data.data
  );
}

export async function getDepreciationReport(
  filters: {
    page?: number;
    per_page?: number;
    branch_id?: number | string;
    asset_id?: number | string;
    month_from?: string;
    month_to?: string;
  } = {}
): Promise<
  PaginationResult<FixedAssetDepreciation>
> {
  const response = await api.get(
    "/fixed-assets/reports/depreciations",
    {
      params: cleanParams(filters),
    }
  );

  return normalizePagination<FixedAssetDepreciation>(
    response.data.data
  );
}

export async function getMaintenanceReport(
  filters: {
    page?: number;
    per_page?: number;
    branch_id?: number | string;
    asset_id?: number | string;
    status?: string;
    date_from?: string;
    date_to?: string;
  } = {}
): Promise<
  PaginationResult<FixedAssetMaintenance>
> {
  const response = await api.get(
    "/fixed-assets/reports/maintenances",
    {
      params: cleanParams(filters),
    }
  );

  return normalizePagination<FixedAssetMaintenance>(
    response.data.data
  );
}

export async function getMovementReport(
  filters: {
    page?: number;
    per_page?: number;
    branch_id?: number | string;
    asset_id?: number | string;
    movement_type?: string;
    date_from?: string;
    date_to?: string;
  } = {}
): Promise<
  PaginationResult<FixedAssetMovement>
> {
  const response = await api.get(
    "/fixed-assets/reports/movements",
    {
      params: cleanParams(filters),
    }
  );

  return normalizePagination<FixedAssetMovement>(
    response.data.data
  );
}

/*
|--------------------------------------------------------------------------
| المساعدات
|--------------------------------------------------------------------------
*/

function cleanParams(
  values: Record<string, unknown>
): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(values).filter(
      ([, value]) =>
        value !== "" &&
        value !== null &&
        value !== undefined
    )
  );
}

function normalizePagination<T>(
  payload: unknown
): PaginationResult<T> {
  if (Array.isArray(payload)) {
    return {
      data: payload as T[],
      total: payload.length,
    };
  }

  const result =
    payload && typeof payload === "object"
      ? (payload as PaginationResult<T>)
      : ({ data: [] } as PaginationResult<T>);

  return {
    ...result,
    data: Array.isArray(result.data)
      ? result.data
      : [],
  };
}