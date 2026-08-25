"use client";

import { useEffect, useMemo, useState } from "react";
import SystemDialog from "../../components/common/SystemDialog";
import api from "../api";
import {
  DataTableShell,
  EmptyState,
  FilterBar,
  FormField,
  FormSection,
  LoadingState,
  PageHeader,
  StatusBadge,
  fieldClassName,
  primaryButtonClassName,
} from "@/components/ui/enterprise";

type StoredUser = {
  id?: number | string | null;
  company_id?: number | string | null;
  branch_id?: number | string | null;
  company_name?: string | null;
  branch_name?: string | null;
  role_code?: string | null;
  role?: {
    role_code?: string | null;
    role_name?: string | null;
  } | null;
  company?: {
    company_name?: string | null;
  } | null;
  branch?: {
    branch_name?: string | null;
  } | null;
};

type DialogType = "success" | "error" | "warning" | "info" | "confirm";

type DialogState = {
  open: boolean;
  type: DialogType;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  showCancel?: boolean;
  onConfirm?: () => void | Promise<void>;
};

type UserForm = {
  company_id: string;
  branch_id: string;
  role_id: string;
  name: string;
  username: string;
  email: string;
  phone: string;
  password: string;
  is_active: number;
};

const COMPANY_MANAGER_ROLES = ["COMPANY_MANAGER", "COMPANY_ADMIN", "MANAGER"];
const BRANCH_MANAGER_ROLE = "BRANCH_MANAGER";
const SUPER_ADMIN_ROLE = "SUPER_ADMIN";

const closedDialog: DialogState = {
  open: false,
  type: "info",
  title: "",
  message: "",
};

function getStoredUser(): StoredUser {
  if (typeof window === "undefined") return {};

  try {
    const storedUser = localStorage.getItem("scrap_user");
    return storedUser ? JSON.parse(storedUser) : {};
  } catch {
    return {};
  }
}

function roleCodeOf(user: StoredUser): string {
  return String(user?.role?.role_code || user?.role_code || "")
    .trim()
    .toUpperCase();
}

function getRequestConfig() {
  const user = getStoredUser();
  const roleCode = roleCodeOf(user);
  const headers: Record<string, string> = {};

  if (user?.company_id !== null && user?.company_id !== undefined) {
    headers["X-Company-ID"] = String(user.company_id);
  }

  if (user?.branch_id !== null && user?.branch_id !== undefined) {
    headers["X-Branch-ID"] = String(user.branch_id);
  }

  if (user?.id !== null && user?.id !== undefined) {
    headers["X-User-ID"] = String(user.id);
  }

  if (roleCode) {
    headers["X-Role-Code"] = roleCode;
  }

  return { headers };
}

function extractApiMessage(error: any): string {
  const errors = error?.response?.data?.errors;

  if (errors && typeof errors === "object") {
    const firstValue = Object.values(errors)[0];

    if (Array.isArray(firstValue) && firstValue.length > 0) {
      return String(firstValue[0]);
    }

    if (typeof firstValue === "string") {
      return firstValue;
    }
  }

  return (
    error?.response?.data?.message ||
    error?.message ||
    "تعذر إكمال العملية. حاول مرة أخرى."
  );
}

function validateForm(form: UserForm, editId: number | null): string | null {
  if (!form.company_id) return "اختر الشركة.";
  if (!form.branch_id) return "اختر الفرع.";
  if (!form.role_id) return "اختر الدور.";

  const name = form.name.trim();
  if (!name) return "أدخل اسم المستخدم.";
  if (name.length < 3) return "اسم المستخدم يجب ألا يقل عن 3 أحرف.";
  if (!/^[\p{L}\s.'-]+$/u.test(name)) {
    return "اسم المستخدم يجب أن يحتوي على حروف ومسافات فقط.";
  }

  const username = form.username.trim();
  if (!username) return "أدخل اسم الدخول.";
  if (!/^[A-Za-z0-9._-]{3,50}$/.test(username)) {
    return "اسم الدخول يقبل الحروف الإنجليزية والأرقام والنقطة والشرطة فقط، من 3 إلى 50 خانة.";
  }

  const email = form.email.trim();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return "صيغة البريد الإلكتروني غير صحيحة.";
  }

  const phone = form.phone.trim();
  if (phone && !/^[0-9]{7,15}$/.test(phone)) {
    return "رقم الجوال يجب أن يحتوي على أرقام فقط، من 7 إلى 15 رقمًا.";
  }

  if (!editId && !form.password) return "أدخل كلمة المرور.";
  if (form.password && form.password.length < 6) {
    return "كلمة المرور يجب ألا تقل عن 6 خانات.";
  }

  return null;
}

