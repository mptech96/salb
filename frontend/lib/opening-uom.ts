export function openingInventoryPayload(row: Record<string, any>) {
  const common = { branch_id: Number(row.branch_id), item_id: Number(row.item_id),
    lot_number: row.lot_number || null, notes: row.notes || null };
  return row.uom_version === "BASE_V1"
    ? { ...common, quantity: String(row.quantity), unit_code: row.unit_code, total_cost: String(row.total_cost) }
    : { ...common, qty_kg: Number(row.qty_kg), total_cost: Number(row.total_cost) };
}
