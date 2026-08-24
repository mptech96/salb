"use client";

import ERPButton from "@/components/erp/buttons/ERPButton";
import ERPInput from "@/components/erp/form/ERPInput";
import ERPSelect from "@/components/erp/form/ERPSelect";
import ERPTextarea from "@/components/erp/form/ERPTextarea";

export type FixedAssetForm = {
  asset_code: string;
  asset_name: string;
  category_id: number | string;
  description: string;

  branch_id: number | string;
  location: string;
  cost_center_id: number | string;
  responsible_worker_id: number | string;

  serial_number: string;
  barcode: string;

  purchase_date: string;
  purchase_cost: number | string;
  salvage_value: number | string;

  depreciation_method:
    | "STRAIGHT_LINE"
    | "DECLINING_BALANCE"
    | "NO_DEPRECIATION";

  useful_life_months: number | string;
  annual_depreciation_rate: number | string;
  depreciation_start_date: string;

  asset_account_id: number | string;
  accumulated_account_id: number | string;
  expense_account_id: number | string;

  purchase_invoice_id: number | string;
  reference_no: string;
  notes: string;
};

type Option = {
  id: number | string;
  name?: string;
  category_name?: string;
  worker_name?: string;
  branch_name?: string;
  account_name?: string;
  account_code?: string;
};

type Props = {
  open: boolean;
  form: FixedAssetForm;
  setForm: (form: FixedAssetForm) => void;

  categories?: Option[];
  branches?: Option[];
  workers?: Option[];
  accounts?: Option[];

  loading?: boolean;
  editing?: boolean;

  onSave: () => void;
  onClose: () => void;
};

