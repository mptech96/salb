"use client";

import { usePathname, useRouter } from "next/navigation";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import api from "@/app/api";
import SystemDialog from "@/components/common/SystemDialog";
import SupportReadOnlyGuard from "@/components/layout/SupportReadOnlyGuard";
import AppSidebar from "@/components/navigation/AppSaidbar";
import { AppTopbar } from "@/components/ui/enterprise";
import {
  companyNavigation,
  platformNavigation,
} from "@/components/navigation/menu";
import {
  MANAGER_ROLES,
  canAccessEntitledPath,
  filterNavigation,
  getCompanyLandingPath,
} from "@/components/navigation/access";
import {
  SESSION_CHANGED_EVENT,
  clearAllSessions,
  hasPlatformSessionBackup,
  readSession,
  restorePlatformSession,
  updateSessionPayload,
  type StoredSession,
} from "@/lib/session";

const PUBLIC_PATHS = ["/login", "/register"];
const PLATFORM_PATHS = ["/system-center", "/companies"];
type DialogState = {
  open: boolean;
  type: "success" | "error" | "warning" | "info" | "confirm";
  title: string;
  message: string;
  action: "none" | "logout" | "exit-support";
  confirmText?: string;
  showCancel?: boolean;
};

const emptyDialog: DialogState = {
  open: false,
  type: "info",
  title: "",
  message: "",
  action: "none",
};

function pathMatches(pathname: string, root: string): boolean {
  return pathname === root || pathname.startsWith(`${root}/`);
}

