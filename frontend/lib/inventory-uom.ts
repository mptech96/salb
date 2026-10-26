type Row = Record<string, any>;
export function dashboardStockText(cards?: Row): string {
  if (Array.isArray(cards?.stock_quantities_by_unit)) return cards.stock_quantities_by_unit.map((row: Row) => `${exactQuantity(row.base_quantity)} ${row.base_unit_code || "—"}`).join(" / ") || "0";
  return exactQuantity(cards?.stock_qty);
}
/** Display exact six-place quantities without a floating-point round-trip. */
export function exactQuantity(value: unknown): string {
  const raw = String(value ?? "0");
  if (!/^-?\d+(?:\.\d{1,6})?$/.test(raw)) return "—";
  const [whole, decimals = ""] = raw.split(".");
  const fraction = decimals.replace(/0+$/, "");
  const integer = (whole.startsWith("-") && BigInt(whole) === BigInt(0) && fraction ? "-" : "") + BigInt(whole).toLocaleString("en-US");
  return fraction ? `${integer}.${fraction}` : integer;
}
export const isBaseLot = (row: Row) => row.uom_version === "BASE_V1";
export function returnLineQuantity(row: Row):string {
  return exactQuantity(isBaseLot(row) || Number(row.track_inventory_snapshot ?? 1) !== 1 ? row.quantity : row.qty_kg);
}
export function returnLineUnit(row: Row):string {
  return isBaseLot(row) || Number(row.track_inventory_snapshot ?? 1) !== 1 ? row.unit_code || "UNIT" : "KG";
}
export function returnPayloadLine(row: Row):Row {
  return { source_invoice_line_id: row.id, quantity: isBaseLot(row) ? String(row.return_quantity) : Number(row.return_quantity), notes: row.return_notes || null };
}
export function operationLineQuantity(row: Row):string {
  return `${exactQuantity(isBaseLot(row)?row.quantity:row.qty_kg)} ${isBaseLot(row)?row.unit_code || "—":"KG"}`;
}
export function transferLinePayload(row: Row, item?: Row): Row {
  const common = { item_id: Number(row.item_id), notes: row.notes || null };
  if (item?.inventory_quantity_version === "BASE_V1") {
    if (!/^\d+(?:\.\d{1,6})?$/.test(String(row.quantity ?? "")) || !row.unit_code) throw new Error("اختر الوحدة وأدخل كمية صحيحة حتى ست خانات عشرية.");
    return { ...common, quantity: String(row.quantity), unit_code: row.unit_code };
  }
  return { ...common, qty_kg: Number(row.qty_kg) };
}
export function inventoryBalance(row: Row): string {
  return `${exactQuantity(row.balance_base_quantity ?? row.balance_kg)} ${row.base_unit_code || "KG"}`;
}
export function lotQuantity(row: Row, kind: "received" | "remaining"): string {
  return `${exactQuantity(isBaseLot(row) ? row[`${kind}_base_qty`] : row[`qty_${kind}_kg`])} ${isBaseLot(row) ? row.base_unit_code || "—" : "KG"}`;
}
export function movementQuantity(row: Row): string {
  return `${exactQuantity(isBaseLot(row) ? row.base_quantity : row.qty_kg)} ${isBaseLot(row) ? row.base_unit_code || "—" : "KG"}`;
}
