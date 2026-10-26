type Row = Record<string, any>;

export function commercialSupportAction(user: Row | undefined, base: string, action: string): boolean {
  if (!user?.is_support_mode || user.support_access_level !== "WRITE") return false;
  const route = base.replace(/^\//, "");
  const paths: Record<string, string> = { create: `POST:api/${route}`, update: `PUT:api/${route}/{id}`,
    send: `POST:api/${route}/{id}/send`, accept: `POST:api/${route}/{id}/accept`,
    approve: `POST:api/${route}/{id}/approve`, cancel: `POST:api/${route}/{id}/cancel`,
    convert: `POST:api/${route}/{id}/convert-to-invoice` };
  return !!paths[action] && (user.support_capabilities || []).includes(paths[action]);
}

export function commercialLinePayload(row: Row, item?: Row): Row {
  if (item?.inventory_quantity_version !== "BASE_V1") {
    const qty = Number(row.quantity);
    return { ...row, qty_kg: row.unit_code === "KG" ? qty : qty * 1000 };
  }
  const { qty_kg, base_quantity, uom_mapping_id, uom_version, base_uom_id_snapshot,
    uom_factor_snapshot, uom_price_snapshot, _captured_line_id, ...entered } = row;
  return { ...entered, quantity: String(row.quantity), unit_price: String(row.unit_price), price_unit: "UNIT" };
}
