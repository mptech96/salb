"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import {ListToolbar,Pager,TableScroll,usePagedSearch,stickyActionCell,stickyActionHead} from "@/components/sulb/ListUX";

const today = () => new Date().toISOString().slice(0, 10);
const fmt = (v: any) => Number(v || 0).toLocaleString("en-US", { minimumFractionDigits: 0, maximumFractionDigits: 3 });

const types = [
  ["TRANSFER", "تحويل بين الفروع"],
  ["SORTING", "فرز"],
  ["RECLASSIFICATION", "إعادة تصنيف"],
  ["CLEANING", "تنظيف"],
  ["STRIPPING", "خلس / تجريد"],
  ["CUTTING", "تقطيع"],
  ["DISASSEMBLY", "فك"],
  ["CONVERSION", "تحويل / معالجة"],
  ["MIXING", "خلط"],
  ["ASSEMBLY", "تجميع"],
  ["SCRAP", "هالك"],
];

const typeMap = Object.fromEntries(types);
const emptyLine = () => ({ item_id: "", qty_kg: "", market_value_per_kg: "", allocation_percent: "", total_cost: "", notes: "" });
const emptyCost = () => ({ cost_type: "عمالة تشغيل", amount: "", currency_code: "", exchange_rate: "1", payment_status: "UNPAID", financial_account_id: "", notes: "" });

