/** Existing KG stock remains legacy; no counted-to-mass mapping is manufactured. */
export function recipeItems(items: any[]): any[] {
  return items.filter(i => (i.item_type || "STOCK") === "STOCK" && Number(i.track_inventory ?? 1) === 1);
}
export function recipeUnits(item?: any): {id: string | number; code: string}[] {
  if (!item) return [];
  return item.inventory_quantity_version === "BASE_V1" ? item.uom_units || [] : [{id:"KG",code:"KG"},{id:"TON",code:"TON"}];
}
