"use client";

import { useState } from "react";
import api from "@/app/api";
import SystemDialog from "@/components/common/SystemDialog";
import { readSession } from "@/lib/session";

type DialogState = {
  open: boolean;
  type: "success" | "error" | "warning" | "info" | "confirm";
  title: string;
  message: string;
};

const emptyDialog: DialogState = {
  open: false,
  type: "info",
  title: "",
  message: "",
};

export default function SecurityPage() {
  const session = readSession();
  const user = session?.user;
  const isSupportMode = user?.is_support_mode === true;

  const [form, setForm] = useState({
    current_password: "",
    password: "",
    password_confirmation: "",
  });
  const [saving, setSaving] = useState(false);
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [dialog, setDialog] = useState<DialogState>(emptyDialog);

  const showMessage = (
    type: "success" | "error" | "warning" | "info",
    title: string,
    message: string
  ) => setDialog({ open: true, type, title, message });

  const extractMessage = (error: any) => {
    const errors = error?.response?.data?.errors;

    if (errors && typeof errors === "object") {
      const first = Object.values(errors)[0];
      if (Array.isArray(first) && first.length > 0) return String(first[0]);
      if (typeof first === "string") return first;
    }

    return (
      error?.response?.data?.message ||
      error?.message ||
      "تعذر تغيير كلمة المرور."
    );
  };

  const savePassword = async () => {
    if (isSupportMode) {
      showMessage(
        "warning",
        "اخرج من وضع الدعم",
        "ارجع إلى لوحة المنصة أولًا ثم غيّر كلمة مرور مدير المنصة."
      );
      return;
    }

    if (!form.current_password) {
      showMessage("warning", "تحقق من البيانات", "أدخل كلمة المرور الحالية.");
      return;
    }

    if (form.password.length < 8) {
      showMessage(
        "warning",
        "تحقق من البيانات",
        "كلمة المرور الجديدة يجب ألا تقل عن 8 خانات."
      );
      return;
    }

    if (form.password !== form.password_confirmation) {
      showMessage(
        "warning",
        "تحقق من البيانات",
        "تأكيد كلمة المرور الجديدة غير مطابق."
      );
      return;
    }

    setSaving(true);

    try {
      const response = await api.post("/profile/password", form);
      setForm({
        current_password: "",
        password: "",
        password_confirmation: "",
      });
      showMessage(
        "success",
        "تم تغيير كلمة المرور",
        response?.data?.message || "تم حفظ كلمة المرور الجديدة بنجاح."
      );
    } catch (error: any) {
      showMessage("error", "تعذر تغيير كلمة المرور", extractMessage(error));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <div className="mx-auto max-w-3xl space-y-6">
        <section className="rounded-[28px] bg-gradient-to-l from-[#0B2A4A] to-[#154E7A] p-6 text-white shadow-xl sm:p-8">
          <div className="text-sm font-bold text-blue-100">أمان الحساب</div>
          <h1 className="mt-2 text-3xl font-black">تغيير كلمة المرور</h1>
          <p className="mt-3 text-sm leading-7 text-blue-100">
            الحساب الحالي: {user?.name || user?.username || "-"}. بعد الحفظ
            تُلغى جميع الجلسات الأخرى ويستمر جهازك الحالي فقط.
          </p>
        </section>

        <section className="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
          {isSupportMode ? (
            <div className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-900">
              أنت داخل شركة بوضع الدعم الفني. ارجع إلى لوحة المنصة أولًا.
            </div>
          ) : null}

          <div className="space-y-4">
            <label className="block space-y-2">
              <span className="text-sm font-black text-slate-700">
                كلمة المرور الحالية
              </span>
              <div className="flex gap-2">
                <input
                  type={showCurrent ? "text" : "password"}
                  value={form.current_password}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      current_password: event.target.value,
                    }))
                  }
                  className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none focus:border-[#0B2A4A]"
                  autoComplete="current-password"
                />
                <button
                  type="button"
                  onClick={() => setShowCurrent((value) => !value)}
                  className="rounded-2xl border border-slate-200 px-4 font-bold text-slate-600"
                >
                  {showCurrent ? "إخفاء" : "إظهار"}
                </button>
              </div>
            </label>

            <label className="block space-y-2">
              <span className="text-sm font-black text-slate-700">
                كلمة المرور الجديدة
              </span>
              <div className="flex gap-2">
                <input
                  type={showNew ? "text" : "password"}
                  value={form.password}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      password: event.target.value,
                    }))
                  }
                  className="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none focus:border-[#0B2A4A]"
                  autoComplete="new-password"
                />
                <button
                  type="button"
                  onClick={() => setShowNew((value) => !value)}
                  className="rounded-2xl border border-slate-200 px-4 font-bold text-slate-600"
                >
                  {showNew ? "إخفاء" : "إظهار"}
                </button>
              </div>
            </label>

            <label className="block space-y-2">
              <span className="text-sm font-black text-slate-700">
                تأكيد كلمة المرور الجديدة
              </span>
              <input
                type={showNew ? "text" : "password"}
                value={form.password_confirmation}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    password_confirmation: event.target.value,
                  }))
                }
                className="w-full rounded-2xl border border-slate-200 bg-slate-50 p-4 outline-none focus:border-[#0B2A4A]"
                autoComplete="new-password"
              />
            </label>
          </div>

          <button
            type="button"
            onClick={savePassword}
            disabled={saving || isSupportMode}
            className="mt-6 w-full rounded-2xl bg-[#0B2A4A] px-5 py-4 font-black text-white disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saving ? "جاري الحفظ..." : "حفظ كلمة المرور الجديدة"}
          </button>
        </section>
      </div>

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        confirmText="حسنًا"
        showCancel={false}
        onConfirm={() => setDialog(emptyDialog)}
        onClose={() => setDialog(emptyDialog)}
      />
    </>
  );
}
