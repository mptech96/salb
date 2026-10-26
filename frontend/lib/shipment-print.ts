import { exactQuantity, isBaseLot } from "./inventory-uom";

/** Frozen transaction quantities and independent physical evidence for printing. */
export function shipmentPrintLine(line: Record<string, any>) {
  const base = isBaseLot(line);
  return {
    quantity: `${exactQuantity(base ? line.quantity : line.accepted_qty_kg)} ${base ? line.unit_code || "—" : "KG"}`,
    inventoryQuantity: base ? `${exactQuantity(line.base_quantity)} ${line.base_unit_code_snapshot || "—"}` : "—",
    physicalWeight: base
      ? line.actual_weight_kg == null || line.actual_weight_kg === "" ? "غير موثق" : `${exactQuantity(line.actual_weight_kg)} KG`
      : `${exactQuantity(line.gross_qty_kg)} KG`,
    deduction: base ? "—" : `${exactQuantity(line.deduction_qty_kg)} KG`,
    priceUnit: base ? line.unit_code || "—" : line.price_unit || "KG",
    price: base ? line.uom_price_snapshot ?? line.unit_price : line.price_unit === "TON" ? Number(line.unit_price_per_kg || 0) * 1000 : line.unit_price_per_kg,
  };
}
