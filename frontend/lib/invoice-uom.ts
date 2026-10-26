type Row = Record<string, any>;
export const baseItem = (item?: Row) => item?.inventory_quantity_version === "BASE_V1";
export const baseLine = (line: Row) => line.uom_version === "BASE_V1";
export function operationalInvoiceLine(row: Row, item?: Row): Row {
  const service = item?.item_type === "SERVICE";
  const shared = { item_id: Number(row.item_id), discount_amount: Number(row.discount_amount || 0), tax_code_id: row.tax_code_id ? Number(row.tax_code_id) : null, vat_percent: row.vat_percent !== "" ? Number(row.vat_percent) : null, notes: row.notes || null };
  if (baseItem(item)) return { ...shared, quantity: String(row.quantity ?? ""), unit_code: row.unit_code, price_unit: "UNIT", unit_price: String(row.unit_price ?? "") };
  return { ...shared, qty_kg: service ? null : Number(row.qty_kg), quantity: service ? Number(row.quantity) : null, unit_code: service ? row.unit_code || "UNIT" : "KG", price_unit: service ? "UNIT" : row.price_unit, unit_price: Number(row.unit_price || 0) };
}
export function storedInvoiceLine(row: Row): Row {
  const service = row.item_type_snapshot === "SERVICE";
  return { ...row, item_id: String(row.item_id), qty_kg: service || baseLine(row) ? "" : String(row.qty_kg ?? Number(row.qty || 0) * 1000), quantity: service || baseLine(row) ? String(row.quantity ?? row.qty ?? 1) : "", unit_code: row.unit_code || "UNIT", price_unit: service || baseLine(row) ? "UNIT" : row.price_unit || "KG", unit_price: baseLine(row) ? String(row.uom_price_snapshot) : String(service ? Number(row.unit_price || row.unit_price_per_kg || 0) : (row.price_unit || "KG") === "TON" ? Number(row.unit_price_per_kg || 0) * 1000 : Number(row.unit_price_per_kg || 0)), discount_amount: String(row.discount_amount || 0), vat_percent: String(row.vat_percent ?? "") };
}
export const lineQuantityText = (row: Row) => baseLine(row) || row.item_type_snapshot === "SERVICE" ? `${row.quantity ?? row.qty ?? 0} ${row.unit_code || "وحدة"}` : `${row.qty_kg ?? Number(row.qty || 0) * 1000} كجم`;
export const lineUnitPrice = (row: Row) => baseLine(row) ? row.uom_price_snapshot : row.item_type_snapshot === "SERVICE" ? row.unit_price ?? row.unit_price_per_kg ?? 0 : row.unit_price_per_kg ?? Number(row.unit_price || 0) / 1000;
