"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import { readSession } from "@/lib/session";

const emptyLine = () => ({
  account_id: "",
  debit: "",
  credit: "",
  description: "",
});

const today = () => new Date().toISOString().slice(0, 10);

const sourceLabel = (source: string) => {
  const labels: Record<string, string> = {
    MANUAL: "يدوي",
    REVERSAL: "عكسي",
    SALE: "مبيعات",
    PURCHASE: "مشتريات",
    VOUCHER: "سند",
    EXPENSE: "مصروف",
    INVENTORY: "مخزون",
    YEAR_CLOSE_PNL: "إقفال نتائج",
    YEAR_CLOSE_RETAINED: "إقفال أرباح محتجزة",
    YEAR_REOPEN: "إعادة فتح سنة",
  };

  return labels[String(source || "").toUpperCase()] || source || "—";
};

export default function JournalEntriesPage() {
  const [entries, setEntries] = useState<any[]>([]);
  const [accounts, setAccounts] = useState<any[]>([]);
  const [branches, setBranches] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [reverseTarget, setReverseTarget] = useState<any>(null);
  const [reverseReason, setReverseReason] = useState("");
  const [reverseDate, setReverseDate] = useState(today());
  const [accountSearch, setAccountSearch] = useState("");

  const [filters, setFilters] = useState({
    q: "",
    from_date: "",
    to_date: "",
    source_type: "",
  });

  const [quick, setQuick] = useState({
    debit_account_id: "",
    credit_account_id: "",
    amount: "",
    description: "",
  });

  const [form, setForm] = useState<any>({
    branch_id: "",
    entry_date: today(),
    reference_no: "",
    description: "",
    lines: [emptyLine(), emptyLine()],
  });

  const [dialog, setDialog] = useState<any>({
    open: false,
    type: "info",
    title: "",
    message: "",
  });

  const session = readSession();
  const role = String(session?.user?.role?.role_code || "").toUpperCase();
  const companyWide =
    [
      "MANAGER",
      "COMPANY_MANAGER",
      "COMPANY_ADMIN",
      "COMPANY_OWNER",
      "ADMIN",
    ].includes(role) || Boolean(session?.user?.is_support_mode);

  const totals = useMemo(
    () =>
      form.lines.reduce(
        (acc: any, line: any) => ({
          debit: acc.debit + Number(line.debit || 0),
          credit: acc.credit + Number(line.credit || 0),
        }),
        { debit: 0, credit: 0 }
      ),
    [form.lines]
  );

  const difference = Number((totals.debit - totals.credit).toFixed(3));

  const visibleAccounts = useMemo(() => {
    const q = accountSearch.trim().toLowerCase();

    if (!q) return accounts;

    return accounts.filter((account) =>
      `${account.account_code} ${account.account_name} ${account.account_type}`
        .toLowerCase()
        .includes(q)
    );
  }, [accounts, accountSearch]);

  async function loadEntries(nextFilters = filters) {
    setLoading(true);

    try {
      const response = await api.get("/journal-entries", {
        params: Object.fromEntries(
          Object.entries(nextFilters).filter(([, value]) => value !== "")
        ),
      });

      setEntries(response.data.data || []);
    } catch (e: any) {
      setEntries([]);
      setDialog({
        open: true,
        type: "error",
        title: "تعذر تحميل القيود",
        message:
          e?.response?.data?.message ||
          "تعذر قراءة دفتر اليومية.",
      });
    } finally {
      setLoading(false);
    }
  }

  async function loadMeta() {
    const [accountsResult, branchesResult] = await Promise.allSettled([
      api.get("/accounts/posting"),
      api.get("/branches"),
    ]);

    setAccounts(
      accountsResult.status === "fulfilled"
        ? accountsResult.value.data.data || []
        : []
    );

    setBranches(
      branchesResult.status === "fulfilled"
        ? branchesResult.value.data.data || []
        : []
    );
  }

  useEffect(() => {
    void loadMeta();
    void loadEntries();
  }, []);

  function resetForm() {
    setForm({
      branch_id: "",
      entry_date: today(),
      reference_no: "",
      description: "",
      lines: [emptyLine(), emptyLine()],
    });

    setQuick({
      debit_account_id: "",
      credit_account_id: "",
      amount: "",
      description: "",
    });

    setAccountSearch("");
  }

  function updateLine(index: number, key: string, value: any) {
    setForm((current: any) => ({
      ...current,
      lines: current.lines.map((line: any, lineIndex: number) =>
        lineIndex === index ? { ...line, [key]: value } : line
      ),
    }));
  }

  function updateAmount(
    index: number,
    side: "debit" | "credit",
    value: string
  ) {
    setForm((current: any) => ({
      ...current,
      lines: current.lines.map((line: any, lineIndex: number) => {
        if (lineIndex !== index) return line;

        return {
          ...line,
          [side]: value,
          [side === "debit" ? "credit" : "debit"]:
            Number(value || 0) > 0 ? "" : line[side === "debit" ? "credit" : "debit"],
        };
      }),
    }));
  }

  function applyQuickEntry() {
    const amount = Number(quick.amount || 0);

    if (
      !quick.debit_account_id ||
      !quick.credit_account_id ||
      amount <= 0
    ) {
      setDialog({
        open: true,
        type: "warning",
        title: "أكمل القيد السريع",
        message: "اختر الحساب المدين والدائن وأدخل مبلغًا أكبر من صفر.",
      });
      return;
    }

    if (quick.debit_account_id === quick.credit_account_id) {
      setDialog({
        open: true,
        type: "warning",
        title: "الحسابان متطابقان",
        message: "اختر حسابين مختلفين للقيد السريع.",
      });
      return;
    }

    const description =
      quick.description.trim() || "قيد يومي بسيط";

    setForm((current: any) => ({
      ...current,
      description,
      lines: [
        {
          account_id: quick.debit_account_id,
          debit: amount.toFixed(3),
          credit: "",
          description,
        },
        {
          account_id: quick.credit_account_id,
          debit: "",
          credit: amount.toFixed(3),
          description,
        },
      ],
    }));
  }

  function validateForm() {
    if (!form.description.trim()) {
      return "اكتب بيانًا واضحًا للقيد.";
    }

    if (companyWide && !form.branch_id) {
      return "اختر الفرع الذي يخص القيد.";
    }

    if (form.lines.length < 2) {
      return "القيد يجب أن يحتوي على طرفين على الأقل.";
    }

    for (let i = 0; i < form.lines.length; i++) {
      const line = form.lines[i];
      const debit = Number(line.debit || 0);
      const credit = Number(line.credit || 0);

      if (!line.account_id) {
        return `اختر الحساب في السطر رقم ${i + 1}.`;
      }

      if (debit < 0 || credit < 0) {
        return `لا تقبل مبالغ سالبة في السطر رقم ${i + 1}.`;
      }

      if ((debit > 0 && credit > 0) || (debit === 0 && credit === 0)) {
        return `حدد مدينًا أو دائنًا فقط في السطر رقم ${i + 1}.`;
      }
    }

    if (totals.debit <= 0) {
      return "قيمة القيد يجب أن تكون أكبر من صفر.";
    }

    if (Math.abs(difference) > 0.0001) {
      return `القيد غير متوازن. الفرق ${Math.abs(difference).toFixed(3)}.`;
    }

    return "";
  }

  async function save() {
    const validationMessage = validateForm();

    if (validationMessage) {
      setDialog({
        open: true,
        type: "warning",
        title: "راجع القيد",
        message: validationMessage,
      });
      return;
    }

    setSaving(true);

    try {
      await api.post("/journal-entries", form);

      setDialog({
        open: true,
        type: "success",
        title: "تم ترحيل القيد",
        message: "تم حفظ القيد وترحيله إلى دفتر الأستاذ بنجاح.",
      });

      setShowForm(false);
      resetForm();
      await loadEntries();
    } catch (e: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر ترحيل القيد",
        message:
          e?.response?.data?.message ||
          "حدث خطأ أثناء ترحيل القيد.",
      });
    } finally {
      setSaving(false);
    }
  }

  async function openDetail(id: number) {
    try {
      const response = await api.get(`/journal-entries/${id}`);
      setDetail(response.data.data);
    } catch (e: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر فتح القيد",
        message:
          e?.response?.data?.message ||
          "تعذر قراءة تفاصيل القيد.",
      });
    }
  }

  function startReverse(entry: any) {
    setReverseTarget(entry);
    setReverseReason("");
    setReverseDate(today());
  }

  async function reverseEntry() {
    if (!reverseTarget) return;

    if (reverseReason.trim().length < 5) {
      setDialog({
        open: true,
        type: "warning",
        title: "سبب العكس مطلوب",
        message: "اكتب سببًا واضحًا لعكس القيد.",
      });
      return;
    }

    setSaving(true);

    try {
      await api.post(
        `/journal-entries/${reverseTarget.id}/reverse`,
        {
          entry_date: reverseDate,
          reason: reverseReason,
        }
      );

      setReverseTarget(null);
      setReverseReason("");
      setDialog({
        open: true,
        type: "success",
        title: "تم عكس القيد",
        message: "تم إنشاء قيد عكسي مستقل وحفظ أثر القيد الأصلي.",
      });

      await loadEntries();
    } catch (e: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر عكس القيد",
        message:
          e?.response?.data?.message ||
          "تعذر إنشاء القيد العكسي.",
      });
    } finally {
      setSaving(false);
    }
  }

  return (
    <section dir="rtl" className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-[#0B2A4A] p-6 text-white">
        <div>
          <div className="text-sm text-blue-100">المحاسبة العامة</div>
          <h1 className="text-3xl font-black">دفتر اليومية</h1>
          <p className="mt-1 text-blue-100">
            القيود اليدوية للحركات الاستثنائية، بينما عمليات النظام تُرحّل تلقائيًا.
          </p>
        </div>

        <button
          onClick={() => {
            resetForm();
            setShowForm(true);
          }}
          className="rounded-2xl bg-white px-5 py-3 font-black text-[#0B2A4A]"
        >
          + قيد يومي
        </button>
      </div>

      <div className="grid gap-3 rounded-3xl border bg-white p-4 shadow-sm md:grid-cols-5">
        <input
          value={filters.q}
          onChange={(e) => setFilters({ ...filters, q: e.target.value })}
          placeholder="بحث برقم القيد أو المرجع أو البيان..."
          className="rounded-2xl border p-3 md:col-span-2"
        />
        <input
          type="date"
          value={filters.from_date}
          onChange={(e) =>
            setFilters({ ...filters, from_date: e.target.value })
          }
          className="rounded-2xl border p-3"
        />
        <input
          type="date"
          value={filters.to_date}
          onChange={(e) =>
            setFilters({ ...filters, to_date: e.target.value })
          }
          className="rounded-2xl border p-3"
        />
        <button
          onClick={() => void loadEntries()}
          className="rounded-2xl bg-[#0B2A4A] px-4 py-3 font-bold text-white"
        >
          تطبيق البحث
        </button>
      </div>

      {showForm && (
        <div className="space-y-5 rounded-3xl border bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-xl font-black text-[#0B2A4A]">
                قيد يومي جديد
              </h2>
              <p className="text-sm text-slate-500">
                بعد الترحيل لا يتم حذف القيد؛ التصحيح يكون بقيد عكسي.
              </p>
            </div>
            <button
              onClick={() => setShowForm(false)}
              className="rounded-xl border px-4 py-2"
            >
              إغلاق
            </button>
          </div>

          <div className="grid gap-3 md:grid-cols-4">
            <label className="space-y-1">
              <span className="text-xs font-bold text-slate-500">التاريخ</span>
              <input
                type="date"
                value={form.entry_date}
                onChange={(e) =>
                  setForm({ ...form, entry_date: e.target.value })
                }
                className="w-full rounded-2xl border p-3"
              />
            </label>

            {companyWide && (
              <label className="space-y-1">
                <span className="text-xs font-bold text-slate-500">الفرع</span>
                <select
                  value={form.branch_id}
                  onChange={(e) =>
                    setForm({ ...form, branch_id: e.target.value })
                  }
                  className="w-full rounded-2xl border p-3"
                >
                  <option value="">اختر الفرع</option>
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.branch_name}
                    </option>
                  ))}
                </select>
              </label>
            )}

            <label className="space-y-1">
              <span className="text-xs font-bold text-slate-500">
                المرجع — اختياري
              </span>
              <input
                value={form.reference_no}
                onChange={(e) =>
                  setForm({ ...form, reference_no: e.target.value })
                }
                placeholder="مثال: تحويل بنكي 125"
                className="w-full rounded-2xl border p-3"
              />
            </label>

            <label className="space-y-1 md:col-span-2">
              <span className="text-xs font-bold text-slate-500">
                بيان القيد
              </span>
              <input
                value={form.description}
                onChange={(e) =>
                  setForm({ ...form, description: e.target.value })
                }
                placeholder="مثال: تحويل من البنك إلى الصندوق"
                className="w-full rounded-2xl border p-3"
              />
            </label>
          </div>

          <div className="rounded-3xl border border-blue-100 bg-blue-50/50 p-4">
            <div className="mb-3">
              <div className="font-black text-[#0B2A4A]">قيد سريع</div>
              <div className="text-xs text-slate-500">
                للحركات البسيطة من حساب إلى حساب.
              </div>
            </div>

            <div className="grid gap-3 md:grid-cols-5">
              <select
                value={quick.debit_account_id}
                onChange={(e) =>
                  setQuick({
                    ...quick,
                    debit_account_id: e.target.value,
                  })
                }
                className="rounded-2xl border bg-white p-3"
              >
                <option value="">الحساب المدين</option>
                {accounts.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.account_code} - {account.account_name}
                  </option>
                ))}
              </select>

              <select
                value={quick.credit_account_id}
                onChange={(e) =>
                  setQuick({
                    ...quick,
                    credit_account_id: e.target.value,
                  })
                }
                className="rounded-2xl border bg-white p-3"
              >
                <option value="">الحساب الدائن</option>
                {accounts.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.account_code} - {account.account_name}
                  </option>
                ))}
              </select>

              <input
                type="number"
                min="0"
                step="0.001"
                value={quick.amount}
                onChange={(e) =>
                  setQuick({ ...quick, amount: e.target.value })
                }
                placeholder="المبلغ"
                className="rounded-2xl border bg-white p-3"
              />

              <input
                value={quick.description}
                onChange={(e) =>
                  setQuick({
                    ...quick,
                    description: e.target.value,
                  })
                }
                placeholder="البيان"
                className="rounded-2xl border bg-white p-3"
              />

              <button
                onClick={applyQuickEntry}
                className="rounded-2xl border border-[#0B2A4A] bg-white px-4 py-3 font-black text-[#0B2A4A]"
              >
                تعبئة القيد
              </button>
            </div>
          </div>

          <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="font-black text-[#0B2A4A]">أطراف القيد</div>
                <div className="text-xs text-slate-500">
                  تظهر الحسابات التحليلية القابلة للترحيل فقط.
                </div>
              </div>

              <input
                value={accountSearch}
                onChange={(e) => setAccountSearch(e.target.value)}
                placeholder="تصفية الحسابات بالكود أو الاسم..."
                className="rounded-2xl border p-2.5"
              />
            </div>

            <div className="overflow-x-auto rounded-2xl border">
              <table className="w-full min-w-[900px] text-right">
                <thead className="bg-slate-100">
                  <tr>
                    <th className="p-3">#</th>
                    <th className="p-3">الحساب</th>
                    <th className="p-3">مدين</th>
                    <th className="p-3">دائن</th>
                    <th className="p-3">بيان السطر</th>
                    <th className="p-3"></th>
                  </tr>
                </thead>
                <tbody>
                  {form.lines.map((line: any, index: number) => {
                    const options = visibleAccounts.some(
                      (a) => String(a.id) === String(line.account_id)
                    )
                      ? visibleAccounts
                      : [
                          ...accounts.filter(
                            (a) =>
                              String(a.id) === String(line.account_id)
                          ),
                          ...visibleAccounts,
                        ];

                    return (
                      <tr key={index} className="border-t">
                        <td className="p-3 font-bold text-slate-400">
                          {index + 1}
                        </td>
                        <td className="p-2">
                          <select
                            value={line.account_id}
                            onChange={(e) =>
                              updateLine(
                                index,
                                "account_id",
                                e.target.value
                              )
                            }
                            className="w-full min-w-[280px] rounded-xl border p-2.5"
                          >
                            <option value="">اختر الحساب</option>
                            {options.map((account) => (
                              <option key={account.id} value={account.id}>
                                {account.account_code} -{" "}
                                {account.account_name}
                              </option>
                            ))}
                          </select>
                        </td>

                        <td className="p-2">
                          <input
                            type="number"
                            min="0"
                            step="0.001"
                            value={line.debit}
                            onChange={(e) =>
                              updateAmount(
                                index,
                                "debit",
                                e.target.value
                              )
                            }
                            className="w-36 rounded-xl border p-2.5"
                          />
                        </td>

                        <td className="p-2">
                          <input
                            type="number"
                            min="0"
                            step="0.001"
                            value={line.credit}
                            onChange={(e) =>
                              updateAmount(
                                index,
                                "credit",
                                e.target.value
                              )
                            }
                            className="w-36 rounded-xl border p-2.5"
                          />
                        </td>

                        <td className="p-2">
                          <input
                            value={line.description}
                            onChange={(e) =>
                              updateLine(
                                index,
                                "description",
                                e.target.value
                              )
                            }
                            placeholder="اختياري"
                            className="w-full min-w-[220px] rounded-xl border p-2.5"
                          />
                        </td>

                        <td className="p-2">
                          <button
                            disabled={form.lines.length <= 2}
                            onClick={() =>
                              setForm((current: any) => ({
                                ...current,
                                lines: current.lines.filter(
                                  (_: any, lineIndex: number) =>
                                    lineIndex !== index
                                ),
                              }))
                            }
                            className="rounded-xl border px-3 py-2 text-rose-600 disabled:opacity-30"
                          >
                            حذف
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-between gap-3">
            <button
              onClick={() =>
                setForm((current: any) => ({
                  ...current,
                  lines: [...current.lines, emptyLine()],
                }))
              }
              className="rounded-xl border px-4 py-2 font-bold"
            >
              + إضافة سطر
            </button>

            <div className="flex flex-wrap gap-2">
              <div className="rounded-xl bg-slate-100 px-4 py-2 font-bold">
                المدين: {totals.debit.toFixed(3)}
              </div>
              <div className="rounded-xl bg-slate-100 px-4 py-2 font-bold">
                الدائن: {totals.credit.toFixed(3)}
              </div>
              <div
                className={`rounded-xl px-4 py-2 font-black ${
                  Math.abs(difference) < 0.0001 && totals.debit > 0
                    ? "bg-emerald-50 text-emerald-700"
                    : "bg-rose-50 text-rose-700"
                }`}
              >
                الفرق: {difference.toFixed(3)}
              </div>
            </div>

            <button
              onClick={save}
              disabled={saving}
              className="rounded-xl bg-[#0B2A4A] px-6 py-2.5 font-black text-white disabled:opacity-50"
            >
              {saving ? "جاري الترحيل..." : "ترحيل القيد"}
            </button>
          </div>
        </div>
      )}

      <div className="overflow-x-auto rounded-3xl border bg-white shadow-sm">
        <table className="w-full min-w-[1100px] text-right">
          <thead className="bg-slate-100">
            <tr>
              <th className="p-3">رقم القيد</th>
              <th className="p-3">التاريخ</th>
              <th className="p-3">المرجع</th>
              <th className="p-3">المصدر</th>
              <th className="p-3">الفرع</th>
              <th className="p-3">البيان</th>
              <th className="p-3">الإجمالي</th>
              <th className="p-3">الحالة</th>
              <th className="p-3">الإجراء</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td
                  colSpan={9}
                  className="p-8 text-center text-slate-500"
                >
                  جاري تحميل القيود...
                </td>
              </tr>
            ) : entries.length === 0 ? (
              <tr>
                <td
                  colSpan={9}
                  className="p-8 text-center text-slate-500"
                >
                  لا توجد قيود مطابقة.
                </td>
              </tr>
            ) : (
              entries.map((entry) => (
                <tr key={entry.id} className="border-t">
                  <td className="p-3 font-black text-[#0B2A4A]">
                    {entry.entry_number}
                  </td>
                  <td className="p-3">{entry.entry_date}</td>
                  <td className="p-3">{entry.reference_no || "—"}</td>
                  <td className="p-3">{sourceLabel(entry.source_type)}</td>
                  <td className="p-3">
                    {entry.branch_name || "مستوى الشركة"}
                  </td>
                  <td className="max-w-[320px] truncate p-3">
                    {entry.description}
                  </td>
                  <td className="p-3 font-bold">
                    {Number(entry.total_debit || 0).toFixed(3)}
                  </td>
                  <td className="p-3">
                    {entry.reversed_at ? (
                      <span className="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700">
                        معكوس
                      </span>
                    ) : entry.reversal_of_id ? (
                      <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">
                        قيد عكسي
                      </span>
                    ) : (
                      <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                        مرحّل
                      </span>
                    )}
                  </td>
                  <td className="p-3">
                    <div className="flex gap-2">
                      <a href={`/print/journal/${entry.id}`} className="rounded-xl border px-3 py-1.5 font-bold text-[#0B2A4A]">طباعة</a>
                      <button
                        onClick={() => void openDetail(entry.id)}
                        className="rounded-xl border px-3 py-1.5 font-bold"
                      >
                        عرض
                      </button>

                      {String(entry.source_type).toUpperCase() ===
                        "MANUAL" &&
                        !entry.reversed_at &&
                        !entry.reversal_of_id && (
                          <button
                            onClick={() => startReverse(entry)}
                            className="rounded-xl border border-rose-200 px-3 py-1.5 font-bold text-rose-700"
                          >
                            عكس
                          </button>
                        )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {detail && (
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40 p-4">
          <div className="max-h-[90vh] w-full max-w-5xl overflow-auto rounded-3xl bg-white p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-2xl font-black text-[#0B2A4A]">
                  {detail.entry.entry_number}
                </h2>
                <p className="text-sm text-slate-500">
                  {detail.entry.description}
                </p>
              </div>
              <button
                onClick={() => setDetail(null)}
                className="rounded-xl border px-4 py-2"
              >
                إغلاق
              </button>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-4">
              <div className="rounded-2xl bg-slate-50 p-3">
                <div className="text-xs text-slate-500">التاريخ</div>
                <div className="font-bold">{detail.entry.entry_date}</div>
              </div>
              <div className="rounded-2xl bg-slate-50 p-3">
                <div className="text-xs text-slate-500">المرجع</div>
                <div className="font-bold">
                  {detail.entry.reference_no || "—"}
                </div>
              </div>
              <div className="rounded-2xl bg-slate-50 p-3">
                <div className="text-xs text-slate-500">الفرع</div>
                <div className="font-bold">
                  {detail.entry.branch_name || "مستوى الشركة"}
                </div>
              </div>
              <div className="rounded-2xl bg-slate-50 p-3">
                <div className="text-xs text-slate-500">أنشأه</div>
                <div className="font-bold">
                  {detail.entry.created_by_name || "النظام"}
                </div>
              </div>
            </div>

            {detail.entry.reversal_reason && (
              <div className="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                <span className="font-black">سبب العكس: </span>
                {detail.entry.reversal_reason}
              </div>
            )}

            <div className="mt-5 overflow-x-auto rounded-2xl border">
              <table className="w-full min-w-[750px] text-right">
                <thead className="bg-slate-100">
                  <tr>
                    <th className="p-3">الحساب</th>
                    <th className="p-3">البيان</th>
                    <th className="p-3">مركز التكلفة</th>
                    <th className="p-3">مدين</th>
                    <th className="p-3">دائن</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.lines.map((line: any) => (
                    <tr key={line.id} className="border-t">
                      <td className="p-3 font-bold">
                        {line.account_code} - {line.account_name}
                      </td>
                      <td className="p-3">{line.description || "—"}</td>
                      <td className="p-3">
                        {line.cost_center_name || "—"}
                      </td>
                      <td className="p-3">
                        {Number(line.debit || 0).toFixed(3)}
                      </td>
                      <td className="p-3">
                        {Number(line.credit || 0).toFixed(3)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {reverseTarget && (
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-xl rounded-3xl bg-white p-6 shadow-2xl">
            <h2 className="text-2xl font-black text-[#0B2A4A]">
              عكس القيد {reverseTarget.entry_number}
            </h2>
            <p className="mt-1 text-sm text-slate-500">
              لن يتم حذف القيد الأصلي. سيُنشئ النظام قيدًا عكسيًا مستقلًا.
            </p>

            <div className="mt-5 space-y-4">
              <label className="block space-y-1">
                <span className="text-sm font-bold">تاريخ العكس</span>
                <input
                  type="date"
                  value={reverseDate}
                  min={reverseTarget.entry_date}
                  onChange={(e) => setReverseDate(e.target.value)}
                  className="w-full rounded-2xl border p-3"
                />
              </label>

              <label className="block space-y-1">
                <span className="text-sm font-bold">سبب العكس</span>
                <textarea
                  value={reverseReason}
                  onChange={(e) => setReverseReason(e.target.value)}
                  rows={4}
                  placeholder="مثال: تم تسجيل التحويل على حساب بنك غير صحيح"
                  className="w-full rounded-2xl border p-3"
                />
              </label>
            </div>

            <div className="mt-5 flex justify-end gap-2">
              <button
                onClick={() => setReverseTarget(null)}
                className="rounded-xl border px-4 py-2"
              >
                إلغاء
              </button>
              <button
                onClick={reverseEntry}
                disabled={saving}
                className="rounded-xl bg-rose-700 px-5 py-2 font-black text-white disabled:opacity-50"
              >
                {saving ? "جاري العكس..." : "إنشاء قيد عكسي"}
              </button>
            </div>
          </div>
        </div>
      )}

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        loading={saving}
        onClose={() => setDialog({ ...dialog, open: false })}
        onConfirm={() => setDialog({ ...dialog, open: false })}
      />
    </section>
  );
}
