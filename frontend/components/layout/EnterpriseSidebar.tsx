"use client";

import Link from "next/link";
import type { SessionUser } from "@/lib/session";
import type { NavigationGroup } from "@/components/navigation/menu";

type Props = {
  groups: NavigationGroup[];
  pathname: string;
  user: SessionUser;
  portalLabel: string;
  openGroups: Set<string>;
  collapsed?: boolean;
  isMobile?: boolean;
  isSupportMode?: boolean;
  onToggleCollapsed?: () => void;
  onToggleGroup: (groupId: string) => void;
  onNavigate: () => void;
  onLogout: () => void;
  onClose?: () => void;
  onExitSupport?: () => void;
};

function isActive(pathname: string, href: string): boolean {
  return href === "/" ? pathname === "/" : pathname === href || pathname.startsWith(`${href}/`);
}

const GROUP_MARKS: Record<string, string> = {
  dashboard: "⌂",
  operations: "وز",
  purchases: "ش",
  sales: "ب",
  "inventory-processing": "م",
  "human-resources": "ع",
  finance: "ح",
  assets: "أ",
  reports: "ت",
  "company-management": "إ",
  "platform-overview": "ن",
  "platform-control": "ر",
};

function NavMark({ groupId, label }: { groupId: string; label: string }) {
  return (
    <span aria-hidden="true" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-white/10 bg-white/[0.07] text-[11px] font-bold text-sky-100">
      {GROUP_MARKS[groupId] || label.trim().slice(0, 1)}
    </span>
  );
}

export default function EnterpriseSidebar({
  groups,
  pathname,
  user,
  portalLabel,
  openGroups,
  collapsed = false,
  isMobile = false,
  isSupportMode = false,
  onToggleCollapsed,
  onToggleGroup,
  onNavigate,
  onLogout,
  onClose,
  onExitSupport,
}: Props) {
  const compact = collapsed && !isMobile;

  return (
    <div className="flex h-full flex-col bg-[var(--sulb-sidebar)] text-white" data-tour="sidebar">
      <div className="border-b border-white/10 px-3 py-3">
        <div className={`flex items-center ${compact ? "justify-center" : "justify-between"} gap-2`}>
          <div className="flex min-w-0 items-center gap-2.5">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white p-1">
              <img src="/sulb-logo.png" alt="صلب ERP" className="h-full w-full object-contain" />
            </div>
            {!compact && (
              <div className="min-w-0">
                <div className="truncate text-sm font-bold">صلب ERP</div>
                <div className="truncate text-[10px] text-slate-300">{portalLabel}</div>
              </div>
            )}
          </div>
          {isMobile && onClose ? (
            <button type="button" onClick={onClose} className="enterprise-icon-button border-white/15 text-white" aria-label="إغلاق القائمة">×</button>
          ) : null}
        </div>

        {!compact && (
          <div className="mt-3 rounded-md border border-white/10 bg-white/[0.045] px-3 py-2.5">
            <div className="truncate text-xs font-semibold">{user.name || "—"}</div>
            <div className="mt-1 truncate text-[11px] text-slate-300">{user.company_name || "إدارة المنصة"}</div>
            <div className="truncate text-[11px] text-slate-400">{user.branch_name || "مركز التحكم"} · {user.role?.role_name || "—"}</div>
          </div>
        )}

        {isSupportMode && !compact ? (
          <button type="button" onClick={onExitSupport} className="mt-2 w-full rounded-md border border-amber-300/25 bg-amber-300/10 px-3 py-2 text-right text-[11px] font-semibold text-amber-100">
            وضع الدعم نشط · العودة للمنصة
          </button>
        ) : null}
      </div>

      <nav className="min-h-0 flex-1 overflow-y-auto px-2 py-2" aria-label="التنقل الرئيسي">
        <div className="space-y-1">
          {groups.map((group) => {
            const active = group.items.some((item) => isActive(pathname, item.href));
            const expanded = openGroups.has(group.id);

            if (compact) {
              const target = group.items.find((item) => isActive(pathname, item.href)) || group.items[0];
              return (
                <Link key={group.id} href={target.href} onClick={onNavigate} title={group.label} data-tour-group={group.id} className={`flex h-10 items-center justify-center rounded-md transition ${active ? "bg-white text-[var(--sulb-sidebar)]" : "text-slate-200 hover:bg-white/10"}`}>
                  <span className="text-xs font-bold">{GROUP_MARKS[group.id] || group.label.slice(0, 1)}</span>
                </Link>
              );
            }

            return (
              <section key={group.id} className="rounded-md" data-tour-group={group.id}>
                <button type="button" onClick={() => onToggleGroup(group.id)} aria-expanded={expanded} className={`flex w-full items-center gap-2 rounded-md px-2 py-2 text-right transition ${active ? "bg-white/[0.08] text-white" : "text-slate-200 hover:bg-white/[0.06]"}`}>
                  <NavMark groupId={group.id} label={group.label} />
                  <span className="min-w-0 flex-1 truncate text-xs font-semibold">{group.label}</span>
                  <span className={`text-[10px] text-slate-400 transition ${expanded ? "rotate-180" : ""}`}>⌄</span>
                </button>
                {expanded ? (
                  <div className="mr-3 mt-1 space-y-0.5 border-r border-white/10 pr-3">
                    {group.items.map((item) => {
                      const itemActive = isActive(pathname, item.href);
                      return item.disabled ? (
                        <div key={item.href} className="rounded-md px-3 py-2 text-[11px] text-slate-500">{item.label}</div>
                      ) : (
                        <Link key={item.href} href={item.href} onClick={onNavigate} data-tour-path={item.href} className={`flex items-center gap-2 rounded-md px-3 py-2 text-[11px] font-medium transition ${itemActive ? "bg-white text-[var(--sulb-sidebar)]" : "text-slate-300 hover:bg-white/[0.07] hover:text-white"}`}>
                          <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60" />
                          <span className="truncate">{item.label}</span>
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

      <div className="border-t border-white/10 p-2">
        {!isMobile && onToggleCollapsed ? (
          <button type="button" onClick={onToggleCollapsed} className="mb-1 flex h-9 w-full items-center justify-center gap-2 rounded-md text-xs text-slate-300 transition hover:bg-white/[0.07] hover:text-white" aria-label={compact ? "توسيع القائمة" : "طي القائمة"} title={compact ? "توسيع القائمة" : "طي القائمة"}>
            <span>{compact ? "‹" : "›"}</span>{!compact && <span>طي القائمة</span>}
          </button>
        ) : null}
        <button type="button" onClick={onLogout} className="flex h-9 w-full items-center justify-center gap-2 rounded-md border border-white/10 bg-white/[0.04] text-xs font-medium text-slate-200 transition hover:bg-white/[0.08]">
          <span>⇥</span>{!compact && <span>تسجيل الخروج</span>}
        </button>
      </div>
    </div>
  );
}
