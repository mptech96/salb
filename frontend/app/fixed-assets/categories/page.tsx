"use client";

import { useEffect, useMemo, useState } from "react";
import api from "../../api";

import ERPPage from "@/components/erp/layout/ERPPage";
import ERPHeader from "@/components/erp/layout/ERPHeader";
import ERPToolbar from "@/components/erp/layout/ERPToolbar";
import ERPCard from "@/components/erp/cards/ERPCard";
import ERPStatCard from "@/components/erp/cards/ERPStatCard";
import ERPButton from "@/components/erp/buttons/ERPButton";
import ERPInput from "@/components/erp/form/ERPInput";
import ERPSelect from "@/components/erp/form/ERPSelect";
import ERPMessage from "@/components/erp/dialog/ERPMessage";

import CategoryDialog, {
  type AssetCategoryForm,
} from "../components/CategoryDialog";

import CategoryTable, {
  type AssetCategory,
} from "../components/CategoryTable";

import CategoryCard from "../components/CategoryCard";

type MessageState = {
  type: "success" | "error" | "info" | "warning";
  title: string;
  text: string;
} | null;

type AccountOption = {
  id: number;
  account_code?: string;
  account_name?: string;
  name?: string;
};

const emptyForm: AssetCategoryForm = {
  category_code: "",
  category_name: "",
  description: "",
  depreciation_method: "STRAIGHT_LINE",
  useful_life_months: "",
  annual_depreciation_rate: "",
  default_salvage_percentage: 0,
  asset_account_id: "",
  accumulated_depreciation_account_id: "",
  depreciation_expense_account_id: "",
  disposal_gain_account_id: "",
  disposal_loss_account_id: "",
};