export default function InventoryOperationsPage() {
  const [rows, setRows] = useState<any[]>([]);
  const [branches, setBranches] = useState<any[]>([]);
  const [items, setItems] = useState<any[]>([]);
  const [inventoryRows, setInventoryRows] = useState<any[]>([]);
  const [financialAccounts, setFinancialAccounts] = useState<any[]>([]);
  const [currencies, setCurrencies] = useState<any[]>([]);
  const [baseCurrency, setBaseCurrency] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [dialog, setDialog] = useState<any>({ open: false, type: "info", title: "", message: "" });
  const [form, setForm] = useState<any>({
    operation_type: "TRANSFER",
    operation_date: today(),
    from_branch_id: "",
    to_branch_id: "",
    allocation_method: "RELATIVE_VALUE",
    loss_gain_reason: "",
    notes: "",
    from_lines: [emptyLine()],
    to_lines: [emptyLine()],
    costs: [],
  });

  async function load() {
    setLoading(true);
    const [a, meta] = await Promise.allSettled([api.get("/inventory-operations"), api.get("/inventory-operations-meta")]);
    if (a.status === "fulfilled") setRows(a.value.data.data || []);
    if (meta.status === "fulfilled") {
      setBranches(meta.value.data.data?.branches || []);
      setItems(meta.value.data.data?.items || []);
      setInventoryRows(meta.value.data.data?.inventory || []);
      setFinancialAccounts(meta.value.data.data?.financial_accounts || []);
      setCurrencies(meta.value.data.data?.currencies || []);
      setBaseCurrency(meta.value.data.data?.base_currency || "");
    }
    setLoading(false);
  }
  useEffect(() => { void load(); }, []);

  const list=usePagedSearch(rows,(r:any)=>`${r.operation_number||""} ${r.operation_date||""} ${typeMap[r.operation_type]||r.operation_type||""} ${r.from_branch_name||""} ${r.to_branch_name||""} ${r.status||""}`,25);
  const isTransfer = form.operation_type === "TRANSFER";
  const isScrap = form.operation_type === "SCRAP";
  const inputKg = useMemo(() => form.from_lines.reduce((s: number, x: any) => s + Number(x.qty_kg || 0), 0), [form.from_lines]);
  const outputKg = useMemo(() => isTransfer ? inputKg : form.to_lines.reduce((s: number, x: any) => s + Number(x.qty_kg || 0), 0), [form.to_lines, inputKg, isTransfer]);
  const delta = Number((inputKg - outputKg).toFixed(3));
  const availableKg = (itemId: any) =>
    inventoryRows
      .filter((r: any) =>
        String(r.item_id) === String(itemId) &&
        (!form.from_branch_id || String(r.branch_id) === String(form.from_branch_id))
      )
      .reduce((sum: number, r: any) => sum + Number(r.balance_kg || 0), 0);

  function reset(type = "TRANSFER") {
    setForm({ operation_type: type, operation_date: today(), from_branch_id: "", to_branch_id: "", allocation_method: "RELATIVE_VALUE", loss_gain_reason: "", notes: "", from_lines: [emptyLine()], to_lines: [emptyLine()], costs: [] });
  }

  function setLine(kind: "from_lines" | "to_lines", index: number, key: string, value: any) {
    setForm((f: any) => ({ ...f, [kind]: f[kind].map((x: any, n: number) => n === index ? { ...x, [key]: value } : x) }));
  }

  async function save() {
    if (!form.from_branch_id) return setDialog({ open: true, type: "warning", title: "اختر الفرع", message: "اختر فرع المصدر." });
    if (isTransfer && !form.to_branch_id) return setDialog({ open: true, type: "warning", title: "اختر الوجهة", message: "اختر الفرع المستلم." });
    if (!form.from_lines.some((x: any) => x.item_id && Number(x.qty_kg) > 0)) return setDialog({ open: true, type: "warning", title: "المدخلات", message: "أضف صنف مصدر واحدًا على الأقل." });

    const requestedByItem = new Map<string, number>();
    for (const line of form.from_lines) {
      if (!line.item_id || Number(line.qty_kg || 0) <= 0) continue;
      const key = String(line.item_id);
      requestedByItem.set(key, (requestedByItem.get(key) || 0) + Number(line.qty_kg || 0));
    }
    for (const [itemId, requested] of requestedByItem.entries()) {
      const available = availableKg(itemId);
      if (requested > available + 0.0001) {
        const item = items.find((x: any) => String(x.id) === itemId);
        return setDialog({
          open: true,
          type: "warning",
          title: "الكمية أكبر من الرصيد",
          message: `${item?.item_name || "الصنف"}: المطلوب ${fmt(requested)} كجم والمتوفر في فرع المصدر ${fmt(available)} كجم.`,
        });
      }
    }

    if (!isTransfer && !isScrap && !form.to_lines.some((x: any) => x.item_id && Number(x.qty_kg) > 0)) return setDialog({ open: true, type: "warning", title: "المخرجات", message: "أضف صنفًا ناتجًا واحدًا على الأقل." });
    if (!isTransfer && Math.abs(delta) > 0.5 && form.loss_gain_reason.trim().length < 3) return setDialog({ open: true, type: "warning", title: "فرق الوزن يحتاج تفسير", message: `فرق الوزن ${fmt(Math.abs(delta))} كجم. اكتب سبب الفاقد أو الزيادة.` });

    if (!isTransfer && !isScrap && form.allocation_method === "MANUAL_PERCENT") {
      const percent = form.to_lines.reduce((sum: number, x: any) => sum + Number(x.allocation_percent || 0), 0);
      if (Math.abs(percent - 100) > 0.01) return setDialog({ open: true, type: "warning", title: "نسب التكلفة غير مكتملة", message: `مجموع نسب المخرجات يجب أن يساوي 100%. المجموع الحالي ${percent.toFixed(2)}%.` });
    }

    setSaving(true);
    try {
      const payload = {
        ...form,
        from_branch_id: Number(form.from_branch_id),
        to_branch_id: form.to_branch_id ? Number(form.to_branch_id) : null,
        from_lines: form.from_lines.filter((x: any) => x.item_id && Number(x.qty_kg) > 0).map((x: any) => ({ ...x, item_id: Number(x.item_id), qty_kg: Number(x.qty_kg) })),
        to_lines: isTransfer || isScrap ? [] : form.to_lines.filter((x: any) => x.item_id && Number(x.qty_kg) > 0).map((x: any) => ({ ...x, item_id: Number(x.item_id), qty_kg: Number(x.qty_kg), market_value_per_kg: x.market_value_per_kg ? Number(x.market_value_per_kg) : null, allocation_percent: x.allocation_percent ? Number(x.allocation_percent) : null, total_cost: x.total_cost ? Number(x.total_cost) : null })),
      };
      await api.post("/inventory-operations", payload);
      setShowForm(false); reset(); await load();
      setDialog({ open: true, type: "success", title: "تم حفظ المسودة", message: "راجع العملية ثم اضغط ترحيل لتنفيذ حركة المخزون." });
    } catch (e: any) {
      setDialog({ open: true, type: "error", title: "تعذر حفظ العملية", message: e?.response?.data?.message || "حدث خطأ أثناء الحفظ." });
    } finally { setSaving(false); }
  }

  async function approve(id: number) {
    setSaving(true);
    try {
      const r = await api.post(`/inventory-operations/${id}/approve`);
      await load();
      setDialog({ open: true, type: "success", title: "تم ترحيل العملية", message: r.data.message || "تم تحديث المخزون والتكلفة." });
    } catch (e: any) {
      setDialog({ open: true, type: "error", title: "تعذر الترحيل", message: e?.response?.data?.message || "راجع المخزون والكميات." });
    } finally { setSaving(false); }
  }

  async function openDetail(id: number) {
    try { const r = await api.get(`/inventory-operations/${id}`); setDetail(r.data.data); }
    catch (e: any) { setDialog({ open: true, type: "error", title: "تعذر فتح العملية", message: e?.response?.data?.message || "حدث خطأ." }); }
  }

  return (
    <section dir="rtl" className="space-y-5">
      <div className="rounded-3xl bg-gradient-to-l from-[#0B2A4A] to-[#123D68] p-6 text-white shadow-lg">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <div className="text-sm text-blue-100">المخزون والمعالجة</div>
            <h1 className="mt-1 text-3xl font-black">الفرز والتحويل والتخزين</h1>
            <p className="mt-2 max-w-4xl text-sm leading-7 text-blue-100">تحويل بين الفروع مع حفظ مصدر وتكلفة الدفعات، أو تحويل صنف إلى عدة مخرجات مع فاقد/زيادة مبررة وتوزيع تكلفة.</p>
          </div>
          <button onClick={() => { reset(); setShowForm(true); }} className="rounded-2xl bg-white px-5 py-3 font-black text-[#0B2A4A]">+ عملية مخزنية</button>
        </div>
      </div>

      <div className="rounded-3xl border bg-white p-4 shadow-sm"><ListToolbar query={list.query} setQuery={list.setQuery} total={list.total} page={list.page} pageSize={list.pageSize} setPageSize={list.setPageSize} placeholder="ابحث برقم العملية، النوع، الفرع أو الحالة..."/><TableScroll><table className="w-full min-w-[1200px] text-right text-sm"><thead className="bg-slate-100"><tr><th className="p-3">العملية</th><th>التاريخ</th><th>النوع</th><th>من فرع</th><th>إلى فرع</th><th>مدخل كجم</th><th>مخرج كجم</th><th>فاقد</th><th>تكلفة المدخل</th><th>الحالة</th><th className={stickyActionHead}>إجراء</th></tr></thead><tbody>{loading?<tr><td colSpan={11} className="p-8 text-center text-slate-500">جاري التحميل...</td></tr>:list.paged.length===0?<tr><td colSpan={11} className="p-8 text-center text-slate-500">لا توجد عمليات مطابقة.</td></tr>:list.paged.map((r:any)=><tr key={r.id} className="group border-t hover:bg-slate-50"><td className="p-3 font-black text-[#0B2A4A]">{r.operation_number}</td><td>{r.operation_date}</td><td>{typeMap[r.operation_type]||r.operation_type}</td><td>{r.from_branch_name||"—"}</td><td>{r.to_branch_name||"—"}</td><td>{fmt(r.input_weight_kg)}</td><td>{fmt(r.output_weight_kg)}</td><td>{fmt(r.loss_qty_kg)}</td><td>{fmt(r.input_cost)}</td><td><span className={`rounded-full px-3 py-1 text-xs font-black ${r.status==="POSTED"?"bg-emerald-100 text-emerald-700":"bg-amber-100 text-amber-700"}`}>{r.status==="POSTED"?"مرحلة":"مسودة"}</span></td><td className={stickyActionCell}><div className="flex flex-wrap gap-2 whitespace-nowrap"><button onClick={()=>void openDetail(r.id)} className="rounded-lg border px-3 py-1.5 font-bold">عرض</button><a href={`/print/inventory-operation/${r.id}`} className="rounded-lg border px-3 py-1.5 font-bold text-[#0B2A4A]">طباعة</a>{r.status==="DRAFT"&&<button onClick={()=>void approve(r.id)} disabled={saving} className="rounded-lg bg-emerald-700 px-3 py-1.5 font-bold text-white disabled:opacity-50">ترحيل</button>}</div></td></tr>)}</tbody></table></TableScroll><Pager page={list.page} totalPages={list.totalPages} setPage={list.setPage}/></div>

      {showForm && (
        <div className="fixed inset-0 z-[230] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
          <div className="max-h-[94vh] w-full max-w-7xl overflow-auto rounded-3xl bg-white p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
              <div><h2 className="text-2xl font-black text-[#0B2A4A]">عملية مخزنية جديدة</h2><p className="text-sm text-slate-500">الوحدة الأساسية كجم. الترحيل الفعلي يتم بعد حفظ المسودة ومراجعتها.</p></div>
              <button onClick={() => setShowForm(false)} className="rounded-xl border px-4 py-2">إغلاق</button>
            </div>

            <div className="mt-5 grid gap-3 md:grid-cols-3 xl:grid-cols-5">
              <select value={form.operation_type} onChange={(e) => { const t = e.target.value; reset(t); }} className="rounded-xl border p-3">{types.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
              <input type="date" value={form.operation_date} onChange={(e) => setForm({ ...form, operation_date: e.target.value })} className="rounded-xl border p-3" />
              <select value={form.from_branch_id} onChange={(e) => setForm({ ...form, from_branch_id: e.target.value })} className="rounded-xl border p-3"><option value="">فرع المصدر</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.branch_name}</option>)}</select>
              {isTransfer ? <select value={form.to_branch_id} onChange={(e) => setForm({ ...form, to_branch_id: e.target.value })} className="rounded-xl border p-3"><option value="">الفرع المستلم</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.branch_name}</option>)}</select> : <select value={form.allocation_method} onChange={(e) => setForm({ ...form, allocation_method: e.target.value })} className="rounded-xl border p-3"><option value="RELATIVE_VALUE">التكلفة بالقيمة النسبية</option><option value="WEIGHT">حسب الوزن</option><option value="MANUAL_PERCENT">نسب يدوية</option><option value="MANUAL_COST">تكلفة يدوية</option></select>}
              <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} placeholder="ملاحظات العملية" className="rounded-xl border p-3" />
            </div>

            <div className="mt-6 grid gap-5 xl:grid-cols-2">
              <Lines title="المدخلات / المصدر" kind="from_lines" lines={form.from_lines} items={items} setLine={setLine} setForm={setForm} form={form} showAllocation={false} availableFor={availableKg} />
              {!isTransfer && !isScrap ? <Lines title="المخرجات / الناتج" kind="to_lines" lines={form.to_lines} items={items} setLine={setLine} setForm={setForm} form={form} showAllocation allocationMethod={form.allocation_method} /> : (
                <div className="rounded-3xl border border-dashed p-6 text-sm text-slate-500">{isTransfer ? "في التحويل بين الفروع لا تدخل مخرجات يدويًا؛ النظام ينشئ نفس الكميات في الفرع المستلم مع نفس تكلفة ومصدر الدفعات." : "الهالك يخرج المخزون ويولد أثرًا محاسبيًا على حساب تسوية/هالك المخزون."}</div>
              )}
            </div>



            {!isTransfer && (
              <div className="mt-5 rounded-3xl border p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <div>
                    <div className="font-black text-[#0B2A4A]">تكاليف التشغيل</div>
                    <div className="text-xs text-slate-500">عمالة، كهرباء، ماكينة أو أي تكلفة معالجة. عند الترحيل تضاف إلى تكلفة المخرجات وتثبت محاسبيًا.</div>
                  </div>
                  <button onClick={() => setForm((f:any)=>({...f,costs:[...(f.costs||[]),{...emptyCost(),currency_code:baseCurrency}]}))} className="rounded-xl border px-4 py-2 text-sm font-bold">+ تكلفة</button>
                </div>
                <div className="space-y-2">
                  {(form.costs||[]).map((c:any,i:number)=><div key={i} className="grid gap-2 rounded-2xl bg-slate-50 p-3 md:grid-cols-6">
                    <input value={c.cost_type??""} onChange={e=>setForm((f:any)=>({...f,costs:f.costs.map((x:any,n:number)=>n===i?{...x,cost_type:e.target.value}:x)}))} placeholder="نوع التكلفة" className="rounded-xl border bg-white p-2.5"/>
                    <input type="number" value={c.amount??""} onChange={e=>setForm((f:any)=>({...f,costs:f.costs.map((x:any,n:number)=>n===i?{...x,amount:e.target.value}:x)}))} placeholder="المبلغ" className="rounded-xl border bg-white p-2.5"/>
                    <select value={c.currency_code||baseCurrency} onChange={e=>setForm((f:any)=>({...f,costs:f.costs.map((x:any,n:number)=>n===i?{...x,currency_code:e.target.value}:x)}))} className="rounded-xl border bg-white p-2.5">{currencies.map((x:any)=><option key={x.currency_code} value={x.currency_code}>{x.currency_code}</option>)}</select>
                    <select value={c.payment_status??"UNPAID"} onChange={e=>setForm((f:any)=>({...f,costs:f.costs.map((x:any,n:number)=>n===i?{...x,payment_status:e.target.value}:x)}))} className="rounded-xl border bg-white p-2.5"><option value="UNPAID">غير مدفوعة</option><option value="PAID">مدفوعة</option></select>
                    <select disabled={c.payment_status!=="PAID"} value={c.financial_account_id??""} onChange={e=>setForm((f:any)=>({...f,costs:f.costs.map((x:any,n:number)=>n===i?{...x,financial_account_id:e.target.value}:x)}))} className="rounded-xl border bg-white p-2.5 disabled:bg-slate-100"><option value="">الخزينة / البنك</option>{financialAccounts.map((x:any)=><option key={x.id} value={x.id}>{x.account_name}</option>)}</select>
                    <button onClick={()=>setForm((f:any)=>({...f,costs:f.costs.filter((_:any,n:number)=>n!==i)}))} className="rounded-xl border px-3 py-2 text-rose-700">حذف</button>
                  </div>)}
                </div>
              </div>
            )}

            <div className="mt-5 grid gap-3 sm:grid-cols-4">
              <Metric label="إجمالي المدخل" value={`${fmt(inputKg)} كجم`} />
              <Metric label="إجمالي المخرج" value={`${fmt(outputKg)} كجم`} />
              <Metric label={delta >= 0 ? "الفاقد" : "الزيادة"} value={`${fmt(Math.abs(delta))} كجم`} danger={Math.abs(delta) > 0.5} />
              <Metric label="المعادلة" value={Math.abs(delta) <= 0.5 ? "متوازنة" : "تحتاج سبب"} danger={Math.abs(delta) > 0.5} />
            </div>

            {!isTransfer && Math.abs(delta) > 0.5 && <textarea value={form.loss_gain_reason} onChange={(e) => setForm({ ...form, loss_gain_reason: e.target.value })} placeholder="سبب الفاقد / الزيادة — مطلوب" rows={3} className="mt-4 w-full rounded-2xl border border-amber-300 bg-amber-50 p-3" />}

            <div className="mt-5 flex justify-end"><button onClick={save} disabled={saving} className="rounded-2xl bg-[#0B2A4A] px-7 py-3 font-black text-white disabled:opacity-50">{saving ? "جاري الحفظ..." : "حفظ كمسودة"}</button></div>
          </div>
        </div>
      )}

      {detail && <OperationDetail data={detail} onClose={() => setDetail(null)} />}
      <SystemDialog open={dialog.open} type={dialog.type} title={dialog.title} message={dialog.message} loading={saving} onClose={() => setDialog({ ...dialog, open: false })} onConfirm={() => setDialog({ ...dialog, open: false })} />
    </section>
  );
}

