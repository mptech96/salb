"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import api from "../api";
import SystemDialog from "@/components/common/SystemDialog";
import { backupPlatformSession, saveSession } from "../../lib/session";

type DashboardData = {
  companies: {
    total: number;
    active: number;
    inactive: number;
    new_this_month: number;
  };
  subscriptions: {
    total: number;
    active: number;
    expired: number;
    trial: number;
    suspended: number;
    cancelled: number;
    expiring_soon: number;
  };
  users: {
    total: number;
    active: number;
    inactive: number;
  };
  branches: {
    total: number;
    active: number;
    inactive: number;
  };
  recent_companies: Array<{
    id: number;
    company_name: string;
    owner_name: string | null;
    phone: string | null;
    city: string | null;
    is_active: number;
    created_at: string | null;
    start_date: string | null;
    end_date: string | null;
    subscription_status: string | null;
    plan_name: string | null;
    plan_code: string | null;
  }>;
  expiring_subscriptions: Array<{
    id: number;
    company_id: number;
    company_name: string;
    plan_name: string | null;
    start_date: string;
    end_date: string;
    status: string;
    remaining_days: number;
  }>;
  plans_distribution: Array<{
    id: number;
    plan_name: string;
    plan_code: string;
    monthly_price: number | string;
    is_active: number;
    companies_count: number | string;
  }>;
  recent_activities: Array<{
    id: number;
    company_id: number | null;
    branch_id: number | null;
    user_id: number | null;
    action_type: string;
    module_name: string;
    record_id: number | null;
    description: string | null;
    ip_address: string | null;
    created_at: string | null;
    company_name: string | null;
    user_name: string | null;
    username: string | null;
  }>;
  generated_at: string;
};

const emptyDashboard: DashboardData = {
  companies: {
    total: 0,
    active: 0,
    inactive: 0,
    new_this_month: 0,
  },
  subscriptions: {
    total: 0,
    active: 0,
    expired: 0,
    trial: 0,
    suspended: 0,
    cancelled: 0,
    expiring_soon: 0,
  },
  users: {
    total: 0,
    active: 0,
    inactive: 0,
  },
  branches: {
    total: 0,
    active: 0,
    inactive: 0,
  },
  recent_companies: [],
  expiring_subscriptions: [],
  plans_distribution: [],
  recent_activities: [],
  generated_at: "",
};

