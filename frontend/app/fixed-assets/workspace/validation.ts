import type {
  CreateAssetPayload,
  DepreciationRunPayload,
  DisposeAssetPayload,
  MaintenancePayload,
  SellAssetPayload,
  TransferAssetPayload,
} from "./services/fixedAssets";

export type ValidationResult = {
  valid: boolean;
  errors: Record<string, string>;
};

export function validateAsset(
  payload: Partial<CreateAssetPayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.asset_code?.trim()) {
    errors.asset_code = "كود الأصل مطلوب.";
  }

  if (!payload.asset_name?.trim()) {
    errors.asset_name = "اسم الأصل مطلوب.";
  }

  if (!payload.category_id) {
    errors.category_id = "فئة الأصل مطلوبة.";
  }

  if (
    payload.purchase_cost === undefined ||
    payload.purchase_cost === null ||
    Number(payload.purchase_cost) < 0
  ) {
    errors.purchase_cost =
      "تكلفة شراء الأصل غير صحيحة.";
  }

  if (
    Number(payload.salvage_value || 0) >
    Number(payload.purchase_cost || 0)
  ) {
    errors.salvage_value =
      "القيمة المتبقية لا يمكن أن تكون أكبر من تكلفة الأصل.";
  }

  if (
    payload.depreciation_method !==
    "NO_DEPRECIATION"
  ) {
    if (!payload.depreciation_start_date) {
      errors.depreciation_start_date =
        "تاريخ بداية الإهلاك مطلوب.";
    }

    const usefulLife = Number(
      payload.useful_life_months || 0
    );

    const annualRate = Number(
      payload.annual_depreciation_rate || 0
    );

    if (
      payload.depreciation_method ===
        "STRAIGHT_LINE" &&
      usefulLife <= 0
    ) {
      errors.useful_life_months =
        "العمر الإنتاجي يجب أن يكون أكبر من صفر.";
    }

    if (
      payload.depreciation_method ===
        "DECLINING_BALANCE" &&
      annualRate <= 0
    ) {
      errors.annual_depreciation_rate =
        "نسبة الإهلاك السنوية يجب أن تكون أكبر من صفر.";
    }
  }

  return result(errors);
}

export function validateTransfer(
  payload: Partial<TransferAssetPayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.transfer_date) {
    errors.transfer_date =
      "تاريخ نقل الأصل مطلوب.";
  }

  if (
    !payload.to_branch_id &&
    !payload.to_worker_id &&
    !payload.to_location?.trim()
  ) {
    errors.destination =
      "حدد فرعًا أو موظفًا أو موقعًا جديدًا للنقل.";
  }

  return result(errors);
}

export function validateMaintenance(
  payload: Partial<MaintenancePayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.maintenance_date) {
    errors.maintenance_date =
      "تاريخ الصيانة مطلوب.";
  }

  if (
    payload.maintenance_cost === undefined ||
    payload.maintenance_cost === null ||
    Number(payload.maintenance_cost) < 0
  ) {
    errors.maintenance_cost =
      "تكلفة الصيانة غير صحيحة.";
  }

  if (!payload.cost_treatment) {
    errors.cost_treatment =
      "طريقة معالجة تكلفة الصيانة مطلوبة.";
  }

  if (
    payload.cost_treatment === "EXPENSE" &&
    Number(payload.maintenance_cost || 0) > 0 &&
    !payload.expense_account_id
  ) {
    errors.expense_account_id =
      "حساب مصروف الصيانة مطلوب.";
  }

  if (
    Number(payload.maintenance_cost || 0) > 0 &&
    !payload.payment_account_id
  ) {
    errors.payment_account_id =
      "حساب السداد مطلوب.";
  }

  return result(errors);
}

export function validateDepreciationRun(
  payload: Partial<DepreciationRunPayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.depreciation_month) {
    errors.depreciation_month =
      "شهر الإهلاك مطلوب.";
  }

  return result(errors);
}

export function validateSale(
  payload: Partial<SellAssetPayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.sale_date) {
    errors.sale_date =
      "تاريخ بيع الأصل مطلوب.";
  }

  if (
    payload.sale_amount === undefined ||
    payload.sale_amount === null ||
    Number(payload.sale_amount) < 0
  ) {
    errors.sale_amount =
      "قيمة بيع الأصل غير صحيحة.";
  }

  if (!payload.collection_account_id) {
    errors.collection_account_id =
      "حساب تحصيل قيمة البيع مطلوب.";
  }

  return result(errors);
}

export function validateDisposal(
  payload: Partial<DisposeAssetPayload>
): ValidationResult {
  const errors: Record<string, string> = {};

  if (!payload.disposal_date) {
    errors.disposal_date =
      "تاريخ شطب الأصل مطلوب.";
  }

  return result(errors);
}

export function firstValidationMessage(
  validation: ValidationResult
): string {
  return (
    Object.values(validation.errors)[0] ||
    "تحقق من البيانات المدخلة."
  );
}

function result(
  errors: Record<string, string>
): ValidationResult {
  return {
    valid: Object.keys(errors).length === 0,
    errors,
  };
}