export default function AssetDialog({
  open,
  form,
  setForm,
  categories = [],
  branches = [],
  workers = [],
  accounts = [],
  loading = false,
  editing = false,
  onSave,
  onClose,
}: Props) {
  if (!open) return null;

  const depreciationDisabled =
    form.depreciation_method === "NO_DEPRECIATION";

  const categoryOptions = categories.map((item) => ({
    id: item.id,
    name:
      item.category_name ||
      item.name ||
      `فئة #${item.id}`,
  }));

  const branchOptions = branches.map((item) => ({
    id: item.id,
    name:
      item.branch_name ||
      item.name ||
      `فرع #${item.id}`,
  }));

  const workerOptions = workers.map((item) => ({
    id: item.id,
    name:
      item.worker_name ||
      item.name ||
      `موظف #${item.id}`,
  }));

  const accountOptions = accounts.map((item) => ({
    id: item.id,
    name: item.account_code
      ? `${item.account_code} - ${
          item.account_name || item.name || ""
        }`
      : item.account_name ||
        item.name ||
        `حساب #${item.id}`,
  }));

  return (
    <div className="fixed inset-0 z-[960] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm">
      <div
        dir="rtl"
        className="flex max-h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl"
      >
        <header className="border-b border-slate-200 px-5 py-4 sm:px-7">
          <h2 className="text-2xl font-black text-[#0B2A4A]">
            {editing
              ? "تعديل بيانات الأصل"
              : "تسجيل أصل ثابت جديد"}
          </h2>

          <p className="mt-2 text-sm font-semibold leading-7 text-slate-500">
            أدخل بيانات الأصل والموقع والتكلفة وإعدادات الإهلاك والحسابات
            المحاسبية.
          </p>
        </header>

        <div className="flex-1 overflow-y-auto p-5 sm:p-7">
          <div className="space-y-8">
            <AssetSection
              title="البيانات الأساسية"
              subtitle="تعريف الأصل والفئة والبيانات التعريفية"
            >
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <ERPInput
                  label="كود الأصل"
                  value={form.asset_code}
                  placeholder="مثال: AST-0001"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      asset_code: value.toUpperCase(),
                    })
                  }
                />

                <ERPInput
                  label="اسم الأصل"
                  value={form.asset_name}
                  placeholder="مثال: سيارة نقل مرسيدس"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      asset_name: value,
                    })
                  }
                />

                <ERPSelect
                  label="فئة الأصل"
                  value={form.category_id}
                  options={categoryOptions}
                  placeholder="اختر فئة الأصل"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      category_id: value,
                    })
                  }
                />

                <ERPInput
                  label="الرقم التسلسلي"
                  value={form.serial_number}
                  placeholder="الرقم التسلسلي أو رقم الهيكل"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      serial_number: value,
                    })
                  }
                />

                <ERPInput
                  label="الباركود"
                  value={form.barcode}
                  placeholder="يُنشأ يدويًا أو آليًا"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      barcode: value,
                    })
                  }
                />

                <ERPInput
                  label="المرجع"
                  value={form.reference_no}
                  placeholder="رقم العقد أو المستند"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      reference_no: value,
                    })
                  }
                />

                <div className="md:col-span-2 lg:col-span-3">
                  <ERPTextarea
                    label="وصف الأصل"
                    value={form.description}
                    placeholder="وصف ومواصفات الأصل..."
                    onChange={(value: string) =>
                      setForm({
                        ...form,
                        description: value,
                      })
                    }
                  />
                </div>
              </div>
            </AssetSection>

            <AssetSection
              title="الموقع والعهدة"
              subtitle="الفرع والموقع والموظف المسؤول عن الأصل"
            >
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <ERPSelect
                  label="الفرع"
                  value={form.branch_id}
                  options={branchOptions}
                  placeholder="اختر الفرع"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      branch_id: value,
                    })
                  }
                />

                <ERPInput
                  label="الموقع"
                  value={form.location}
                  placeholder="مثال: المستودع الرئيسي"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      location: value,
                    })
                  }
                />

                <ERPInput
                  label="مركز التكلفة"
                  type="number"
                  value={form.cost_center_id}
                  placeholder="اختياري"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      cost_center_id: value,
                    })
                  }
                />

                <ERPSelect
                  label="الموظف المسؤول"
                  value={form.responsible_worker_id}
                  options={workerOptions}
                  placeholder="اختر الموظف المسؤول"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      responsible_worker_id: value,
                    })
                  }
                />
              </div>
            </AssetSection>

            <AssetSection
              title="بيانات الشراء والتكلفة"
              subtitle="قيمة شراء الأصل والقيمة المتبقية ومرجع الفاتورة"
            >
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <ERPInput
                  label="تاريخ الشراء"
                  type="date"
                  value={form.purchase_date}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      purchase_date: value,
                    })
                  }
                />

                <ERPInput
                  label="تكلفة الشراء"
                  type="number"
                  value={form.purchase_cost}
                  placeholder="0.000"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      purchase_cost: value,
                    })
                  }
                />

                <ERPInput
                  label="القيمة المتبقية"
                  type="number"
                  value={form.salvage_value}
                  placeholder="0.000"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      salvage_value: value,
                    })
                  }
                />

                <ERPInput
                  label="رقم فاتورة الشراء"
                  type="number"
                  value={form.purchase_invoice_id}
                  placeholder="اختياري"
                  onChange={(value: number) =>
                    setForm({
                      ...form,
                      purchase_invoice_id: value,
                    })
                  }
                />
              </div>

              <div className="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold leading-7 text-blue-900">
                تبدأ القيمة الدفترية للأصل مساوية لتكلفة الشراء، ثم تنخفض
                تلقائيًا مع ترحيل الإهلاك.
              </div>
            </AssetSection>

            <AssetSection
              title="إعدادات الإهلاك"
              subtitle="طريقة الإهلاك والعمر الإنتاجي وتاريخ البداية"
            >
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <ERPSelect
                  label="طريقة الإهلاك"
                  value={form.depreciation_method}
                  onChange={(
                    value: FixedAssetForm["depreciation_method"]
                  ) =>
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
                  label="تاريخ بداية الإهلاك"
                  type="date"
                  disabled={depreciationDisabled}
                  value={form.depreciation_start_date}
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      depreciation_start_date: value,
                    })
                  }
                />
              </div>

              {depreciationDisabled && (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold leading-7 text-slate-700">
                  هذا الأصل لن يخضع للإهلاك، وتبقى قيمته الدفترية ثابتة ما لم
                  تحدث إعادة تقييم أو بيع أو شطب.
                </div>
              )}
            </AssetSection>

            <AssetSection
              title="الحسابات المحاسبية"
              subtitle="الحسابات التي ستستخدم في قيود الأصل والإهلاك"
            >
              <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <ERPSelect
                  label="حساب الأصل"
                  value={form.asset_account_id}
                  options={accountOptions}
                  placeholder="اختر حساب الأصل"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      asset_account_id: value,
                    })
                  }
                />

                <ERPSelect
                  label="حساب مجمع الإهلاك"
                  value={form.accumulated_account_id}
                  options={accountOptions}
                  disabled={depreciationDisabled}
                  placeholder="اختر حساب مجمع الإهلاك"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      accumulated_account_id: value,
                    })
                  }
                />

                <ERPSelect
                  label="حساب مصروف الإهلاك"
                  value={form.expense_account_id}
                  options={accountOptions}
                  disabled={depreciationDisabled}
                  placeholder="اختر حساب مصروف الإهلاك"
                  onChange={(value: string) =>
                    setForm({
                      ...form,
                      expense_account_id: value,
                    })
                  }
                />
              </div>
            </AssetSection>

            <AssetSection
              title="ملاحظات إضافية"
              subtitle="أي معلومات إدارية أو تشغيلية مرتبطة بالأصل"
            >
              <ERPTextarea
                label="الملاحظات"
                value={form.notes}
                placeholder="الملاحظات والضمان وشروط الاستخدام..."
                onChange={(value: string) =>
                  setForm({
                    ...form,
                    notes: value,
                  })
                }
              />
            </AssetSection>
          </div>
        </div>

        <footer className="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row">
          <ERPButton onClick={onSave} disabled={loading}>
            {loading
              ? "جاري الحفظ..."
              : editing
                ? "حفظ التعديلات"
                : "تسجيل الأصل"}
          </ERPButton>

          <ERPButton
            type="secondary"
            onClick={onClose}
            disabled={loading}
          >
            إلغاء
          </ERPButton>
        </footer>
      </div>
    </div>
  );
}

function AssetSection({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="border-b border-slate-100 pb-4">
        <h3 className="text-lg font-black text-[#0B2A4A]">
          {title}
        </h3>

        <p className="mt-1 text-sm font-semibold text-slate-500">
          {subtitle}
        </p>
      </div>

      <div className="pt-5">{children}</div>
    </section>
  );
}