"use client";

import { useEffect, useState, type FormEvent } from "react";
import api from "@/app/api";

type Unit = { id: number; code: string; name_ar: string; dimension: string };
type Mapping = { id: number; unit_id: number; code: string; factor_to_base: string; revision: number };
type Price = { id: number; mapping_id: number; currency_code: string; purchase_price: string; sale_price: string };
type Definition = { schema_ready: boolean; mode: string; base_unit_id?: number; units: Unit[]; mappings: Mapping[]; prices: Price[] };
type Item = { id: number; item_code?: string; item_name: string; item_type?: string };
const field = "w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm";

/** Decimal inputs stay strings all the way to the server; no Number/float conversion. */
export default function ItemUnitManager({ items }: { items: Item[] }) {
  const [item, setItem] = useState("");
  const [definition, setDefinition] = useState<Definition | null>(null);
  const [unit, setUnit] = useState("");
  const [factor, setFactor] = useState("1");
  const [mapping, setMapping] = useState("");
  const [currency, setCurrency] = useState("SAR");
  const [purchase, setPurchase] = useState("0");
  const [sale, setSale] = useState("0");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    let active = true;
    setDefinition(null); setUnit(""); setMapping(""); setMessage("");
    if (!item) return;
    api.get(`/items/${item}/units`).then(response => {
      if (active) setDefinition(response.data.data);
    }).catch(error => {
      if (active) setMessage(error?.response?.data?.message || "تعذر تحميل تعريف الوحدات أو لا تتوفر صلاحية العرض.");
    });
    return () => { active = false; };
  }, [item, revision]);

  const base = definition?.units.find(value => value.id === definition.base_unit_id);
  const enabled = definition?.schema_ready && definition.mode === "BASE_ONLY";
  async function save(event: FormEvent, kind: "mappings" | "prices") {
    event.preventDefault();
    if (!enabled || busy) return;
    setBusy(true); setMessage("");
    try {
      const payload = kind === "mappings"
        ? { unit_id: Number(unit), factor }
        : { mapping_id: Number(mapping), currency_code: currency, purchase_price: purchase, sale_price: sale };
      await api.post(`/items/${item}/units/${kind}`, payload);
      setRevision(value => value + 1);
    } catch (error: unknown) {
      const response = (error as { response?: { data?: { message?: string } } }).response;
      setMessage(response?.data?.message || "لم يتم الحفظ. تحقق من الصلاحيات والوحدة والدقة والعملة.");
    } finally { setBusy(false); }
  }

  return <section aria-label="إدارة وحدات الأصناف" className="space-y-4 rounded-xl border bg-white p-4" dir="rtl">
    <h2 className="text-lg font-bold">وحدات الأصناف ومعاملات التحويل</h2>
    <p className="text-sm text-slate-600">كل تعديل ينشئ مراجعة جديدة؛ لا يعيد تسعير المستندات القديمة ولا يحوّل أصناف الوزن التاريخية.</p>
    <label className="block">الصنف<select className={field} value={item} disabled={busy} onChange={event => setItem(event.target.value)}>
      <option value="">اختر الصنف</option>{items.filter(value => value.item_type !== "SERVICE").map(value => <option key={value.id} value={value.id}>{value.item_code} — {value.item_name}</option>)}
    </select></label>
    {message && <p role="alert" className="rounded bg-amber-50 p-3 text-amber-900">{message}</p>}
    {definition && !definition.schema_ready && <p role="status">Schema الوحدات غير مثبتة. بقيت بيانات الأصناف القديمة دون تغيير.</p>}
    {definition?.schema_ready && <p>الوحدة الأساسية: <b>{base?.code || "KG (العقد التاريخي)"}</b> — {definition.mode}</p>}
    {definition?.schema_ready && !enabled && <p role="status">هذا صنف تاريخي؛ تغيير عقد كمياته محجوب. لا يتم إنشاء تحويل صوري إلى كيلوجرام.</p>}
    {enabled && <>
      <form onSubmit={event => void save(event, "mappings")} className="grid gap-3 sm:grid-cols-3">
        <label>الوحدة الفرعية<select required disabled={busy} className={field} value={unit} onChange={event => setUnit(event.target.value)}><option value="">اختر الوحدة</option>{definition.units.filter(value => value.dimension === base?.dimension).map(value => <option key={value.id} value={value.id}>{value.name_ar} ({value.code})</option>)}</select></label>
        <label>عدد الوحدات الأساسية لكل وحدة<input required disabled={busy} inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,6})?" className={field} value={factor} onChange={event => setFactor(event.target.value)} /></label>
        <button disabled={busy || !unit} className="self-end rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">حفظ مراجعة التحويل</button>
      </form>
      <form onSubmit={event => void save(event, "prices")} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <label>مراجعة الوحدة<select required disabled={busy} className={field} value={mapping} onChange={event => setMapping(event.target.value)}><option value="">اختر الوحدة</option>{definition.mappings.map(value => <option key={value.id} value={value.id}>{value.code} × {value.factor_to_base} (#{value.revision})</option>)}</select></label>
        <label>العملة<input required maxLength={10} disabled={busy} className={field} value={currency} onChange={event => setCurrency(event.target.value)} /></label>
        <label>سعر الشراء<input required disabled={busy} inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,6})?" className={field} value={purchase} onChange={event => setPurchase(event.target.value)} /></label>
        <label>سعر البيع<input required disabled={busy} inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,6})?" className={field} value={sale} onChange={event => setSale(event.target.value)} /></label>
        <button disabled={busy || !mapping} className="self-end rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50">حفظ مراجعة الأسعار</button>
      </form>
      <div className="overflow-x-auto"><table className="w-full text-right text-sm"><caption className="text-right font-semibold">المراجعات الحالية</caption><thead><tr><th>الوحدة</th><th>المعامل</th><th>المراجعة</th><th>الأسعار</th></tr></thead><tbody>{definition.mappings.map(value => <tr key={value.id} className="border-t"><td className="p-2">{value.code}</td><td>{value.factor_to_base}</td><td>{value.revision}</td><td>{definition.prices.filter(price => price.mapping_id === value.id).map(price => <div key={price.id}>{price.currency_code}: شراء {price.purchase_price} / بيع {price.sale_price}</div>)}</td></tr>)}</tbody></table></div>
    </>}
  </section>;
}