export default function SystemCenterPage() {
  const [data, setData] = useState<DashboardData>(emptyDashboard);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [supportCompany, setSupportCompany] = useState<{ id: number; name: string } | null>(null);
  const [supportLoading, setSupportLoading] = useState(false);
  const [supportDialog, setSupportDialog] = useState({
    open: false,
    type: "confirm" as "confirm" | "error",
    title: "",
    message: "",
  });

  const loadDashboard = useCallback(async (manualRefresh = false) => {
    if (manualRefresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    setError("");

    try {
      const response = await api.get("/system-admin/dashboard");

      if (!response.data?.status) {
        throw new Error(
          response.data?.message || "تعذر تحميل لوحة إدارة المنصة"
        );
      }

      setData({
        ...emptyDashboard,
        ...(response.data?.data || {}),
      });
    } catch (requestError: any) {
      setError(
        requestError?.response?.data?.message ||
          requestError?.message ||
          "حدث خطأ أثناء تحميل لوحة إدارة المنصة"
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadDashboard();
  }, [loadDashboard]);

  const totalPlanCompanies = useMemo(() => {
    return data.plans_distribution.reduce(
      (total, plan) => total + Number(plan.companies_count || 0),
      0
    );
  }, [data.plans_distribution]);

  function supportAccess(companyId: number, companyName = "الشركة") {
    setSupportCompany({ id: companyId, name: companyName });
    setSupportDialog({
      open: true,
      type: "confirm",
      title: "دخول الدعم الفني",
      message: `سيتم فتح جلسة دعم آمنة داخل شركة «${companyName}» لمدة ساعتين، مع حفظ جلسة مدير المنصة الأصلية.`,
    });
  }

  async function confirmSupportAccess() {
    if (!supportCompany) return;

    setSupportLoading(true);

    try {
      backupPlatformSession();

      const response = await api.post(
        `/companies/${supportCompany.id}/support-access`,
        { reason: "دخول دعم فني من لوحة إدارة المنصة" }
      );

      saveSession({
        token: response.data.token,
        user: response.data.user,
        subscription: response.data.subscription ?? null,
        permissions: response.data.user?.permissions ?? [],
      });

      window.location.assign("/");
    } catch (requestError: any) {
      setSupportDialog({
        open: true,
        type: "error",
        title: "فشل دخول الدعم",
        message:
          requestError?.response?.data?.message ||
          "تعذر فتح جلسة الدعم الفني لهذه الشركة.",
      });
    } finally {
      setSupportLoading(false);
    }
  }

  return (
    <section dir="rtl" className="space-y-6">
      <header className="overflow-hidden rounded-[32px] bg-gradient-to-l from-[#071D33] via-[#0B2A4A] to-[#164F82] p-6 text-white shadow-xl sm:p-8">
        <div className="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <div className="mb-3 inline-flex rounded-full bg-white/10 px-4 py-2 text-sm font-bold text-blue-100">
              SULB ERP — Platform Administration
            </div>

            <h1 className="text-3xl font-black sm:text-4xl">
              لوحة إدارة المنصة
            </h1>

            <p className="mt-3 max-w-3xl text-sm leading-7 text-blue-100 sm:text-base">
              متابعة الشركات والاشتراكات والفروع والمستخدمين ونشاط النظام
              من مركز تحكم واحد.
            </p>
          </div>

          <div className="flex flex-wrap gap-3">
            <Link
              href="/system-center/companies"
              className="rounded-2xl bg-white px-5 py-3 text-sm font-black text-[#0B2A4A] shadow-lg transition hover:-translate-y-0.5"
            >
              + إضافة شركة
            </Link>

            <button
              type="button"
              onClick={() => loadDashboard(true)}
              disabled={refreshing}
              className="rounded-2xl border border-white/30 bg-white/10 px-5 py-3 text-sm font-black text-white transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {refreshing ? "جاري التحديث..." : "تحديث البيانات"}
            </button>
          </div>
        </div>

        <div className="mt-7 grid grid-cols-2 gap-3 md:grid-cols-4">
          <HeroStat
            title="إجمالي الشركات"
            value={data.companies.total}
          />

          <HeroStat
            title="اشتراكات نشطة"
            value={data.subscriptions.active}
          />

          <HeroStat
            title="إجمالي المستخدمين"
            value={data.users.total}
          />

          <HeroStat
            title="إجمالي الفروع"
            value={data.branches.total}
          />
        </div>
      </header>

      {error ? (
        <div className="rounded-3xl border border-red-200 bg-red-50 p-5 text-red-700 shadow-sm">
          <div className="font-black">تعذر تحميل البيانات</div>
          <div className="mt-1 text-sm">{error}</div>

          <button
            type="button"
            onClick={() => loadDashboard()}
            className="mt-4 rounded-xl bg-red-700 px-5 py-2.5 text-sm font-bold text-white"
          >
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          title="الشركات النشطة"
          value={data.companies.active}
          subtitle={`${data.companies.inactive} شركة متوقفة`}
          icon="🏢"
          loading={loading}
        />

        <StatCard
          title="تنتهي قريبًا"
          value={data.subscriptions.expiring_soon}
          subtitle="خلال الثلاثين يومًا القادمة"
          icon="⏳"
          loading={loading}
        />

        <StatCard
          title="الاشتراكات المنتهية"
          value={data.subscriptions.expired}
          subtitle={`${data.subscriptions.suspended} اشتراك معلق`}
          icon="⚠️"
          loading={loading}
        />

        <StatCard
          title="شركات جديدة"
          value={data.companies.new_this_month}
          subtitle="منذ بداية الشهر الحالي"
          icon="✨"
          loading={loading}
        />
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <SmallStat
          title="تجريبية"
          value={data.subscriptions.trial}
        />

        <SmallStat
          title="معلقة"
          value={data.subscriptions.suspended}
        />

        <SmallStat
          title="مستخدمون نشطون"
          value={data.users.active}
        />

        <SmallStat
          title="فروع نشطة"
          value={data.branches.active}
        />
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div className="xl:col-span-2">
          <Panel
            title="آخر الشركات المسجلة"
            description="أحدث العملاء الذين تمت إضافتهم إلى المنصة"
            action={
              <Link
                href="/system-center/companies"
                className="text-sm font-black text-[#0B2A4A] hover:underline"
              >
                عرض جميع الشركات
              </Link>
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full min-w-[900px] text-right">
                <thead className="bg-slate-50 text-xs text-slate-500">
                  <tr>
                    <th className="p-4 font-black">الشركة</th>
                    <th className="p-4 font-black">المالك</th>
                    <th className="p-4 font-black">الباقة</th>
                    <th className="p-4 font-black">نهاية الاشتراك</th>
                    <th className="p-4 font-black">الحالة</th>
                    <th className="p-4 font-black">الإجراء</th>
                  </tr>
                </thead>

                <tbody>
                  {loading ? (
                    <LoadingRows columns={6} />
                  ) : data.recent_companies.length === 0 ? (
                    <EmptyRow
                      columns={6}
                      message="لا توجد شركات حتى الآن"
                    />
                  ) : (
                    data.recent_companies.map((company) => (
                      <tr
                        key={company.id}
                        className="border-t border-slate-100 transition hover:bg-slate-50"
                      >
                        <td className="p-4">
                          <div className="font-black text-[#0B2A4A]">
                            {company.company_name}
                          </div>
                          <div className="mt-1 text-xs text-slate-500">
                            {company.city || "المدينة غير محددة"}
                          </div>
                        </td>

                        <td className="p-4 text-sm text-slate-700">
                          {company.owner_name || "-"}
                        </td>

                        <td className="p-4">
                          <div className="font-bold text-slate-800">
                            {company.plan_name || "بدون باقة"}
                          </div>
                          <div className="mt-1 text-xs text-slate-500">
                            {company.plan_code || "-"}
                          </div>
                        </td>

                        <td className="p-4 text-sm text-slate-700">
                          {formatDate(company.end_date)}
                        </td>

                        <td className="p-4">
                          <StatusBadge
                            status={
                              Number(company.is_active) !== 1
                                ? "INACTIVE"
                                : company.subscription_status || "UNKNOWN"
                            }
                          />
                        </td>

                        <td className="p-4">
                          <button
                            type="button"
                            onClick={() => supportAccess(company.id, company.company_name)}
                            className="rounded-xl bg-[#0B2A4A] px-4 py-2 text-xs font-black text-white transition hover:bg-[#123D68]"
                          >
                            دخول دعم
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Panel>
        </div>

        <Panel
          title="توزيع الباقات"
          description={`${totalPlanCompanies} شركة مرتبطة بالباقات`}
        >
          <div className="space-y-4 p-5">
            {loading ? (
              <div className="py-10 text-center text-slate-500">
                جاري التحميل...
              </div>
            ) : data.plans_distribution.length === 0 ? (
              <EmptyState message="لا توجد باقات" />
            ) : (
              data.plans_distribution.map((plan) => {
                const count = Number(plan.companies_count || 0);
                const percentage =
                  totalPlanCompanies > 0
                    ? Math.round((count / totalPlanCompanies) * 100)
                    : 0;

                return (
                  <div
                    key={plan.id}
                    className="rounded-2xl border border-slate-100 bg-slate-50 p-4"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <div className="font-black text-slate-800">
                          {plan.plan_name}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">
                          {plan.plan_code}
                        </div>
                      </div>

                      <div className="text-left">
                        <div className="text-xl font-black text-[#0B2A4A]">
                          {count}
                        </div>
                        <div className="text-xs text-slate-500">
                          شركة
                        </div>
                      </div>
                    </div>

                    <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                      <div
                        className="h-full rounded-full bg-[#0B2A4A]"
                        style={{ width: `${percentage}%` }}
                      />
                    </div>

                    <div className="mt-2 flex justify-between text-xs text-slate-500">
                      <span>{percentage}% من الشركات</span>
                      <span>
                        {money(plan.monthly_price)} ر.س / شهر
                      </span>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </Panel>
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
        <Panel
          title="اشتراكات تنتهي قريبًا"
          description="الاشتراكات التي تحتاج متابعة أو تجديد"
        >
          <div className="divide-y divide-slate-100">
            {loading ? (
              <div className="p-8 text-center text-slate-500">
                جاري التحميل...
              </div>
            ) : data.expiring_subscriptions.length === 0 ? (
              <EmptyState message="لا توجد اشتراكات قريبة من الانتهاء" />
            ) : (
              data.expiring_subscriptions.map((subscription) => (
                <div
                  key={subscription.id}
                  className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div>
                    <div className="font-black text-slate-800">
                      {subscription.company_name}
                    </div>

                    <div className="mt-1 text-sm text-slate-500">
                      {subscription.plan_name || "بدون باقة"} — ينتهي{" "}
                      {formatDate(subscription.end_date)}
                    </div>
                  </div>

                  <div
                    className={`w-fit rounded-full px-3 py-1.5 text-xs font-black ${
                      Number(subscription.remaining_days) <= 7
                        ? "bg-red-100 text-red-700"
                        : Number(subscription.remaining_days) <= 15
                          ? "bg-amber-100 text-amber-700"
                          : "bg-blue-100 text-blue-700"
                    }`}
                  >
                    متبقي {Number(subscription.remaining_days)} يوم
                  </div>
                </div>
              ))
            )}
          </div>
        </Panel>

        <Panel
          title="آخر نشاطات النظام"
          description="أحدث العمليات المسجلة في سجل التدقيق"
        >
          <div className="max-h-[520px] divide-y divide-slate-100 overflow-y-auto">
            {loading ? (
              <div className="p-8 text-center text-slate-500">
                جاري التحميل...
              </div>
            ) : data.recent_activities.length === 0 ? (
              <EmptyState message="لا توجد نشاطات مسجلة" />
            ) : (
              data.recent_activities.map((activity) => (
                <div key={activity.id} className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="font-black text-slate-800">
                        {activity.description ||
                          `${activity.action_type} — ${activity.module_name}`}
                      </div>

                      <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                        <span>
                          الشركة: {activity.company_name || "-"}
                        </span>

                        <span>
                          المستخدم:{" "}
                          {activity.user_name ||
                            activity.username ||
                            "النظام"}
                        </span>

                        <span>{formatDateTime(activity.created_at)}</span>
                      </div>
                    </div>

                    <ActionBadge action={activity.action_type} />
                  </div>
                </div>
              ))
            )}
          </div>
        </Panel>
      </div>

      <Panel
        title="اختصارات إدارة المنصة"
        description="الوصول السريع إلى أهم صفحات الإدارة"
      >
        <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
          <QuickLink
            href="/system-center/companies"
            icon="🏢"
            title="إدارة الشركات"
            description="إضافة ومتابعة شركات المنصة"
          />

          <QuickLink
            href="/branches"
            icon="🏬"
            title="إدارة الفروع"
            description="عرض فروع جميع الشركات"
          />

          <QuickLink
            href="/users"
            icon="👥"
            title="المستخدمون"
            description="متابعة مستخدمي الشركات"
          />

          <QuickLink
            href="/audit-logs"
            icon="🧾"
            title="سجل النشاط"
            description="مراجعة العمليات والتغييرات"
          />
        </div>
      </Panel>

      <footer className="pb-2 text-center text-xs text-slate-400">
        آخر تحديث: {formatDateTime(data.generated_at)}
      </footer>

      <SystemDialog
        open={supportDialog.open}
        type={supportDialog.type}
        title={supportDialog.title}
        message={supportDialog.message}
        confirmText={supportDialog.type === "confirm" ? "دخول الشركة" : "حسنًا"}
        showCancel={supportDialog.type === "confirm"}
        loading={supportLoading}
        onConfirm={
          supportDialog.type === "confirm"
            ? confirmSupportAccess
            : () => setSupportDialog((current) => ({ ...current, open: false }))
        }
        onClose={() => {
          if (!supportLoading) {
            setSupportDialog((current) => ({ ...current, open: false }));
            setSupportCompany(null);
          }
        }}
      />
    </section>
  );
}

function HeroStat({
  title,
  value,
}: {
  title: string;
  value: number;
}) {
  return (
    <div className="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
      <div className="text-xs font-bold text-blue-100 sm:text-sm">
        {title}
      </div>
      <div className="mt-2 text-2xl font-black sm:text-3xl">
        {Number(value || 0).toLocaleString("ar-SA")}
      </div>
    </div>
  );
}

function StatCard({
  title,
  value,
  subtitle,
  icon,
  loading,
}: {
  title: string;
  value: number;
  subtitle: string;
  icon: string;
  loading: boolean;
}) {
  return (
    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="text-sm font-bold text-slate-500">
            {title}
          </div>

          <div className="mt-3 text-3xl font-black text-[#0B2A4A]">
            {loading
              ? "..."
              : Number(value || 0).toLocaleString("ar-SA")}
          </div>

          <div className="mt-2 text-xs text-slate-500">
            {subtitle}
          </div>
        </div>

        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-2xl">
          {icon}
        </div>
      </div>
    </div>
  );
}

function SmallStat({
  title,
  value,
}: {
  title: string;
  value: number;
}) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="text-xs font-bold text-slate-500">
        {title}
      </div>
      <div className="mt-2 text-2xl font-black text-[#0B2A4A]">
        {Number(value || 0).toLocaleString("ar-SA")}
      </div>
    </div>
  );
}

function Panel({
  title,
  description,
  action,
  children,
}: {
  title: string;
  description?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-xl font-black text-[#0B2A4A]">
            {title}
          </h2>

          {description ? (
            <p className="mt-1 text-sm text-slate-500">
              {description}
            </p>
          ) : null}
        </div>

        {action}
      </div>

      {children}
    </div>
  );
}

function QuickLink({
  href,
  icon,
  title,
  description,
}: {
  href: string;
  icon: string;
  title: string;
  description: string;
}) {
  return (
    <Link
      href={href}
      className="rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:-translate-y-0.5 hover:border-[#0B2A4A] hover:bg-white hover:shadow-md"
    >
      <div className="text-2xl">{icon}</div>
      <div className="mt-3 font-black text-slate-800">
        {title}
      </div>
      <div className="mt-1 text-xs leading-6 text-slate-500">
        {description}
      </div>
    </Link>
  );
}

function StatusBadge({ status }: { status: string }) {
  const normalized = String(status || "UNKNOWN").toUpperCase();

  const styles: Record<string, string> = {
    ACTIVE: "bg-emerald-100 text-emerald-700",
    TRIAL: "bg-blue-100 text-blue-700",
    EXPIRED: "bg-red-100 text-red-700",
    SUSPENDED: "bg-amber-100 text-amber-700",
    CANCELLED: "bg-slate-200 text-slate-700",
    INACTIVE: "bg-slate-200 text-slate-700",
    UNKNOWN: "bg-slate-100 text-slate-600",
  };

  const labels: Record<string, string> = {
    ACTIVE: "نشط",
    TRIAL: "تجريبي",
    EXPIRED: "منتهي",
    SUSPENDED: "معلق",
    CANCELLED: "ملغي",
    INACTIVE: "متوقف",
    UNKNOWN: "غير محدد",
  };

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-xs font-black ${
        styles[normalized] || styles.UNKNOWN
      }`}
    >
      {labels[normalized] || normalized}
    </span>
  );
}

function ActionBadge({ action }: { action: string }) {
  const normalized = String(action || "").toUpperCase();

  const style =
    normalized === "CREATE"
      ? "bg-emerald-100 text-emerald-700"
      : normalized === "UPDATE"
        ? "bg-blue-100 text-blue-700"
        : normalized === "DELETE"
          ? "bg-red-100 text-red-700"
          : normalized === "LOGIN"
            ? "bg-violet-100 text-violet-700"
            : normalized === "SUPPORT_ACCESS"
              ? "bg-amber-100 text-amber-700"
              : "bg-slate-100 text-slate-700";

  return (
    <span
      className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-black ${style}`}
    >
      {normalized || "ACTION"}
    </span>
  );
}

function LoadingRows({ columns }: { columns: number }) {
  return (
    <>
      {[1, 2, 3].map((row) => (
        <tr key={row} className="border-t border-slate-100">
          <td colSpan={columns} className="p-4">
            <div className="h-12 animate-pulse rounded-xl bg-slate-100" />
          </td>
        </tr>
      ))}
    </>
  );
}

function EmptyRow({
  columns,
  message,
}: {
  columns: number;
  message: string;
}) {
  return (
    <tr>
      <td
        colSpan={columns}
        className="p-10 text-center text-sm text-slate-500"
      >
        {message}
      </td>
    </tr>
  );
}

function EmptyState({ message }: { message: string }) {
  return (
    <div className="p-10 text-center text-sm text-slate-500">
      {message}
    </div>
  );
}

function money(value: unknown): string {
  return Number(value || 0).toLocaleString("ar-SA", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(value: string | null | undefined): string {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("ar-SA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("ar-SA", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}