export default function UsersPage() {
  const [currentUser, setCurrentUser] = useState<StoredUser>({});
  const [users, setUsers] = useState<any[]>([]);
  const [companies, setCompanies] = useState<any[]>([]);
  const [branches, setBranches] = useState<any[]>([]);
  const [roles, setRoles] = useState<any[]>([]);

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [dialogLoading, setDialogLoading] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [search, setSearch] = useState("");
  const [dialog, setDialog] = useState<DialogState>(closedDialog);

  const currentRoleCode = roleCodeOf(currentUser);
  const isSuper = currentRoleCode === SUPER_ADMIN_ROLE;
  const isCompanyManager = COMPANY_MANAGER_ROLES.includes(currentRoleCode);
  const isBranchManager = currentRoleCode === BRANCH_MANAGER_ROLE;
  const canManageUsers = isSuper || isCompanyManager || isBranchManager;

  const currentCompanyId =
    currentUser?.company_id !== null && currentUser?.company_id !== undefined
      ? String(currentUser.company_id)
      : "";

  const currentBranchId =
    currentUser?.branch_id !== null && currentUser?.branch_id !== undefined
      ? String(currentUser.branch_id)
      : "";

  const currentCompanyName =
    currentUser?.company_name ||
    currentUser?.company?.company_name ||
    companies.find((item) => String(item.id) === currentCompanyId)?.company_name ||
    "الشركة الحالية";

  const currentBranchName =
    currentUser?.branch_name ||
    currentUser?.branch?.branch_name ||
    branches.find((item) => String(item.id) === currentBranchId)?.branch_name ||
    "الفرع الحالي";

  const emptyForm = (): UserForm => ({
    company_id: isSuper ? "" : currentCompanyId,
    branch_id: isBranchManager ? currentBranchId : "",
    role_id: "",
    name: "",
    username: "",
    email: "",
    phone: "",
    password: "",
    is_active: 1,
  });

  const [form, setForm] = useState<UserForm>({
    company_id: "",
    branch_id: "",
    role_id: "",
    name: "",
    username: "",
    email: "",
    phone: "",
    password: "",
    is_active: 1,
  });

  const closeDialog = () => {
    if (!dialogLoading) setDialog(closedDialog);
  };

  const showMessage = (
    type: Exclude<DialogType, "confirm">,
    title: string,
    message: string,
  ) => {
    setDialog({
      open: true,
      type,
      title,
      message,
      confirmText: "حسنًا",
      showCancel: false,
      onConfirm: () => setDialog(closedDialog),
    });
  };

  const loadData = async (showErrors = true) => {
    setLoading(true);

    try {
      const storedUser = getStoredUser();
      const storedRoleCode = roleCodeOf(storedUser);
      const superAdmin = storedRoleCode === SUPER_ADMIN_ROLE;

      setCurrentUser(storedUser);

      const [usersResult, branchesResult, rolesResult, companiesResult] =
        await Promise.allSettled([
          api.get("/users"),
          api.get("/branches"),
          api.get("/roles"),
          superAdmin ? api.get("/companies") : Promise.resolve(null),
        ]);

      if (usersResult.status === "fulfilled") {
        setUsers(usersResult.value.data.data || []);
      } else {
        setUsers([]);
        throw usersResult.reason;
      }

      if (branchesResult.status === "fulfilled") {
        setBranches(branchesResult.value.data.data || []);
      } else {
        setBranches([]);
      }

      if (rolesResult.status === "fulfilled") {
        setRoles(rolesResult.value.data.data || []);
      } else {
        setRoles([]);
      }

      if (superAdmin) {
        if (companiesResult.status === "fulfilled" && companiesResult.value) {
          setCompanies(companiesResult.value.data.data || []);
        } else {
          setCompanies([]);
        }
      } else {
        setCompanies([
          {
            id: storedUser.company_id,
            company_name:
              storedUser.company_name ||
              storedUser.company?.company_name ||
              "الشركة الحالية",
          },
        ]);
      }
    } catch (error: any) {
      if (showErrors) {
        showMessage(
          "error",
          "تعذر تحميل المستخدمين",
          extractApiMessage(error),
        );
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadData();
  }, []);

  useEffect(() => {
    if (!isSuper && currentCompanyId) {
      setForm((previous) => ({
        ...previous,
        company_id: currentCompanyId,
        branch_id: isBranchManager ? currentBranchId : previous.branch_id,
      }));
    }
  }, [currentCompanyId, currentBranchId, isBranchManager, isSuper]);

  const companyBranches = useMemo(() => {
    return branches.filter(
      (branch) => String(branch.company_id) === String(form.company_id),
    );
  }, [branches, form.company_id]);

  const availableRoles = useMemo(() => {
    if (isSuper) return roles;

    if (isCompanyManager) {
      return roles.filter(
        (role) => String(role.role_code || "").toUpperCase() !== SUPER_ADMIN_ROLE,
      );
    }

    if (isBranchManager) {
      const denied = new Set([
        SUPER_ADMIN_ROLE,
        BRANCH_MANAGER_ROLE,
        ...COMPANY_MANAGER_ROLES,
      ]);

      return roles.filter(
        (role) => !denied.has(String(role.role_code || "").toUpperCase()),
      );
    }

    return [];
  }, [isBranchManager, isCompanyManager, isSuper, roles]);

  const filtered = useMemo(() => {
    const searchValue = search.trim().toLowerCase();

    return users.filter((user) => {
      const text = `${user.name || ""} ${user.username || ""} ${user.email || ""} ${user.phone || ""} ${user.company_name || ""} ${user.branch_name || ""} ${user.role_name || ""}`.toLowerCase();
      return text.includes(searchValue);
    });
  }, [users, search]);

  const resetForm = () => {
    setEditId(null);
    setForm(emptyForm());
  };

  const openCreateForm = () => {
    if (!canManageUsers) {
      showMessage(
        "warning",
        "غير مسموح",
        "لا تملك صلاحية إضافة مستخدمين.",
      );
      return;
    }

    resetForm();
    setShowForm(true);
  };

  const saveUser = async () => {
    const validationMessage = validateForm(form, editId);

    if (validationMessage) {
      showMessage("warning", "تحقق من البيانات", validationMessage);
      return;
    }

    setSaving(true);

    try {
      const response = editId
        ? await api.put(`/users/${editId}`, form)
        : await api.post("/users", form);

      setShowForm(false);
      resetForm();
      await loadData(false);

      showMessage(
        "success",
        editId ? "تم تعديل المستخدم" : "تم إنشاء المستخدم",
        response?.data?.message ||
          (editId
            ? "تم حفظ تعديلات المستخدم بنجاح."
            : "تم إنشاء المستخدم وربطه بالشركة والفرع بنجاح."),
      );
    } catch (error: any) {
      showMessage("error", "تعذر حفظ المستخدم", extractApiMessage(error));
    } finally {
      setSaving(false);
    }
  };

  const editUser = (user: any) => {
    setEditId(Number(user.id));
    setForm({
      company_id: String(user.company_id || currentCompanyId || ""),
      branch_id: String(user.branch_id || currentBranchId || ""),
      role_id: String(user.role_id || ""),
      name: user.name || "",
      username: user.username || "",
      email: user.email || "",
      phone: user.phone || "",
      password: "",
      is_active: Number(user.is_active ?? 1),
    });
    setShowForm(true);
  };

  const requestDisableUser = (user: any) => {
    if (String(user.id) === String(currentUser.id)) {
      showMessage(
        "warning",
        "تعذر تعطيل المستخدم",
        "لا يمكنك تعطيل حسابك الحالي أثناء استخدامه.",
      );
      return;
    }

    setDialog({
      open: true,
      type: "confirm",
      title: "تأكيد تعطيل المستخدم",
      message: `هل تريد تعطيل المستخدم «${user.name || user.username}»؟\nلن يتمكن من تسجيل الدخول بعد التعطيل.`,
      confirmText: "تعطيل المستخدم",
      cancelText: "إلغاء",
      showCancel: true,
      onConfirm: async () => {
        setDialogLoading(true);

        try {
          const response = await api.delete(
            `/users/${user.id}`,
            getRequestConfig(),
          );

          setDialog(closedDialog);
          await loadData(false);
          showMessage(
            "success",
            "تم تعطيل المستخدم",
            response?.data?.message || "تم تعطيل المستخدم بنجاح.",
          );
        } catch (error: any) {
          setDialog(closedDialog);
          showMessage(
            "error",
            "تعذر تعطيل المستخدم",
            extractApiMessage(error),
          );
        } finally {
          setDialogLoading(false);
        }
      },
    });
  };

  return (
    <section dir="rtl" className="space-y-5">
      <PageHeader
        title="المستخدمون"
        description={isSuper
          ? "إدارة مستخدمي جميع الشركات والفروع."
          : isBranchManager
            ? `إدارة مستخدمي ${currentBranchName} فقط.`
            : `إدارة مستخدمي ${currentCompanyName}.`}
        breadcrumbs={[{ label: "الرئيسية", href: "/" }, { label: "إدارة المستخدمين" }]}
        actions={canManageUsers ? (
          <button type="button" onClick={openCreateForm} className={primaryButtonClassName}>
            + إضافة مستخدم
          </button>
        ) : undefined}
      />

      <FilterBar>
        <input
          className={fieldClassName}
          placeholder="بحث بالاسم، الشركة، الفرع، الدور، الجوال..."
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
      </FilterBar>

      <DataTableShell title="دليل المستخدمين" description={`${filtered.length} مستخدم`}>
          <table className="sulb-table min-w-[1100px]">
            <thead>
              <tr>
                <th className="p-4">الاسم</th>
                <th className="p-4">اسم المستخدم</th>
                <th className="p-4">الشركة</th>
                <th className="p-4">الفرع</th>
                <th className="p-4">الدور</th>
                <th className="p-4">الجوال</th>
                <th className="p-4">الحالة</th>
                <th className="p-4">العمليات</th>
              </tr>
            </thead>

            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={8}><LoadingState label="جاري تحميل المستخدمين..." /></td>
                </tr>
              ) : filtered.length === 0 ? (
                <tr>
                  <td colSpan={8}><EmptyState title="لا يوجد مستخدمون مطابقون." /></td>
                </tr>
              ) : (
                filtered.map((user) => (
                  <tr key={user.id} className="border-t border-slate-100 hover:bg-slate-50/70">
                    <td className="p-4 font-bold text-[#0B2A4A]">{user.name}</td>
                    <td className="p-4">{user.username}</td>
                    <td className="p-4">{user.company_name || "-"}</td>
                    <td className="p-4">{user.branch_name || "-"}</td>
                    <td className="p-4">{user.role_name || "-"}</td>
                    <td className="p-4">{user.phone || "-"}</td>
                    <td className="p-4">
                      {Number(user.is_active) === 1 ? (
                        <StatusBadge tone="success">نشط</StatusBadge>
                      ) : (
                        <StatusBadge tone="danger">متوقف</StatusBadge>
                      )}
                    </td>
                    <td className="p-4">
                      {canManageUsers ? (
                        <div className="flex flex-wrap gap-2">
                          <button
                            type="button"
                            onClick={() => editUser(user)}
                            className="rounded-xl bg-blue-100 px-3 py-2 text-sm font-bold text-blue-700 transition hover:bg-blue-200"
                          >
                            تعديل
                          </button>
                          <button
                            type="button"
                            onClick={() => requestDisableUser(user)}
                            disabled={Number(user.is_active) !== 1}
                            className="rounded-xl bg-rose-100 px-3 py-2 text-sm font-bold text-rose-700 transition hover:bg-rose-200 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            تعطيل
                          </button>
                        </div>
                      ) : (
                        <span className="text-sm text-slate-400">عرض فقط</span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
      </DataTableShell>

      {showForm && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm">
          <div className="absolute left-0 top-0 h-full w-full overflow-y-auto bg-white p-5 shadow-2xl sm:w-[620px]">
            <div className="mb-6 flex items-center justify-between">
              <div>
                <p className="text-sm font-bold text-slate-500">إدارة المستخدمين</p>
                <h2 className="mt-1 text-2xl font-bold text-[#0B2A4A]">
                  {editId ? "تعديل مستخدم" : "إضافة مستخدم"}
                </h2>
              </div>

              <button
                type="button"
                onClick={() => !saving && setShowForm(false)}
                disabled={saving}
                className="rounded-xl bg-slate-200 px-4 py-2 font-bold disabled:opacity-50"
              >
                ✕
              </button>
            </div>

            <FormSection title="بيانات المستخدم وصلاحيات الوصول" description="ترتبط الشركة والفرع والدور بقواعد الوصول الحالية دون تغيير.">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField label="الشركة" required>
                {isSuper ? (
                  <select
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4"
                    value={form.company_id}
                    onChange={(event) =>
                      setForm((previous) => ({
                        ...previous,
                        company_id: event.target.value,
                        branch_id: "",
                      }))
                    }
                  >
                    <option value="">اختر الشركة</option>
                    {companies.map((company) => (
                      <option key={company.id} value={company.id}>
                        {company.company_name}
                      </option>
                    ))}
                  </select>
                ) : (
                  <ReadOnlyValue value={currentCompanyName} />
                )}
              </FormField>

              <FormField label="الفرع" required>
                {isBranchManager ? (
                  <ReadOnlyValue value={currentBranchName} />
                ) : (
                  <select
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4"
                    value={form.branch_id}
                    onChange={(event) =>
                      setForm((previous) => ({
                        ...previous,
                        branch_id: event.target.value,
                      }))
                    }
                    disabled={!form.company_id}
                  >
                    <option value="">اختر الفرع</option>
                    {companyBranches.map((branch) => (
                      <option key={branch.id} value={branch.id}>
                        {branch.branch_name}
                      </option>
                    ))}
                  </select>
                )}
              </FormField>

              <FormField label="الدور" required>
                <select
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4"
                  value={form.role_id}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      role_id: event.target.value,
                    }))
                  }
                >
                  <option value="">اختر الدور</option>
                  {availableRoles.map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.role_name}
                    </option>
                  ))}
                </select>
              </FormField>

              <FormField label="الاسم الكامل" required>
                <input
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4"
                  placeholder="مثال: محمد أحمد"
                  value={form.name}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      name: event.target.value,
                    }))
                  }
                />
              </FormField>

              <FormField label="اسم الدخول" required>
                <input
                  dir="ltr"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left"
                  placeholder="مثال: mohammed.hod"
                  value={form.username}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      username: event.target.value.replace(/\s/g, ""),
                    }))
                  }
                />
              </FormField>

              <FormField label="البريد الإلكتروني">
                <input
                  type="email"
                  dir="ltr"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left"
                  placeholder="name@example.com"
                  value={form.email}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      email: event.target.value,
                    }))
                  }
                />
              </FormField>

              <FormField label="رقم الجوال">
                <input
                  type="tel"
                  inputMode="numeric"
                  dir="ltr"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left"
                  placeholder="0771000000"
                  value={form.phone}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      phone: event.target.value.replace(/\D/g, "").slice(0, 15),
                    }))
                  }
                />
              </FormField>

              <FormField
                label={editId ? "كلمة مرور جديدة" : "كلمة المرور"}
                required={!editId}
                hint={editId ? "اتركها فارغة للإبقاء على كلمة المرور الحالية." : undefined}
              >
                <input
                  type="password"
                  dir="ltr"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left"
                  placeholder={editId ? "اختياري" : "6 خانات على الأقل"}
                  value={form.password}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      password: event.target.value,
                    }))
                  }
                />
              </FormField>

              <FormField label="الحالة" required>
                <select
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4"
                  value={form.is_active}
                  onChange={(event) =>
                    setForm((previous) => ({
                      ...previous,
                      is_active: Number(event.target.value),
                    }))
                  }
                >
                  <option value={1}>نشط</option>
                  <option value={0}>متوقف</option>
                </select>
              </FormField>

              <button
                type="button"
                onClick={() => void saveUser()}
                disabled={saving}
                className={`${primaryButtonClassName} self-end`}
              >
                {saving
                  ? "جاري الحفظ..."
                  : editId
                    ? "حفظ التعديلات"
                    : "حفظ المستخدم"}
              </button>
            </div>
            </FormSection>
          </div>
        </div>
      )}

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        confirmText={dialog.confirmText}
        cancelText={dialog.cancelText}
        showCancel={dialog.showCancel}
        loading={dialogLoading}
        onClose={closeDialog}
        onConfirm={dialog.onConfirm || closeDialog}
      />
    </section>
  );
}

function ReadOnlyValue({ value }: { value: string }) {
  return (
    <div className="rounded-2xl border border-blue-100 bg-blue-50 p-4 font-bold text-[#0B2A4A]">
      {value}
    </div>
  );
}
