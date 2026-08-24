"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import { readSession, saveSession } from "../../lib/session";
import { getCompanyLandingPath } from "../../components/navigation/access";

type DialogState = {
  open: boolean;
  type: "error" | "info";
  title: string;
  message: string;
};

const emptyDialog: DialogState = {
  open: false,
  type: "info",
  title: "",
  message: "",
};

export default function LoginPage() {
  const router = useRouter();
  const [form, setForm] = useState({
    username: "",
    password: "",
    remember: false,
  });
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [dialog, setDialog] = useState<DialogState>(emptyDialog);

  useEffect(() => {
    const current = readSession();

    if (current?.user) {
      const isPlatformAdmin =
        current.user.role?.role_code === "SUPER_ADMIN" &&
        !current.user.is_support_mode &&
        !current.user.company_id;

      router.replace(
        isPlatformAdmin
          ? "/system-center"
          : getCompanyLandingPath(
              String(current.user.role?.role_code || ""),
              current.permissions,
              current.user.is_support_mode === true
            )
      );
      return;
    }

    const query = new URLSearchParams(window.location.search);

    if (query.get("session") === "expired") {
      setDialog({
        open: true,
        type: "info",
        title: "انتهت الجلسة",
        message: "انتهت جلسة الدخول حفاظًا على أمان حسابك. سجّل الدخول مرة أخرى.",
      });
    }
  }, [router]);

  function showError(message: string) {
    setDialog({
      open: true,
      type: "error",
      title: "تعذر تسجيل الدخول",
      message,
    });
  }

  async function login() {
    const username = form.username.trim();

    if (!username) {
      showError("أدخل اسم المستخدم.");
      return;
    }

    if (!form.password) {
      showError("أدخل كلمة المرور.");
      return;
    }

    setLoading(true);

    try {
      const response = await api.post("/login", {
        username,
        password: form.password,
        remember: form.remember,
      });

      const saved = saveSession({
        token: response.data.token,
        user: response.data.user,
        subscription: response.data.subscription ?? null,
        permissions: response.data.user?.permissions ?? [],
      });

      const isPlatformAdmin =
        saved.user.role?.role_code === "SUPER_ADMIN" &&
        !saved.user.is_support_mode &&
        !saved.user.company_id;

      router.replace(
        isPlatformAdmin
          ? "/system-center"
          : getCompanyLandingPath(
              String(saved.user.role?.role_code || ""),
              saved.permissions,
              saved.user.is_support_mode === true
            )
      );
    } catch (error: any) {
      const validationMessage = error?.response?.data?.errors
        ? Object.values(error.response.data.errors).flat().find(Boolean)
        : null;

      showError(
        String(
          validationMessage ||
            error?.response?.data?.message ||
            "فشل تسجيل الدخول، تحقق من البيانات ثم حاول مرة أخرى."
        )
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <main
      dir="rtl"
      className="relative min-h-screen overflow-hidden bg-[#061A2D]"
    >
      <div className="absolute inset-0">
        <div className="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-cyan-400/10 blur-3xl" />
        <div className="absolute -bottom-32 -left-24 h-[28rem] w-[28rem] rounded-full bg-blue-500/10 blur-3xl" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.06),transparent_38%)]" />
      </div>

      <div className="relative mx-auto grid min-h-screen w-full max-w-7xl items-center gap-8 px-5 py-8 lg:grid-cols-[1.08fr_0.92fr] lg:px-10">
        <section className="hidden rounded-[2rem] border border-white/10 bg-white/[0.06] p-10 text-white shadow-2xl backdrop-blur-xl lg:block">
          <div className="flex items-center gap-4">
            <div className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-3xl bg-white p-2 shadow-xl">
              <img
                src="/sulb-logo.png"
                alt="شعار صلب ERP"
                className="h-full w-full object-contain"
              />
            </div>

            <div>
              <h1 className="text-4xl font-black">صلب ERP</h1>
              <p className="mt-1 text-sm font-bold tracking-[0.32em] text-cyan-200">
                SULB ERP
              </p>
            </div>
          </div>

          <div className="mt-12 max-w-xl">
            <p className="text-sm font-bold text-cyan-200">
              نظام إدارة أعمال السكراب والمعادن
            </p>
            <h2 className="mt-4 text-4xl font-black leading-[1.45]">
              كل عمليات شركتك
              <br />
              في منصة واحدة مترابطة
            </h2>
            <p className="mt-5 text-base leading-8 text-slate-300">
              أدر الشحنات والميزان والمشتريات والمبيعات والمخزون والحسابات
              والفروع والمستخدمين من لوحة تحكم موحدة وآمنة.
            </p>
          </div>

          <div className="mt-10 grid grid-cols-2 gap-4">
            {[
              ["عزل حقيقي", "لكل شركة وفرع ومستخدم"],
              ["إدارة الشحنات", "ومتابعة الكميات والتكلفة"],
              ["تقارير مترابطة", "لدعم القرار والربحية"],
              ["صلاحيات مرنة", "للإدارة والمحاسبة والمخزون"],
            ].map(([title, description]) => (
              <div
                key={title}
                className="rounded-2xl border border-white/10 bg-white/[0.05] p-4"
              >
                <div className="font-black text-white">{title}</div>
                <div className="mt-1 text-xs leading-6 text-slate-300">
                  {description}
                </div>
              </div>
            ))}
          </div>

          <div className="mt-10 border-t border-white/10 pt-5 text-xs text-slate-400">
            تطوير وتشغيل MG Technology
          </div>
        </section>

        <section className="mx-auto w-full max-w-md">
          <div className="rounded-[2rem] bg-white p-6 shadow-2xl sm:p-8">
            <div className="mb-8 text-center lg:hidden">
              <div className="mx-auto mb-4 flex h-24 w-24 items-center justify-center overflow-hidden rounded-3xl bg-slate-50 p-2 shadow-sm ring-1 ring-slate-200">
                <img
                  src="/sulb-logo.png"
                  alt="شعار صلب ERP"
                  className="h-full w-full object-contain"
                />
              </div>
              <h1 className="text-3xl font-black text-[#0B2A4A]">صلب ERP</h1>
              <p className="mt-1 text-xs font-bold tracking-[0.25em] text-slate-400">
                SULB ERP
              </p>
            </div>

            <div className="mb-7">
              <p className="text-sm font-bold text-cyan-700">مرحبًا بعودتك</p>
              <h2 className="mt-2 text-3xl font-black text-[#0B2A4A]">
                تسجيل الدخول
              </h2>
              <p className="mt-2 text-sm leading-6 text-slate-500">
                أدخل بيانات حسابك للوصول إلى بوابتك المخصصة.
              </p>
            </div>

            <div className="space-y-5">
              <label className="block">
                <span className="mb-2 block text-sm font-bold text-slate-700">
                  اسم المستخدم
                </span>
                <input
                  autoFocus
                  autoComplete="username"
                  className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-600 focus:bg-white focus:ring-4 focus:ring-cyan-100"
                  placeholder="أدخل اسم المستخدم"
                  value={form.username}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      username: event.target.value,
                    }))
                  }
                  onKeyDown={(event) => {
                    if (event.key === "Enter" && !loading) void login();
                  }}
                />
              </label>

              <label className="block">
                <span className="mb-2 block text-sm font-bold text-slate-700">
                  كلمة المرور
                </span>
                <div className="relative">
                  <input
                    type={showPassword ? "text" : "password"}
                    autoComplete="current-password"
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 pl-24 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-cyan-600 focus:bg-white focus:ring-4 focus:ring-cyan-100"
                    placeholder="أدخل كلمة المرور"
                    value={form.password}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        password: event.target.value,
                      }))
                    }
                    onKeyDown={(event) => {
                      if (event.key === "Enter" && !loading) void login();
                    }}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((value) => !value)}
                    className="absolute inset-y-0 left-3 my-auto h-9 rounded-xl px-3 text-xs font-bold text-cyan-700 transition hover:bg-cyan-50"
                  >
                    {showPassword ? "إخفاء" : "إظهار"}
                  </button>
                </div>
              </label>

              <div className="flex items-center justify-between gap-3 text-sm">
                <label className="flex cursor-pointer items-center gap-2 text-slate-600">
                  <input
                    type="checkbox"
                    checked={form.remember}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        remember: event.target.checked,
                      }))
                    }
                    className="h-4 w-4 rounded border-slate-300 accent-cyan-700"
                  />
                  تذكرني لمدة 30 يومًا
                </label>

                <button
                  type="button"
                  className="shrink-0 font-bold text-cyan-700 hover:text-cyan-800"
                  onClick={() =>
                    setDialog({
                      open: true,
                      type: "info",
                      title: "استعادة كلمة المرور",
                      message:
                        "سيتم ربط الاستعادة برسالة جوال أو بريد بعد اعتماد وسيلة الاسترجاع لكل شركة.",
                    })
                  }
                >
                  نسيت كلمة المرور؟
                </button>
              </div>

              <button
                type="button"
                onClick={() => void login()}
                disabled={loading}
                className="w-full rounded-2xl bg-[#0B2A4A] px-5 py-4 text-base font-black text-white shadow-lg shadow-slate-900/10 transition hover:bg-[#123B63] disabled:cursor-not-allowed disabled:opacity-60"
              >
                {loading ? "جاري التحقق والدخول..." : "دخول إلى النظام"}
              </button>
            </div>

            <div className="mt-8 border-t border-slate-100 pt-6 text-center">
              <p className="text-sm text-slate-500">ليس لديك حساب شركة؟</p>
              <Link
                href="/register"
                className="mt-2 inline-block font-black text-cyan-700 hover:text-cyan-800"
              >
                إنشاء حساب شركة جديد
              </Link>
            </div>

            <p className="mt-8 text-center text-xs text-slate-400 lg:hidden">
              تطوير وتشغيل MG Technology
            </p>
          </div>

          <p className="mt-5 text-center text-xs text-slate-400">
            باستخدامك للنظام فإنك توافق على شروط الاستخدام وسياسة الخصوصية
          </p>
        </section>
      </div>

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        onConfirm={() => setDialog(emptyDialog)}
        onClose={() => setDialog(emptyDialog)}
      />
    </main>
  );
}
