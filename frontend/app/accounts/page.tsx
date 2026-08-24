"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../api";

import ERPPage from "@/components/erp/layout/ERPPage";
import ERPHeader from "@/components/erp/layout/ERPHeader";
import ERPToolbar from "@/components/erp/layout/ERPToolbar";
import ERPCard from "@/components/erp/cards/ERPCard";
import ERPStatCard from "@/components/erp/cards/ERPStatCard";
import ERPEmpty from "@/components/erp/cards/ERPEmpty";
import ERPButton from "@/components/erp/buttons/ERPButton";
import ERPMessage from "@/components/erp/dialog/ERPMessage";
import AccountForm from "./AccountForm";
import ERPTree from "@/components/erp/tree/ERPTree";

const emptyForm = {
  account_code: "",
  account_name: "",
  account_type: "ASSET",
  normal_side: "DEBIT",
  parent_id: "",
  is_group: 0,
  allow_cost_center: 0,
  notes: "",
};

export default function AccountsPage() {
  const [accounts, setAccounts] = useState<any[]>([]);
  const [search, setSearch] = useState("");
  const [msg, setMsg] = useState<any>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<any>(emptyForm);

  useEffect(() => {
    loadAccounts();
  }, []);

  async function loadAccounts() {
    try {
      const res = await api.get("/accounts/tree");
      setAccounts(res.data.data || []);
    } catch (e: any) {
      setMsg({
        type: "error",
        title: "تعذر تحميل الحسابات",
        text: e?.response?.data?.message || "حدث خطأ أثناء تحميل شجرة الحسابات.",
      });
    }
  }

  async function saveAccount() {
    try {
      await api.post("/accounts", {
        ...form,
        parent_id: form.parent_id || null,
      });

      setMsg({
        type: "success",
        title: "تم إنشاء الحساب",
        text: "تم حفظ الحساب الجديد داخل شجرة الحسابات بنجاح.",
      });

      setShowForm(false);
      setForm(emptyForm);
      loadAccounts();
    } catch (e: any) {
      setMsg({
        type: "error",
        title: "فشل إنشاء الحساب",
        text: e?.response?.data?.message || "حدث خطأ أثناء حفظ الحساب.",
      });
    }
  }

  const filtered = useMemo(() => {
    return accounts.filter((a) =>
      `${a.account_code || ""} ${a.account_name || ""} ${a.account_type || ""}`
        .toLowerCase()
        .includes(search.toLowerCase())
    );
  }, [accounts, search]);

  return (
    <ERPPage>
      <ERPMessage msg={msg} onClose={() => setMsg(null)} />

      <ERPHeader
        title="شجرة الحسابات"
        subtitle="إدارة دليل الحسابات وربط الحسابات الافتراضية للنظام"
        actions={
          <ERPButton onClick={() => setShowForm(true)}>
            + حساب جديد
          </ERPButton>
        }
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <ERPStatCard title="إجمالي الحسابات" value={accounts.length} />
        <ERPStatCard title="حسابات رئيسية" value={accounts.filter((x) => x.is_group == 1).length} />
        <ERPStatCard title="حسابات تحليلية" value={accounts.filter((x) => x.is_group == 0).length} />
        <ERPStatCard title="نشطة" value={accounts.filter((x) => x.is_active == 1).length} />
      </div>

      <ERPToolbar>
        <input
          className="w-full rounded-2xl border bg-slate-50 p-3 outline-none focus:border-[#0B2A4A]"
          placeholder="بحث بالكود أو اسم الحساب أو النوع..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />

        <ERPButton type="secondary" onClick={loadAccounts}>
          تحديث
        </ERPButton>
      </ERPToolbar>

      <ERPCard title="الدليل المحاسبي" subtitle="عرض شجري للحسابات حسب الأب والابن">
  {filtered.length === 0 ? (
    <ERPEmpty title="لا توجد حسابات" text="لم يتم العثور على حسابات مطابقة للبحث الحالي." />
  ) : (
    <ERPTree
      rows={filtered}
      onSelect={(account: any) => {
        setMsg({
          type: "info",
          title: account.account_name,
          text: `رقم الحساب: ${account.account_code} — ${account.is_group == 1 ? "حساب تجميعي" : "حساب تحليلي"}`,
        });
      }}
    />
  )}
</ERPCard>

      {showForm && (
        <AccountForm
          form={form}
          setForm={setForm}
          accounts={accounts}
          onCancel={() => setShowForm(false)}
          onSave={saveAccount}
        />
      )}
    </ERPPage>
  );
}