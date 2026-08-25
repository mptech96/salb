import Link from "next/link";
import type { ReactNode } from "react";

export const fieldClassName =
  "min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500";

export const primaryButtonClassName =
  "inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-[#123b5d] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#0b2a4a] disabled:cursor-not-allowed disabled:opacity-50";

export const secondaryButtonClassName =
  "inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";

type BreadcrumbItem = { label: string; href?: string };

export function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
  if (!items.length) return null;

  return (
    <nav aria-label="مسار الصفحة" className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
      {items.map((item, index) => (
        <span key={`${item.label}-${index}`} className="inline-flex items-center gap-2">
          {index > 0 && <span aria-hidden="true" className="text-slate-300">/</span>}
          {item.href ? (
            <Link href={item.href} className="transition hover:text-sky-700">{item.label}</Link>
          ) : (
            <span className="font-medium text-slate-600">{item.label}</span>
          )}
        </span>
      ))}
    </nav>
  );
}

export function PageHeader({
  title,
  description,
  breadcrumbs,
  actions,
}: {
  title: string;
  description?: string;
  breadcrumbs?: BreadcrumbItem[];
  actions?: ReactNode;
}) {
  return (
    <header className="space-y-3">
      {breadcrumbs && <Breadcrumbs items={breadcrumbs} />}
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div className="min-w-0">
          <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
          {description && <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{description}</p>}
        </div>
        {actions && <PageToolbar>{actions}</PageToolbar>}
      </div>
    </header>
  );
}

export function PageToolbar({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap items-center gap-2">{children}</div>;
}

export function SurfaceCard({
  children,
  title,
  description,
  actions,
  className = "",
}: {
  children: ReactNode;
  title?: string;
  description?: string;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <section className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5">
          <div>
            {title && <h2 className="text-sm font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-1 text-xs leading-5 text-slate-500">{description}</p>}
          </div>
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function StatCard({
  label,
  value,
  description,
  href,
  tone = "neutral",
}: {
  label: string;
  value: ReactNode;
  description?: string;
  href?: string;
  tone?: "neutral" | "positive" | "negative" | "accent";
}) {
  const tones = {
    neutral: "text-slate-900",
    positive: "text-emerald-700",
    negative: "text-rose-700",
    accent: "text-sky-800",
  };
  const content = (
    <>
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p dir="ltr" className={`mt-2 text-right text-xl font-bold tabular-nums ${tones[tone]}`}>{value}</p>
      {description && <p className="mt-1 text-xs text-slate-400">{description}</p>}
    </>
  );
  const className = "block rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition";

  return href ? (
    <Link href={href} className={`${className} hover:border-sky-300 hover:shadow`}>{content}</Link>
  ) : (
    <div className={className}>{content}</div>
  );
}

export function FormSection({
  title,
  description,
  children,
  actions,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <SurfaceCard title={title} description={description} actions={actions}>
      <div className="p-4 sm:p-5">{children}</div>
    </SurfaceCard>
  );
}

export function FormField({
  label,
  children,
  hint,
  required,
  className = "",
}: {
  label: string;
  children: ReactNode;
  hint?: string;
  required?: boolean;
  className?: string;
}) {
  return (
    <label className={`block min-w-0 ${className}`}>
      <span className="mb-1.5 block text-xs font-medium text-slate-700">
        {label}{required && <span className="mr-1 text-rose-600">*</span>}
      </span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

export function StatusBadge({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: "neutral" | "success" | "warning" | "danger" | "info";
}) {
  const tones = {
    neutral: "bg-slate-100 text-slate-700",
    success: "bg-emerald-50 text-emerald-700",
    warning: "bg-amber-50 text-amber-800",
    danger: "bg-rose-50 text-rose-700",
    info: "bg-sky-50 text-sky-800",
  };

  return <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${tones[tone]}`}>{children}</span>;
}

export function DataTableShell({
  children,
  title,
  description,
  actions,
  footer,
}: {
  children: ReactNode;
  title?: string;
  description?: string;
  actions?: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <SurfaceCard title={title} description={description} actions={actions} className="overflow-hidden">
      <div className="max-w-full overflow-x-auto">{children}</div>
      {footer && <div className="border-t border-slate-100 px-4 py-3">{footer}</div>}
    </SurfaceCard>
  );
}

export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center">{children}</div>;
}

export function EmptyState({
  title = "لا توجد بيانات",
  description,
  action,
}: {
  title?: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex min-h-32 flex-col items-center justify-center gap-2 px-5 py-8 text-center">
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {description && <p className="max-w-md text-xs leading-5 text-slate-500">{description}</p>}
      {action}
    </div>
  );
}

export function LoadingState({ label = "جارٍ تحميل البيانات..." }: { label?: string }) {
  return (
    <div role="status" className="flex min-h-32 items-center justify-center gap-3 px-5 py-8 text-sm text-slate-500">
      <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-slate-200 border-t-sky-700" />
      {label}
    </div>
  );
}

export function AccessState({
  title,
  description,
  tone = "warning",
  action,
}: {
  title: string;
  description?: string;
  tone?: "warning" | "danger" | "info";
  action?: ReactNode;
}) {
  const tones = {
    warning: "border-amber-200 bg-amber-50 text-amber-900",
    danger: "border-rose-200 bg-rose-50 text-rose-900",
    info: "border-sky-200 bg-sky-50 text-sky-900",
  };

  return (
    <div role="alert" className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 ${tones[tone]}`}>
      <div><p className="text-sm font-semibold">{title}</p>{description && <p className="mt-1 text-xs leading-5 opacity-80">{description}</p>}</div>
      {action}
    </div>
  );
}

export function AppTopbar({
  title,
  companyName,
  branchName,
  userName,
  isPlatformAdmin,
  onOpenMenu,
  support,
}: {
  title: string;
  companyName?: string | null;
  branchName?: string | null;
  userName?: string | null;
  isPlatformAdmin: boolean;
  onOpenMenu: () => void;
  support?: {
    companyName: string;
    accessMode: string;
    ticket?: string | null;
    expiry?: string | null;
    onExit: () => void;
  };
}) {
  return (
    <div className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
      {support && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-amber-300 bg-amber-50 px-4 py-2 text-xs text-amber-950 sm:px-6">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-bold">وضع الدعم</span>
            <span>{support.companyName}</span>
            <StatusBadge tone={support.accessMode === "READ_ONLY" ? "warning" : "danger"}>
              {support.accessMode === "READ_ONLY" ? "قراءة فقط" : "قراءة وكتابة"}
            </StatusBadge>
            {support.ticket && <span>المرجع: {support.ticket}</span>}
            {support.expiry && <span>ينتهي: {support.expiry}</span>}
          </div>
          <button type="button" onClick={support.onExit} className="rounded-md border border-amber-300 px-3 py-1.5 font-semibold transition hover:bg-amber-100">إنهاء وضع الدعم</button>
        </div>
      )}
      <div className="flex min-h-16 items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          <button type="button" onClick={onOpenMenu} aria-label="فتح القائمة" className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-700 lg:hidden">☰</button>
          <div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-900">{title}</p><p className="mt-0.5 truncate text-xs text-slate-500">{isPlatformAdmin ? "إدارة منصة صلب" : [companyName, branchName].filter(Boolean).join(" / ") || "بوابة الشركة"}</p></div>
        </div>
        {userName && <span className="hidden max-w-48 truncate rounded-lg bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600 sm:inline-flex">{userName}</span>}
      </div>
    </div>
  );
}