function Metric({ label, value, danger = false }: any) { return <div className={`rounded-2xl p-4 ${danger ? "bg-amber-50 text-amber-800" : "bg-slate-50"}`}><div className="text-xs font-bold opacity-70">{label}</div><div className="mt-1 text-xl font-black">{value}</div></div>; }

function Lines({ title, kind, lines, items, setLine, setForm, form, showAllocation, allocationMethod, availableFor }: any) {
  return <div className="rounded-3xl border p-4"><div className="mb-3 flex items-center justify-between"><div className="font-black text-[#0B2A4A]">{title}</div><button onClick={() => setForm((f: any) => ({ ...f, [kind]: [...f[kind], emptyLine()] }))} className="rounded-lg border px-3 py-1.5 text-sm font-bold">+ سطر</button></div>
    <div className="space-y-2">{lines.map((line: any, index: number) => <div key={index} className="grid gap-2 rounded-2xl bg-slate-50 p-3 md:grid-cols-[1.5fr_1fr_1fr_auto]">
      <select value={line.item_id} onChange={(e) => setLine(kind, index, "item_id", e.target.value)} className="rounded-xl border bg-white p-2.5"><option value="">اختر الصنف</option>{items.map((i: any) => <option key={i.id} value={i.id}>{i.item_code ? `${i.item_code} - ` : ""}{i.item_name}</option>)}</select>
      <div><input type="number" min="0" step="0.001" value={line.qty_kg} onChange={(e) => setLine(kind, index, "qty_kg", e.target.value)} placeholder="الكمية كجم" className="w-full rounded-xl border bg-white p-2.5" />{availableFor && line.item_id ? <div className={`mt-1 text-xs font-bold ${Number(line.qty_kg || 0) > Number(availableFor(line.item_id) || 0) ? "text-rose-600" : "text-slate-500"}`}>المتوفر: {fmt(availableFor(line.item_id))} كجم</div> : null}</div>
      {showAllocation ? allocationMethod === "RELATIVE_VALUE" ? <input type="number" min="0" step="0.000001" value={line.market_value_per_kg} onChange={(e) => setLine(kind, index, "market_value_per_kg", e.target.value)} placeholder="القيمة السوقية/كجم" className="rounded-xl border bg-white p-2.5" /> : allocationMethod === "MANUAL_PERCENT" ? <input type="number" min="0" max="100" step="0.01" value={line.allocation_percent} onChange={(e) => setLine(kind, index, "allocation_percent", e.target.value)} placeholder="نسبة التكلفة %" className="rounded-xl border bg-white p-2.5" /> : allocationMethod === "MANUAL_COST" ? <input type="number" min="0" step="0.001" value={line.total_cost} onChange={(e) => setLine(kind, index, "total_cost", e.target.value)} placeholder="التكلفة" className="rounded-xl border bg-white p-2.5" /> : <div className="rounded-xl border bg-white p-2.5 text-sm text-slate-500">حسب الوزن</div> : <input value={line.notes} onChange={(e) => setLine(kind, index, "notes", e.target.value)} placeholder="ملاحظة" className="rounded-xl border bg-white p-2.5" />}
      <button disabled={lines.length <= 1} onClick={() => setForm((f: any) => ({ ...f, [kind]: f[kind].filter((_: any, n: number) => n !== index) }))} className="rounded-xl border px-3 py-2 text-rose-700 disabled:opacity-30">حذف</button>
    </div>)}</div></div>;
}

