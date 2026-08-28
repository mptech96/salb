import Link from "next/link";
import type { ReactNode } from "react";

export function LifecycleStrip({
  title,
  steps,
}: {
  title: string;
  steps: Array<{ label: string; hint?: string }>;
}) {
  return (
    <section className="min-w-0 rounded-xl border border-slate-200 bg-white p-3 shadow-sm" aria-label={title}>
      <p className="mb-2 text-xs font-black text-slate-500">{title}</p>
      <ol className="flex max-w-full gap-2 overflow-x-auto pb-1" aria-label="مراحل دورة العمل">
        {steps.map((step, index) => (
          <li key={`${step.label}-${index}`} className="flex shrink-0 items-center gap-2">
            <span className="grid size-6 place-items-center rounded-full bg-[#0B2A4A] text-[11px] font-black text-white">
              {index + 1}
            </span>
            <span className="rounded-lg bg-slate-50 px-3 py-2">
              <span className="block text-xs font-black text-slate-800">{step.label}</span>
              {step.hint && <span className="mt-0.5 block text-[11px] text-slate-500">{step.hint}</span>}
            </span>
            {index < steps.length - 1 && <span className="text-slate-300" aria-hidden="true">←</span>}
          </li>
        ))}
      </ol>
    </section>
  );
}

export function WorkspaceNotice({
  tone = "info",
  children,
}: {
  tone?: "info" | "warning";
  children: ReactNode;
}) {
  const style = tone === "warning"
    ? "border-amber-200 bg-amber-50 text-amber-900"
    : "border-blue-200 bg-blue-50 text-blue-900";

  return (
    <div role="note" className={`rounded-xl border px-4 py-3 text-xs font-semibold leading-6 ${style}`}>
      {children}
    </div>
  );
}

export function ModuleLinks({
  links,
}: {
  links: Array<{ href: string; label: string; description: string }>;
}) {
  return (
    <nav className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" aria-label="روابط الوحدة">
      {links.map((link) => (
        <Link key={link.href} href={link.href} className="min-w-0 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-slate-300 hover:bg-slate-50">
          <span className="block text-sm font-black text-[#0B2A4A]">{link.label}</span>
          <span className="mt-1 block text-xs leading-5 text-slate-500">{link.description}</span>
        </Link>
      ))}
    </nav>
  );
}
