"use client";

import { useEffect, useState } from "react";
import api from "@/app/api";

export type OperationalAccount = {
  id: number; account_code: string; account_name: string; gl_account_id?: number;
  gl_account_code?: string; gl_account_name?: string; currency_code?: string; account_path?: string;
};
type Props = { endpoint: string; branch?: string; value: string; label: string;
  onChange: (id: string, account?: OperationalAccount) => void; disabled?: boolean };

export function accountOptionPage(response: unknown): { data: OperationalAccount[]; last_page: number } {
  const envelope = response as { status?: boolean; message?: string; data?: { data?: OperationalAccount[]; last_page?: number } };
  if (envelope?.status !== true || !Array.isArray(envelope.data?.data)) throw new Error(envelope?.message || "استجابة الحسابات غير صالحة.");
  if (!envelope.data.data.every(a => a && typeof a === "object" && Number.isInteger(Number(a.id)) && Number(a.id) > 0 && typeof a.account_name === "string")) throw new Error("بيانات الحسابات غير صالحة.");
  return { data: envelope.data.data, last_page: Number(envelope.data.last_page || 1) };
}

/** Server-side pagination; selected identity is fetched separately from search results. */
export default function AccountSearchSelect({ endpoint, branch, value, label, onChange, disabled }: Props) {
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [accounts, setAccounts] = useState<OperationalAccount[]>([]);
  const [selected, setSelected] = useState<OperationalAccount>();
  const [last, setLast] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    const abort = new AbortController();
    const timer = setTimeout(() => {
      setLoading(true); setError("");
      api.get(endpoint, { signal: abort.signal, params: { search: query, page, per_page: 25, branch_id: branch || undefined } })
        .then(r => { if (abort.signal.aborted) return; const result = accountOptionPage(r.data); setAccounts(result.data); setLast(result.last_page); })
        .catch(e => { if (!abort.signal.aborted) { setAccounts([]); setError(e?.response?.data?.message || e.message); } })
        .finally(() => { if (!abort.signal.aborted) setLoading(false); });
    }, 250);
    return () => { clearTimeout(timer); abort.abort(); };
  }, [endpoint, branch, query, page]);
  useEffect(() => {
    const abort = new AbortController();
    if (!value) return () => abort.abort();
    api.get(endpoint, { signal: abort.signal, params: { selected_id: value, branch_id: branch || undefined } })
      .then(r => { if (!abort.signal.aborted) setSelected(accountOptionPage(r.data).data[0]); })
      .catch(e => { if (!abort.signal.aborted) { setSelected(undefined); setError(e?.response?.data?.message || e.message); } });
    return () => abort.abort();
  }, [value, endpoint, branch]);
  const options = selected && String(selected.id) === value && !accounts.some(a => a.id === selected.id) ? [selected, ...accounts] : accounts;
  return <div dir="rtl" className="space-y-1 text-sm">
    <label className="block font-bold">{label}
      <input aria-label={`بحث ${label}`} disabled={disabled} value={query} onChange={e => { setQuery(e.target.value); setPage(1); }} placeholder="ابحث برقم الحساب أو اسمه" className="mt-1 w-full rounded-xl border p-2" />
      <select disabled={disabled || loading || !!error} value={value} onChange={e => { const account = options.find(a => String(a.id) === e.target.value); setSelected(account); onChange(e.target.value, account); }} className="mt-1 w-full rounded-xl border p-2">
        <option value="">اختر الحساب</option>
        {value && !options.some(a => String(a.id) === value) && <option value={value}>الحساب المحدد #{value} — غير متاح للاختيار الحالي</option>}
        {options.map(a => <option key={a.id} value={a.id}>{a.account_path || `${a.account_code} — ${a.account_name}`}{a.gl_account_code ? ` • GL ${a.gl_account_code}` : ""}{a.currency_code ? ` • ${a.currency_code}` : ""}</option>)}
      </select>
    </label>
    {error && <div role="alert" className="text-red-700">{error}</div>}
    <div aria-live="polite">{loading ? "جاري التحميل…" : !accounts.length && !error ? "لا توجد نتائج" : ""}</div>
    <div className="flex justify-between"><button type="button" disabled={disabled || loading || page <= 1} onClick={() => setPage(p => p - 1)}>السابق</button><span>{page} / {last}</span><button type="button" disabled={disabled || loading || page >= last} onClick={() => setPage(p => p + 1)}>التالي</button></div>
  </div>;
}
