export type AssetStatus =
  | "ACTIVE"
  | "UNDER_MAINTENANCE"
  | "SOLD"
  | "DISPOSED";

export type DepreciationMethod =
  | "STRAIGHT_LINE"
  | "DECLINING_BALANCE"
  | "NO_DEPRECIATION";

export interface FixedAssetCategory {
  id: number;
  category_code: string;
  category_name: string;
}

export interface FixedAsset {
  id: number;

  asset_code: string;
  asset_name: string;
  description?: string | null;

  category_id: number;
  category?: FixedAssetCategory | null;

  branch_id?: number | null;
  branch_name?: string | null;

  location?: string | null;

  responsible_worker_id?: number | null;
  responsible_worker_name?: string | null;

  purchase_date?: string | null;

  purchase_cost: number;
  salvage_value: number;

  accumulated_depreciation: number;
  current_book_value: number;

  depreciation_method: DepreciationMethod;

  last_depreciation_date?: string | null;

  asset_status: AssetStatus;

  is_active: boolean;
}

export interface FixedAssetMovement {
  id: number;

  movement_type: string;

  movement_date: string;

  amount: number;

  reference_no?: string;

  notes?: string;
}

export interface FixedAssetMaintenance {
  id: number;

  maintenance_date: string;

  maintenance_type?: string;

  maintenance_cost: number;

  status: string;

  supplier_name?: string;
}

export interface FixedAssetDepreciation {
  id: number;

  depreciation_month: string;

  depreciation_amount: number;

  opening_book_value: number;

  closing_book_value: number;
}