"use client";

import ERPInput from "@/components/erp/form/ERPInput";
import ERPSelect from "@/components/erp/form/ERPSelect";
import ERPTextarea from "@/components/erp/form/ERPTextarea";
import ERPButton from "@/components/erp/buttons/ERPButton";

export type AssetCategoryForm = {
  category_code: string;
  category_name: string;
  description: string;
  depreciation_method:
    | "STRAIGHT_LINE"
    | "DECLINING_BALANCE"
    | "NO_DEPRECIATION";
  useful_life_months: number | string;
  annual_depreciation_rate: number | string;
  default_salvage_percentage: number | string;
  asset_account_id: number | string;
  accumulated_depreciation_account_id: number | string;
  depreciation_expense_account_id: number | string;
  disposal_gain_account_id: number | string;
  disposal_loss_account_id: number | string;
};

type AccountOption = {
  id: number;
  account_code?: string;
  account_name?: string;
  name?: string;
};

type Props = {
  open: boolean;
  form: AssetCategoryForm;
  setForm: (form: AssetCategoryForm) => void;
  accounts?: AccountOption[];
  loading?: boolean;
  editing?: boolean;
  onSave: () => void;
  onClose: () => void;
};

export default function CategoryDialog({
  open,
  form,
  setForm,
  accounts = [],
  loading = false,
  editing = false,
  onSave,
  onClose,
}: Props) {
  if (!open) return null;

  const accountOptions = accounts.map((account) => ({
    ...account,
    display_name: account.account_code
      ? `${account.account_code} - ${account.account_name || account.name || ""}`
      : account.account_name || account.name || `حساب #${account.id}`,
  }));

  const depreciationDisabled =
    form.depreciation_method === "NO_DEPRECIATION";

  return (
    <div className="fixed inset-0 z-[950] flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm">
      <div
        dir="rtl"
        className="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
      >
        <div className="border-b border-slate-200 px-5 py-4 sm:px-6">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            {editing ? "تعديل فئة أصل" : "إضافة فئة أصل جديدة"}
          </h2>

          <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
            حدّد بيانات الفئة وإعدادات الإهلاك والحسابات المحاسبية الافتراضية.
          </p>
        </div>

        <div className="flex-1 overflow-y-auto p-5 sm:p-6">
          <div className="space-y-6">
            <section>
              <SectionTitle
                title="البيانات الأساسية"
                subtitle="الكود والاسم والوصف العام للفئة"
              />

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <ERPInput
                  label="كود الفئة"
                  value={form.category_code}
                  placeholder="مثال: VEHICLES"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      category_code: value.toUpperCase(),
                    })
                  }
                />

                <ERPInput
                  label="اسم الفئة"
                  value={form.category_name}
                  placeholder="مثال: السيارات"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      category_name: value,
                    })
                  }
                />

                <div className="md:col-span-2">
                  <ERPTextarea
                    label="الوصف"
                    value={form.description}
                    placeholder="وصف مختصر للأصول التابعة لهذه الفئة..."
                    onChange={(value: string) =>
                      setForm({
                        ...form,
                        description: value,
                      })
                    }
                  />
                </div>
              </div>
            </section>

            <section>
              <SectionTitle
                title="إعدادات الإهلاك"
                subtitle="تُستخدم تلقائيًا عند إنشاء أصل جديد ضمن هذه الفئة"
              />

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <ERPSelect
                  label="طريقة الإهلاك"
                  value={form.depreciation_method}
                  onChange={(value: AssetCategoryForm["depreciation_method"]) =>
                    setForm({
                      ...form,
                      depreciation_method: value,
                    })
                  }
                  options={[
                    {
                      id: "STRAIGHT_LINE",
                      name: "القسط الثابت",
                    },
                    {
                      id: "DECLINING_BALANCE",
                      name: "الرصيد المتناقص",
                    },
                    {
                      id: "NO_DEPRECIATION",
                      name: "بدون إهلاك",
                    },
                  ]}
                />

                <ERPInput
                  label="العمر الإنتاجي بالأشهر"
                  type="number"
                  disabled={depreciationDisabled}
                  value={form.useful_life_months}
                  placeholder="مثال: 60"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      useful_life_months: value,
                    })
                  }
                />

                <ERPInput
                  label="نسبة الإهلاك السنوية %"
                  type="number"
                  disabled={depreciationDisabled}
                  value={form.annual_depreciation_rate}
                  placeholder="مثال: 20"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      annual_depreciation_rate: value,
                    })
                  }
                />

                <ERPInput
                  label="نسبة القيمة المتبقية %"
                  type="number"
                  disabled={depreciationDisabled}
                  value={form.default_salvage_percentage}
                  placeholder="مثال: 5"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      default_salvage_percentage: value,
                    })
                  }
                />
              </div>

              {depreciationDisabled && (
                <div className="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold leading-7 text-blue-900">
                  هذه الفئة لن تخضع للإهلاك، مثل الأراضي أو الأصول غير القابلة
                  للإهلاك.
                </div>
              )}
            </section>

            <section>
              <SectionTitle
                title="الحسابات المحاسبية"
                subtitle="تُستخدم تلقائيًا في قيود الشراء والإهلاك والبيع والشطب"
              />

              <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <ERPSelect
                  label="حساب الأصل"
                  value={form.asset_account_id}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      asset_account_id: value,
                    })
                  }
                  options={accountOptions}
                  nameKey="display_name"
                  valueKey="id"
                  placeholder="اختر حساب الأصل"
                />

                <ERPSelect
                  label="حساب مجمع الإهلاك"
                  value={form.accumulated_depreciation_account_id}
                  disabled={depreciationDisabled}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      accumulated_depreciation_account_id: value,
                    })
                  }
                  options={accountOptions}
                  nameKey="display_name"
                  valueKey="id"
                  placeholder="اختر حساب مجمع الإهلاك"
                />

                <ERPSelect
                  label="حساب مصروف الإهلاك"
                  value={form.depreciation_expense_account_id}
                  disabled={depreciationDisabled}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      depreciation_expense_account_id: value,
                    })
                  }
                  options={accountOptions}
                  nameKey="display_name"
                  valueKey="id"
                  placeholder="اختر حساب مصروف الإهلاك"
                />

                <ERPSelect
                  label="حساب أرباح بيع الأصل"
                  value={form.disposal_gain_account_id}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      disposal_gain_account_id: value,
                    })
                  }
                  options={accountOptions}
                  nameKey="display_name"
                  valueKey="id"
                  placeholder="اختر حساب الأرباح"
                />

                <ERPSelect
                  label="حساب خسائر بيع أو شطب الأصل"
                  value={form.disposal_loss_account_id}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      disposal_loss_account_id: value,
                    })
                  }
                  options={accountOptions}
                  nameKey="display_name"
                  valueKey="id"
                  placeholder="اختر حساب الخسائر"
                />
              </div>
            </section>
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <ERPButton onClick={onSave} disabled={loading}>
            {loading
              ? "جاري الحفظ..."
              : editing
                ? "حفظ التعديلات"
                : "إضافة الفئة"}
          </ERPButton>

          <ERPButton
            type="secondary"
            onClick={onClose}
            disabled={loading}
          >
            إلغاء
          </ERPButton>
        </div>
      </div>
    </div>
  );
}

function SectionTitle({
  title,
  subtitle,
}: {
  title: string;
  subtitle: string;
}) {
  return (
    <div>
      <h3 className="text-lg font-black text-slate-800">{title}</h3>
      <p className="mt-1 text-sm font-semibold text-slate-500">{subtitle}</p>
    </div>
  );
}