export default function AppShell({
  children,
}: Readonly<{
  children: ReactNode;
}>) {
  const pathname = usePathname();
  const router = useRouter();

  const [ready, setReady] = useState(false);
  const [sessionError, setSessionError] = useState<string | null>(null);
  const [sessionRetryKey, setSessionRetryKey] = useState(0);
  const [session, setSession] = useState<StoredSession | null>(null);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [openGroups, setOpenGroups] = useState<Set<string>>(new Set());
  const [dialog, setDialog] = useState<DialogState>(emptyDialog);
  const [dialogLoading, setDialogLoading] = useState(false);

  const isPublicPath = PUBLIC_PATHS.some((path) => pathMatches(pathname, path));
  const user = session?.user ?? null;
  const roleCode = String(user?.role?.role_code || "").toUpperCase();
  const isSupportMode = user?.is_support_mode === true;
  const isPlatformAdmin =
    roleCode === "SUPER_ADMIN" && !user?.company_id && !isSupportMode;
  const isReadOnlySupport =
    isSupportMode && user?.support_access_level !== "WRITE";
  const isPlatformDestination =
    PLATFORM_PATHS.some((path) => pathMatches(pathname, path)) ||
    platformNavigation.some((group) =>
      group.items.some((item) => pathMatches(pathname, item.href))
    );
  const effectiveFeatures = ((session?.subscription as { effective_entitlements?: { features?: Record<string, boolean> } } | null)?.effective_entitlements?.features) ?? {};

  const syncLocalSession = useCallback(() => {
    setSession(readSession());
  }, []);

  useEffect(() => {
    window.addEventListener(SESSION_CHANGED_EVENT, syncLocalSession);
    window.addEventListener("storage", syncLocalSession);

    return () => {
      window.removeEventListener(SESSION_CHANGED_EVENT, syncLocalSession);
      window.removeEventListener("storage", syncLocalSession);
    };
  }, [syncLocalSession]);

  useEffect(() => {
    let cancelled = false;

    async function hydrateSession() {
      if (isPublicPath) {
        if (!cancelled) {
          setSessionError(null);
          setSession(readSession());
          setReady(true);
        }
        return;
      }

      const stored = readSession();

      if (!stored) {
        clearAllSessions();
        router.replace("/login");
        return;
      }

      if (!cancelled) {
        setSessionError(null);
        setSession(stored);
      }

      try {
        const response = await api.get("/me");

        if (cancelled) return;

        const refreshed = updateSessionPayload({
          user: response.data.user,
          subscription: response.data.subscription ?? null,
        });

        setSession(refreshed);
      } catch (error: any) {
        if (cancelled) return;

        const status = Number(error?.response?.status || 0);

        if (status === 401) {
          clearAllSessions();
          router.replace("/login");
          return;
        }

        setReady(false);
        setSessionError(
          error?.response?.data?.message ||
            (status === 403
              ? "لا تملك صلاحية الدخول إلى هذه الجلسة ضمن النطاق الحالي."
              : status >= 500
              ? "تعذر التحقق من الجلسة بسبب خطأ في الخادم. أصلح الخادم ثم اضغط إعادة المحاولة."
              : "تعذر الاتصال بخادم صلب ERP للتحقق من الجلسة.")
        );
        return;
      }

      if (!cancelled) {
        setReady(true);
      }
    }

    void hydrateSession();

    return () => {
      cancelled = true;
    };
  }, [isPublicPath, pathname, router, sessionRetryKey]);

  const navigationGroups = useMemo(() => {
    if (!user) return [];

    if (isPlatformAdmin) {
      return platformNavigation;
    }

    const allowAll = isSupportMode || MANAGER_ROLES.has(roleCode);

    return filterNavigation(
      companyNavigation,
      roleCode,
      session?.permissions ?? [],
      allowAll,
      effectiveFeatures
    );
  }, [effectiveFeatures, isPlatformAdmin, isSupportMode, roleCode, session?.permissions, user]);

  const companyLandingPath = useMemo(() => {
    if (!user || isPlatformAdmin) return "/system-center";

    return getCompanyLandingPath(
      roleCode,
      session?.permissions ?? [],
      isSupportMode,
      effectiveFeatures
    );
  }, [effectiveFeatures, isPlatformAdmin, isSupportMode, roleCode, session?.permissions, user]);

  useEffect(() => {
    if (!ready || isPublicPath || !user) return;

    if (!isPlatformAdmin && !canAccessEntitledPath(pathname, effectiveFeatures)) {
      router.replace('/no-access');
      return;
    }

    const isPlatformPath = PLATFORM_PATHS.some((path) =>
      pathMatches(pathname, path)
    );

    if (isPlatformPath && !isPlatformAdmin) {
      router.replace("/");
      return;
    }

    if (isPlatformAdmin && !isPlatformDestination) {
      router.replace("/system-center");
      return;
    }

    if (
      pathname === "/" &&
      !isPlatformAdmin &&
      companyLandingPath !== "/"
    ) {
      router.replace(companyLandingPath);
    }
  }, [
    companyLandingPath,
    effectiveFeatures,
    isPlatformAdmin,
    isPlatformDestination,
    isPublicPath,
    pathname,
    ready,
    router,
    user,
  ]);

  useEffect(() => {
    setMobileMenuOpen(false);
  }, [pathname]);

  useEffect(() => {
    document.body.style.overflow = mobileMenuOpen ? "hidden" : "";

    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileMenuOpen]);

  const storageGroupKey = isPlatformAdmin
    ? "sulb-open-platform-group"
    : "sulb-open-company-group";

  useEffect(() => {
    if (!navigationGroups.length) return;

    const activeGroup = navigationGroups.find((group) =>
      group.items.some((item) => pathMatches(pathname, item.href))
    );
    const savedGroup = localStorage.getItem(storageGroupKey);
    const initialGroup = activeGroup?.id || savedGroup || navigationGroups[0].id;

    setOpenGroups((current) => {
      if (current.size > 0 && current.has(initialGroup)) return current;
      return new Set([initialGroup]);
    });
  }, [navigationGroups, pathname, storageGroupKey]);

  const currentPageTitle = useMemo(() => {
    for (const group of navigationGroups) {
      const activeItem = group.items.find((item) =>
        pathMatches(pathname, item.href)
      );
      if (activeItem) return activeItem.label;
    }

    return isPlatformAdmin ? "إدارة منصة صلب" : "بوابة الشركة";
  }, [isPlatformAdmin, navigationGroups, pathname]);

  function toggleGroup(groupId: string) {
    setOpenGroups((current) => {
      const next = new Set(current);

      if (next.has(groupId)) {
        next.delete(groupId);
      } else {
        next.add(groupId);
        localStorage.setItem(storageGroupKey, groupId);
      }

      return next;
    });
  }

  function requestLogout() {
    setDialog({
      open: true,
      type: "confirm",
      title: "تسجيل الخروج",
      message: "سيتم إنهاء جلسة الدخول الحالية. هل تريد المتابعة؟",
      action: "logout",
      confirmText: "تسجيل الخروج",
      showCancel: true,
    });
  }

  function requestExitSupport() {
    setDialog({
      open: true,
      type: "confirm",
      title: "العودة إلى لوحة المنصة",
      message: `سيتم إنهاء جلسة الدعم داخل ${
        user?.company_name || "الشركة"
      } واستعادة جلسة مدير المنصة الأصلية.`,
      action: "exit-support",
      confirmText: "العودة إلى المنصة",
      showCancel: true,
    });
  }

  async function confirmDialogAction() {
    if (dialog.action === "none") {
      setDialog(emptyDialog);
      return;
    }

    setDialogLoading(true);

    try {
      if (dialog.action === "logout") {
        try {
          await api.post("/logout");
        } finally {
          clearAllSessions();
          window.location.replace("/login");
        }
        return;
      }

      if (dialog.action === "exit-support") {
        if (!hasPlatformSessionBackup()) {
          throw new Error(
            "لم يتم العثور على نسخة جلسة مدير المنصة. سجّل الدخول إلى المنصة من جديد."
          );
        }

        await api.post("/support/exit");
        const restored = restorePlatformSession();

        if (!restored) {
          throw new Error("تعذر استعادة جلسة مدير المنصة الأصلية.");
        }

        window.location.replace("/system-center/companies");
      }
    } catch (error: any) {
      setDialog({
        open: true,
        type: "error",
        title: "تعذر تنفيذ العملية",
        message:
          error?.response?.data?.message ||
          error?.message ||
          "حدث خطأ غير متوقع.",
        action: "none",
        confirmText: "حسنًا",
        showCancel: false,
      });
    } finally {
      setDialogLoading(false);
    }
  }

  if (isPublicPath) {
    return <>{children}</>;
  }

  if (sessionError) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-5 bg-slate-100 px-4 text-center" dir="rtl">
        <div className="flex h-16 w-16 items-center justify-center rounded-3xl bg-[#0B2A4A] text-2xl font-black text-white shadow-xl">
          ص
        </div>
        <div className="max-w-xl rounded-3xl border border-red-200 bg-white p-6 shadow-lg">
          <div className="text-xl font-black text-red-700">تعذر التحقق من الجلسة</div>
          <div className="mt-3 text-sm leading-7 text-slate-600">{sessionError}</div>
          <div className="mt-5 flex flex-wrap justify-center gap-3">
            <button
              type="button"
              onClick={() => {
                setSessionError(null);
                setReady(false);
                setSessionRetryKey((value) => value + 1);
              }}
              className="rounded-2xl bg-[#0B2A4A] px-5 py-3 text-sm font-black text-white"
            >
              إعادة المحاولة
            </button>
            <button
              type="button"
              onClick={() => {
                clearAllSessions();
                window.location.replace("/login");
              }}
              className="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-black text-slate-700"
            >
              العودة لتسجيل الدخول
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!ready || !user) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-slate-100 px-4 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-3xl bg-[#0B2A4A] text-2xl font-black text-white shadow-xl">
          ص
        </div>
        <div className="font-black text-[#0B2A4A]">
          جاري التحقق من جلسة صلب ERP...
        </div>
      </div>
    );
  }

  if (isPlatformAdmin && !isPlatformDestination) {
    return null;
  }

  const portalLabel = isPlatformAdmin
    ? "بوابة إدارة المنصة"
    : isSupportMode
    ? "بوابة الشركة · دعم فني"
    : "بوابة الشركة";

  const sidebarProps = {
    groups: navigationGroups,
    pathname,
    user,
    portalLabel,
    openGroups,
    onToggleGroup: toggleGroup,
    onNavigate: () => setMobileMenuOpen(false),
    onLogout: requestLogout,
    isSupportMode,
    onExitSupport: requestExitSupport,
  };

  return (
    <>
      <div className="min-h-screen bg-[#f5f7fa] text-slate-900 lg:pr-[304px]">
          <aside className="fixed inset-y-0 right-0 z-40 hidden w-[304px] lg:block">
            <AppSidebar {...sidebarProps} />
          </aside>

          {mobileMenuOpen ? (
            <div className="fixed inset-0 z-[120] lg:hidden">
              <button
                type="button"
                aria-label="إغلاق القائمة"
                onClick={() => setMobileMenuOpen(false)}
                className="absolute inset-0 bg-slate-950/65 backdrop-blur-sm"
              />
              <aside className="absolute inset-y-0 right-0 w-[88%] max-w-[340px] shadow-2xl">
                <AppSidebar
                  {...sidebarProps}
                  isMobile
                  onClose={() => setMobileMenuOpen(false)}
                />
              </aside>
            </div>
          ) : null}

          <div className="min-w-0">
            <AppTopbar
              title={currentPageTitle}
              companyName={user.company_name}
              branchName={user.branch_name}
              userName={user.name}
              isPlatformAdmin={isPlatformAdmin}
              onOpenMenu={() => setMobileMenuOpen(true)}
              support={isSupportMode ? {
                companyName: user.company_name || "الشركة الحالية",
                accessMode: user.support_access_level === "WRITE" ? "WRITE" : "READ_ONLY",
                ticket: user.support_ticket_reference,
                expiry: user.support_expires_at,
                onExit: requestExitSupport,
              } : undefined}
            />

            <main className="mx-auto min-w-0 max-w-[1600px] p-4 sm:p-5 lg:p-6">
              <SupportReadOnlyGuard active={isReadOnlySupport}>
                {children}
              </SupportReadOnlyGuard>
            </main>
          </div>
        </div>

      <SystemDialog
        open={dialog.open}
        type={dialog.type}
        title={dialog.title}
        message={dialog.message}
        confirmText={dialog.confirmText}
        showCancel={dialog.showCancel}
        loading={dialogLoading}
        onConfirm={confirmDialogAction}
        onClose={() => {
          if (!dialogLoading) setDialog(emptyDialog);
        }}
      />
    </>
  );
}
