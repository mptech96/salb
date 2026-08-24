import {
  companyNavigation,
  type NavigationGroup,
  type NavigationItem,
} from "./menu";

export const MANAGER_ROLES = new Set([
  "SUPER_ADMIN",
  "MANAGER",
  "COMPANY_MANAGER",
  "COMPANY_ADMIN",
  "COMPANY_OWNER",
  "ADMIN",
  "BRANCH_MANAGER",
]);

const NAVIGATION_FEATURES: Array<[string, string]> = [
  ["/weighing", "weighbridge"], ["/shipments", "shipments"],
  ["/purchases", "purchases"], ["/sales", "sales"], ["/commercial-returns", "sales"],
  ["/inventory-operations", "processing"], ["/inventory", "inventory"],
  ["/accounting", "accounting"], ["/journal-entries", "accounting"], ["/financial-years", "accounting"],
  ["/accounts", "accounting"], ["/tax-reports", "tax"], ["/reports", "reports"],
  ["/imports", "imports"], ["/fixed-assets", "fixed_assets"], ["/payroll", "payroll"],
  ["/official-documents", "official_documents"],
];

function featureAllowed(href: string, entitlements: Record<string, boolean>): boolean {
  if (Object.keys(entitlements).length === 0) return true;
  const match = NAVIGATION_FEATURES.find(([prefix]) => href === prefix || href.startsWith(`${prefix}/`));
  return !match || entitlements[match[1]] === true;
}

export function canAccessEntitledPath(href: string, entitlements: Record<string, boolean>): boolean {
  return featureAllowed(href, entitlements);
}

export function canAccessNavigationItem(
  item: NavigationItem,
  roleCode: string,
  permissions: string[],
  allowAll: boolean,
  entitlements: Record<string, boolean> = {}
): boolean {
  if (!featureAllowed(item.href, entitlements)) return false;
  if (allowAll) return true;
  if (item.hiddenForRoles?.includes(roleCode)) return false;

  if (item.allowedRoles && !item.allowedRoles.includes(roleCode)) {
    return false;
  }

  // لا نسمح لـ roles بتجاوز صلاحية حقيقية موجودة في الـBackend.
  if (item.permission) {
    return permissions.includes(item.permission);
  }

  if (item.roles) {
    return item.roles.includes(roleCode);
  }

  return true;
}

export function filterNavigation(
  groups: NavigationGroup[],
  roleCode: string,
  permissions: string[],
  allowAll: boolean,
  entitlements: Record<string, boolean> = {}
): NavigationGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) =>
        canAccessNavigationItem(item, roleCode, permissions, allowAll, entitlements)
      ),
    }))
    .filter((group) => group.items.length > 0);
}

export function getCompanyLandingPath(
  roleCodeValue: string,
  permissions: string[],
  isSupportMode = false,
  entitlements: Record<string, boolean> = {}
): string {
  const roleCode = String(roleCodeValue || "").toUpperCase();
  const allowAll = isSupportMode || MANAGER_ROLES.has(roleCode);
  const groups = filterNavigation(
    companyNavigation,
    roleCode,
    permissions,
    allowAll,
    entitlements
  );

  const visiblePaths = new Set(
    groups.flatMap((group) =>
      group.items.filter((item) => !item.disabled).map((item) => item.href)
    )
  );

  const preferredByRole: Record<string, string[]> = {
    ACCOUNTANT: ["/accounts", "/expenses", "/statements"],
    STORE: ["/inventory", "/items", "/shipments"],
    SALES: ["/sales", "/customers", "/reports"],
    VIEWER: ["/reports", "/statements", "/shipments"],
  };

  const preferred = preferredByRole[roleCode] || ["/"];

  for (const path of preferred) {
    if (visiblePaths.has(path)) return path;
  }

  for (const group of groups) {
    const first = group.items.find((item) => !item.disabled);
    if (first) return first.href;
  }

  return "/no-access";
}
