"use client";

import ERPInput from "@/components/erp/form/ERPInput";
import ERPSelect from "@/components/erp/form/ERPSelect";
import ERPTextarea from "@/components/erp/form/ERPTextarea";
import ERPButton from "@/components/erp/buttons/ERPButton";

export default function AccountForm({ form, setForm, accounts, onSave, onCancel }: any) {
  return (
    <div className="fixed inset-0 z-[999] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
      <div className="w-full max-w-3xl rounded-3xl bg-white p-6 shadow-2xl" dir="rtl">
        <h2 className="text-2xl font-black text-[#0B2A4A]">إضافة حساب جديد</h2>
        <p className="mt-1 text-sm font-semibold text-slate-500">
          الحساب الرئيسي يكون تجميعي، والحساب التحليلي يسمح بالحركة والقيود.
        </p>

        <div className="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
          <ERPInput label="رقم الحساب" value={form.account_code} onChange={(v: any) => setForm({ ...form, account_code: v })} />
          <ERPInput label="اسم الحساب" value={form.account_name} onChange={(v: any) => setForm({ ...form, account_name: v })} />

          <ERPSelect
            label="نوع الحساب"
            value={form.account_type}
            onChange={(v: any) => setForm({ ...form, account_type: v })}
            options={[
              { id: "ASSET", name: "أصل" },
              { id: "LIABILITY", name: "التزام" },
              { id: "EQUITY", name: "حقوق ملكية" },
              { id: "REVENUE", name: "إيراد" },
              { id: "EXPENSE", name: "مصروف" },
            ]}
          />

          <ERPSelect
            label="طبيعة الحساب"
            value={form.normal_side}
            onChange={(v: any) => setForm({ ...form, normal_side: v })}
            options={[
              { id: "DEBIT", name: "مدين" },
              { id: "CREDIT", name: "دائن" },
            ]}
          />

          <ERPSelect
            label="الحساب الأب"
            value={form.parent_id}
            onChange={(v: any) => setForm({ ...form, parent_id: v })}
            options={accounts.filter((x: any) => x.is_group == 1)}
            nameKey="account_name"
            placeholder="بدون حساب أب"
          />

          <ERPSelect
            label="نوع الحركة"
            value={form.is_group}
            onChange={(v: any) => setForm({ ...form, is_group: Number(v) })}
            options={[
              { id: 1, name: "حساب تجميعي" },
              { id: 0, name: "حساب تحليلي يسمح بالحركة" },
            ]}
          />

          <div className="md:col-span-2">
            <ERPTextarea label="ملاحظات" value={form.notes} onChange={(v: any) => setForm({ ...form, notes: v })} />
          </div>
        </div>

        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
          <ERPButton onClick={onSave}>حفظ الحساب</ERPButton>
          <ERPButton type="secondary" onClick={onCancel}>إلغاء</ERPButton>
        </div>
      </div>
    </div>
  );
}