function OperationDetail({ data, onClose }: any) {
  const o = data.operation;
  return <div className="fixed inset-0 z-[230] flex items-center justify-center bg-slate-950/60 p-4"><div className="max-h-[94vh] w-full max-w-6xl overflow-auto rounded-3xl bg-white p-5 shadow-2xl"><div className="flex items-start justify-between"><div><div className="text-xs text-slate-500">عملية مخزنية</div><h2 className="text-2xl font-black text-[#0B2A4A]">{o.operation_number}</h2><div className="text-sm text-slate-500">{typeMap[o.operation_type] || o.operation_type} • {o.operation_date}</div></div><button onClick={onClose} className="rounded-xl border px-4 py-2">إغلاق</button></div>
    <div className="mt-4 grid gap-3 sm:grid-cols-4"><Metric label="من" value={o.from_branch_name || "—"}/><Metric label="إلى" value={o.to_branch_name || o.from_branch_name || "—"}/><Metric label="مدخل" value={`${fmt(o.input_weight_kg)} كجم`}/><Metric label="مخرج" value={`${fmt(o.output_weight_kg)} كجم`}/></div>
    {o.loss_gain_reason && <div className="mt-4 rounded-2xl bg-amber-50 p-3 text-sm text-amber-800"><b>سبب فرق الوزن:</b> {o.loss_gain_reason}</div>}
    {(data.operation_costs||[]).length>0 && <div className="mt-4 rounded-2xl border p-4"><div className="mb-2 font-black text-[#0B2A4A]">تكاليف التشغيل</div><div className="grid gap-2 md:grid-cols-2">{data.operation_costs.map((c:any)=><div key={c.id} className="rounded-xl bg-slate-50 p-3 text-sm"><b>{c.cost_type}</b><div>{fmt(c.amount)} {c.currency_code||""} — {c.payment_status==="PAID"?"مدفوعة":"غير مدفوعة"}</div></div>)}</div></div>}
    <div className="mt-5 overflow-x-auto rounded-2xl border"><table className="w-full min-w-[900px] text-right text-sm"><thead className="bg-slate-100"><tr><th className="p-3">طرف</th><th>الصنف</th><th>كجم</th><th>تكلفة الكجم</th><th>التكلفة</th><th>دفعة داخلة</th><th>دفعة ناتجة</th></tr></thead><tbody>{data.lines.map((l: any) => <tr key={l.id} className="border-t"><td className="p-3 font-bold">{l.line_type === "FROM" ? "مصدر" : "ناتج"}</td><td>{l.item_code ? `${l.item_code} - ` : ""}{l.item_name}</td><td>{fmt(l.qty_kg)}</td><td>{fmt(l.unit_cost_per_kg)}</td><td>{fmt(l.total_cost)}</td><td>{l.input_lot_number || "—"}</td><td>{l.output_lot_number || "—"}</td></tr>)}</tbody></table></div>
  </div></div>;
}
