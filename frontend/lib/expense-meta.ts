type ApiObject = Record<string, unknown>;

function object(value: unknown): value is ApiObject {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

/** Never turn a failed API response or malformed collection into an empty list. */
export function expenseMetaArray(value: unknown, field: string): ApiObject[] {
  if (object(value) && value.status === false) {
    throw new Error(typeof value.message === "string" ? value.message : `تعذر تحميل ${field}.`);
  }
  const rows = Array.isArray(value) ? value : object(value) ? value.data : undefined;
  if (!Array.isArray(rows) || !rows.every(object)) {
    throw new Error(`استجابة ${field} غير صالحة؛ يجب أن تحتوي قائمة بيانات.`);
  }
  if (object(value) && typeof value.total === "number" && value.total > rows.length) {
    throw new Error(`قائمة ${field} غير مكتملة؛ يلزم تحديث بيانات الاختيار في الخادم.`);
  }
  return rows;
}

export function normalizeExpenseMeta(response: unknown) {
  if (!object(response) || response.status !== true || !object(response.data)) {
    throw new Error(object(response) && typeof response.message === "string"
      ? response.message : "استجابة بيانات المصروفات غير صالحة.");
  }
  const data = response.data;
  return {
    ...data,
    base_currency: typeof data.base_currency === "string" ? data.base_currency : "",
    scoped_branch_id: data.scoped_branch_id,
    branches: expenseMetaArray(data.branches, "الفروع"),
    types: expenseMetaArray(data.types, "أنواع المصروفات"),
    shipments: expenseMetaArray(data.shipments, "الشحنات"),
    cars: expenseMetaArray(data.cars, "السيارات"),
    purchases: expenseMetaArray(data.purchases, "المشتريات"),
    sales: expenseMetaArray(data.sales, "المبيعات"),
    drivers: expenseMetaArray(data.drivers, "السائقين"),
    workers: expenseMetaArray(data.workers, "العاملين"),
    currencies: expenseMetaArray(data.currencies, "العملات"),
    financial_accounts: expenseMetaArray(data.financial_accounts, "الحسابات المالية"),
  };
}

export function expensePaymentAccounts(value: unknown, branch: string) {
  return expenseMetaArray(value, "الحسابات المالية").filter((account) =>
    (!account.branch_id || String(account.branch_id) === branch) && Number(account.is_active) === 1,
  );
}