export default function FixedAssetCategoriesPage() {
  const [categories, setCategories] = useState<
    AssetCategory[]
  >([]);

  const [accounts, setAccounts] = useState<
    AccountOption[]
  >([]);

  const [search, setSearch] = useState("");
  const [methodFilter, setMethodFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  const [loading, setLoading] = useState(false);
  const [accountsLoading, setAccountsLoading] =
    useState(false);

  const [saving, setSaving] = useState(false);

  const [showDialog, setShowDialog] = useState(false);
  const [editingCategory, setEditingCategory] =
    useState<AssetCategory | null>(null);

  const [form, setForm] =
    useState<AssetCategoryForm>(emptyForm);

  const [msg, setMsg] =
    useState<MessageState>(null);

  useEffect(() => {
    loadCategories();
    loadAccounts();
  }, []);

  async function loadCategories() {
    setLoading(true);

    try {
      const response = await api.get(
        "/fixed-asset-categories"
      );

      const payload = response?.data?.data;

      setCategories(
        Array.isArray(payload) ? payload : []
      );
    } catch (error: any) {
      showMessage(
        "error",
        "تعذر تحميل فئات الأصول",
        apiError(
          error,
          "حدث خطأ أثناء تحميل فئات الأصول."
        )
      );
    } finally {
      setLoading(false);
    }
  }

  async function loadAccounts() {
    setAccountsLoading(true);

    try {
      const response = await api.get(
        "/accounts/posting"
      );

      const payload = response?.data?.data;

      setAccounts(
        Array.isArray(payload)
          ? payload
          : Array.isArray(payload?.data)
          ? payload.data
          : []
      );
    } catch {
      setAccounts([]);
    } finally {
      setAccountsLoading(false);
    }
  }

  function openCreateDialog() {
    setEditingCategory(null);
    setForm({ ...emptyForm });
    setShowDialog(true);
  }

  function openEditDialog(
    category: AssetCategory
  ) {
    setEditingCategory(category);

    setForm({
      category_code:
        category.category_code || "",

      category_name:
        category.category_name || "",

      description:
        category.description || "",

      depreciation_method:
        category.depreciation_method ||
        "STRAIGHT_LINE",

      useful_life_months:
        category.useful_life_months ?? "",

      annual_depreciation_rate:
        category.annual_depreciation_rate ?? "",

      default_salvage_percentage:
        category.default_salvage_percentage ?? 0,

      asset_account_id:
        (category as any).asset_account_id ?? "",

      accumulated_depreciation_account_id:
        (category as any)
          .accumulated_depreciation_account_id ??
        "",

      depreciation_expense_account_id:
        (category as any)
          .depreciation_expense_account_id ?? "",

      disposal_gain_account_id:
        (category as any)
          .disposal_gain_account_id ?? "",

      disposal_loss_account_id:
        (category as any)
          .disposal_loss_account_id ?? "",
    });

    setShowDialog(true);
  }

  async function saveCategory() {
    if (!form.category_code.trim()) {
      showMessage(
        "warning",
        "كود الفئة مطلوب",
        "اكتب كودًا واضحًا ومميزًا لفئة الأصل."
      );

      return;
    }

    if (!form.category_name.trim()) {
      showMessage(
        "warning",
        "اسم الفئة مطلوب",
        "اكتب اسم فئة الأصل قبل الحفظ."
      );

      return;
    }

    if (
      form.depreciation_method !==
        "NO_DEPRECIATION" &&
      !number(form.useful_life_months) &&
      !number(form.annual_depreciation_rate)
    ) {
      showMessage(
        "warning",
        "إعدادات الإهلاك غير مكتملة",
        "حدد العمر الإنتاجي أو نسبة الإهلاك السنوية."
      );

      return;
    }

    setSaving(true);

    try {
      const payload = {
        category_code:
          form.category_code.trim().toUpperCase(),

        category_name:
          form.category_name.trim(),

        description:
          form.description.trim() || null,

        depreciation_method:
          form.depreciation_method,

        useful_life_months:
          form.depreciation_method ===
          "NO_DEPRECIATION"
            ? null
            : nullableNumber(
                form.useful_life_months
              ),

        annual_depreciation_rate:
          form.depreciation_method ===
          "NO_DEPRECIATION"
            ? null
            : nullableNumber(
                form.annual_depreciation_rate
              ),

        default_salvage_percentage:
          form.depreciation_method ===
          "NO_DEPRECIATION"
            ? 0
            : number(
                form.default_salvage_percentage
              ),

        asset_account_id: nullableNumber(
          form.asset_account_id
        ),

        accumulated_depreciation_account_id:
          form.depreciation_method ===
          "NO_DEPRECIATION"
            ? null
            : nullableNumber(
                form.accumulated_depreciation_account_id
              ),

        depreciation_expense_account_id:
          form.depreciation_method ===
          "NO_DEPRECIATION"
            ? null
            : nullableNumber(
                form.depreciation_expense_account_id
              ),

        disposal_gain_account_id:
          nullableNumber(
            form.disposal_gain_account_id
          ),

        disposal_loss_account_id:
          nullableNumber(
            form.disposal_loss_account_id
          ),
      };

      if (editingCategory) {
        await api.put(
          `/fixed-asset-categories/${editingCategory.id}`,
          payload
        );

        showMessage(
          "success",
          "تم تحديث فئة الأصل",
          `تم حفظ تعديلات الفئة ${payload.category_name} بنجاح.`
        );
      } else {
        await api.post(
          "/fixed-asset-categories",
          payload
        );

        showMessage(
          "success",
          "تمت إضافة فئة الأصل",
          `تم إنشاء الفئة ${payload.category_name} بنجاح.`
        );
      }

      setShowDialog(false);
      setEditingCategory(null);
      setForm({ ...emptyForm });

      await loadCategories();
    } catch (error: any) {
      showMessage(
        "error",
        editingCategory
          ? "فشل تحديث الفئة"
          : "فشل إضافة الفئة",
        apiError(
          error,
          "تعذر حفظ بيانات فئة الأصل."
        )
      );
    } finally {
      setSaving(false);
    }
  }

  async function toggleCategory(
    category: AssetCategory
  ) {
    const currentlyActive =
      category.is_active === true ||
      Number(category.is_active) === 1;

    try {
      await api.put(
        `/fixed-asset-categories/${category.id}`,
        {
          ...category,
          is_active: !currentlyActive,
        }
      );

      showMessage(
        "success",
        currentlyActive
          ? "تم إيقاف الفئة"
          : "تم تفعيل الفئة",
        `تم ${
          currentlyActive ? "إيقاف" : "تفعيل"
        } فئة ${category.category_name}.`
      );

      await loadCategories();
    } catch (error: any) {
      showMessage(
        "error",
        "تعذر تحديث حالة الفئة",
        apiError(
          error,
          "لم يتمكن النظام من تحديث حالة الفئة."
        )
      );
    }
  }

  const filteredCategories = useMemo(() => {
    const normalizedSearch = search
      .trim()
      .toLowerCase();

    return categories.filter((category) => {
      const haystack = `
        ${category.category_code || ""}
        ${category.category_name || ""}
        ${category.description || ""}
      `.toLowerCase();

      const matchesSearch =
        !normalizedSearch ||
        haystack.includes(normalizedSearch);

      const matchesMethod =
        !methodFilter ||
        category.depreciation_method ===
          methodFilter;

      const active =
        category.is_active === true ||
        Number(category.is_active) === 1;

      const matchesStatus =
        !statusFilter ||
        (statusFilter === "ACTIVE" && active) ||
        (statusFilter === "INACTIVE" &&
          !active);

      return (
        matchesSearch &&
        matchesMethod &&
        matchesStatus
      );
    });
  }, [
    categories,
    search,
    methodFilter,
    statusFilter,
  ]);

  const stats = useMemo(() => {
    return {
      total: categories.length,

      active: categories.filter(
        (category) =>
          category.is_active === true ||
          Number(category.is_active) === 1
      ).length,

      depreciable: categories.filter(
        (category) =>
          category.depreciation_method !==
          "NO_DEPRECIATION"
      ).length,

      nonDepreciable: categories.filter(
        (category) =>
          category.depreciation_method ===
          "NO_DEPRECIATION"
      ).length,
    };
  }, [categories]);

  return (
    <ERPPage>
      <ERPMessage
        msg={msg}
        onClose={() => setMsg(null)}
      />

      <ERPHeader
        title="فئات الأصول الثابتة"
        subtitle="إدارة تصنيفات الأصول وإعدادات الإهلاك والحسابات المحاسبية الافتراضية"
        actions={
          <div className="flex flex-wrap gap-2">
            <ERPButton
              onClick={openCreateDialog}
              disabled={saving}
            >
              + إضافة فئة أصل
            </ERPButton>

            <ERPButton
              type="secondary"
              onClick={() => {
                loadCategories();
                loadAccounts();
              }}
              disabled={loading}
            >
              {loading
                ? "جاري التحديث..."
                : "تحديث"}
            </ERPButton>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <ERPStatCard
          title="إجمالي الفئات"
          value={stats.total}
        />

        <ERPStatCard
          title="الفئات النشطة"
          value={stats.active}
          color="#059669"
        />

        <ERPStatCard
          title="فئات قابلة للإهلاك"
          value={stats.depreciable}
          color="#2563EB"
        />

        <ERPStatCard
          title="فئات بدون إهلاك"
          value={stats.nonDepreciable}
          color="#64748B"
        />
      </div>

      <ERPToolbar>
        <div className="grid w-full grid-cols-1 gap-3 md:grid-cols-3">
          <ERPInput
            label="بحث"
            value={search}
            onChange={setSearch}
            placeholder="ابحث بالكود أو اسم الفئة..."
          />

          <ERPSelect
            label="طريقة الإهلاك"
            value={methodFilter}
            onChange={setMethodFilter}
            placeholder="كل الطرق"
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

          <ERPSelect
            label="الحالة"
            value={statusFilter}
            onChange={setStatusFilter}
            placeholder="كل الحالات"
            options={[
              {
                id: "ACTIVE",
                name: "نشطة",
              },
              {
                id: "INACTIVE",
                name: "موقوفة",
              },
            ]}
          />
        </div>
      </ERPToolbar>

      <ERPCard
        title="قائمة فئات الأصول"
        subtitle={`عدد النتائج: ${filteredCategories.length}`}
      >
        <CategoryTable
          rows={filteredCategories}
          loading={loading}
          onEdit={openEditDialog}
        />

        {!loading && (
          <div className="space-y-3 lg:hidden">
            {filteredCategories.map(
              (category) => (
                <div key={category.id}>
                  <CategoryCard
                    row={category}
                    onEdit={openEditDialog}
                  />

                  <div className="mt-2">
                    <ERPButton
                      type="secondary"
                      onClick={() =>
                        toggleCategory(category)
                      }
                    >
                      {category.is_active === true ||
                      Number(
                        category.is_active
                      ) === 1
                        ? "إيقاف الفئة"
                        : "تفعيل الفئة"}
                    </ERPButton>
                  </div>
                </div>
              )
            )}
          </div>
        )}
      </ERPCard>

      <CategoryDialog
        open={showDialog}
        form={form}
        setForm={setForm}
        accounts={accounts}
        loading={
          saving || accountsLoading
        }
        editing={Boolean(editingCategory)}
        onSave={saveCategory}
        onClose={() => {
          if (!saving) {
            setShowDialog(false);
            setEditingCategory(null);
            setForm({ ...emptyForm });
          }
        }}
      />
    </ERPPage>
  );

  function showMessage(
    type:
      | "success"
      | "error"
      | "info"
      | "warning",
    title: string,
    text: string
  ) {
    setMsg({
      type,
      title,
      text,
    });

    window.scrollTo({
      top: 0,
      behavior: "smooth",
    });
  }
}

function number(value: any): number {
  const parsed = Number(value || 0);

  return Number.isFinite(parsed)
    ? parsed
    : 0;
}

function nullableNumber(
  value: any
): number | null {
  if (
    value === "" ||
    value === null ||
    value === undefined
  ) {
    return null;
  }

  const parsed = Number(value);

  return Number.isFinite(parsed)
    ? parsed
    : null;
}

function apiError(
  error: any,
  fallback: string
): string {
  const response = error?.response?.data;

  if (response?.message) {
    return String(response.message);
  }

  if (response?.errors) {
    const firstError = Object.values(
      response.errors
    )
      .flat()
      .find(Boolean);

    if (firstError) {
      return String(firstError);
    }
  }

  return fallback;
}