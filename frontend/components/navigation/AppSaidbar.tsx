"use client";

import Link from "next/link";
import type { SessionUser } from ".../../../lib/session";
import type { NavigationGroup } from "./menu";

type Props = {
  groups: NavigationGroup[];
  pathname: string;
  user: SessionUser;
  portalLabel: string;
  openGroups: Set<string>;
  onToggleGroup: (groupId: string) => void;
  onNavigate: () => void;
  onLogout: () => void;
  onClose?: () => void;
  isMobile?: boolean;
  isSupportMode?: boolean;
  onExitSupport?: () => void;
};

function isActive(pathname: string, href: string): boolean {
  if (href === "/") return pathname === "/";
  return pathname === href || pathname.startsWith(`${href}/`);
}

export default function AppSidebar({
  groups,
  pathname,
  user,
  portalLabel,
  openGroups,
  onToggleGroup,
  onNavigate,
  onLogout,
  onClose,
  isMobile = false,
  isSupportMode = false,
  onExitSupport,
}: Props) {
  return (
    <div className="flex h-full flex-col bg-[#102c44] text-white">
      <div className="border-b border-white/10 px-4 py-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white p-1">
              <img
                src="/sulb-logo.png"
                alt="شعار صلب ERP"
                className="h-full w-full object-contain"
              />
            </div>

            <div className="min-w-0">
              <h1 className="truncate text-base font-semibold">صلب ERP</h1>
              <p className="mt-0.5 truncate text-[10px] font-bold tracking-[0.22em] text-cyan-200">
                SULB ERP
              </p>
              <p className="mt-1 truncate text-xs text-blue-100">
                {portalLabel}
              </p>
            </div>
          </div>

          {isMobile && onClose ? (
            <button
              type="button"
              onClick={onClose}
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-xl hover:bg-white/15"
              aria-label="إغلاق القائمة"
            >
              ×
            </button>
          ) : null}
        </div>

        <div className="mt-4 rounded-lg border border-white/10 bg-white/[0.05] p-3">
          <div className="truncate text-sm font-black">
            {user.name || "-"}
          </div>
          <div className="mt-1 truncate text-xs leading-5 text-blue-100">
            {user.company_name || "إدارة المنصة"}
          </div>
          <div className="truncate text-xs leading-5 text-blue-100">
            {user.branch_name || "مركز التحكم"} · {user.role?.role_name || "-"}
          </div>
        </div>

        {isSupportMode ? (
          <button
            type="button"
            onClick={onExitSupport}
            className="mt-3 w-full rounded-2xl border border-amber-300/30 bg-amber-400/15 px-3 py-3 text-right text-xs font-black text-amber-100 transition hover:bg-amber-400/25"
          >
            <span className="block">وضع الدعم الفني مفعل</span>
            <span className="mt-1 block font-semibold text-amber-200">
              العودة إلى لوحة المنصة ←
            </span>
          </button>
        ) : null}
      </div>

      <nav className="min-h-0 flex-1 overflow-y-auto px-2 py-3">
        <div className="space-y-1">
          {groups.map((group) => {
            const expanded = openGroups.has(group.id);
            const hasActiveItem = group.items.some((item) =>
              isActive(pathname, item.href)
            );

            return (
              <section
                key={group.id}
                className={`overflow-hidden rounded-lg border transition ${
                  hasActiveItem
                    ? "border-cyan-300/20 bg-white/[0.07]"
                    : "border-transparent"
                }`}
              >
                <button
                  type="button"
                  onClick={() => onToggleGroup(group.id)}
                  className="flex w-full items-center gap-2 px-3 py-2 text-right transition hover:bg-white/[0.07]"
                  aria-expanded={expanded}
                >
                  <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white/10 text-sm">
                    {group.icon}
                  </span>
                  <span className="min-w-0 flex-1 truncate text-sm font-medium">
                    {group.label}
                  </span>
                  <span
                    className={`text-xs text-blue-200 transition-transform ${
                      expanded ? "rotate-180" : ""
                    }`}
                  >
                    ▾
                  </span>
                </button>

                {expanded ? (
                  <div className="space-y-1 px-2 pb-2">
                    {group.items.map((item) => {
                      const active = isActive(pathname, item.href);

                      if (item.disabled) {
                        return (
                          <div
                            key={item.href}
                            className="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-blue-100/55"
                          >
                            <span className="flex w-6 shrink-0 justify-center">
                              {item.icon}
                            </span>
                            <span className="min-w-0 flex-1 truncate">
                              {item.label}
                            </span>
                            {item.badge ? (
                              <span className="rounded-full bg-white/10 px-2 py-1 text-[10px] font-bold">
                                {item.badge}
                              </span>
                            ) : null}
                          </div>
                        );
                      }

                      return (
                        <Link
                          key={item.href}
                          href={item.href}
                          onClick={onNavigate}
                          className={`flex items-center gap-2 rounded-md px-3 py-2 text-xs font-medium transition ${
                            active
                              ? "bg-white text-[#0B2A4A]"
                              : "text-blue-50 hover:bg-white/10"
                          }`}
                        >
                          <span className="flex w-6 shrink-0 justify-center">
                            {item.icon}
                          </span>
                          <span className="min-w-0 flex-1 truncate">
                            {item.label}
                          </span>
                        </Link>
                      );
                    })}
                  </div>
                ) : null}
              </section>
            );
          })}
        </div>
      </nav>

      <div className="border-t border-white/10 p-3">
        <button
          type="button"
          onClick={onLogout}
          className="flex w-full items-center justify-center gap-2 rounded-lg border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-white/10"
        >
          <span>⇥</span>
          تسجيل الخروج
        </button>
      </div>
    </div>
  );
}
