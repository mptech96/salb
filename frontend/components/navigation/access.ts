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

export function canAccessNavigationItem(
  item: NavigationItem,
  roleCode: string,
  permissions: string[],
  allowAll: boolean
): boolean {
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
  allowAll: boolean
): NavigationGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) =>
        canAccessNavigationItem(item, roleCode, permissions, allowAll)
      ),
    }))
    .filter((group) => group.items.length > 0);
}

export function getCompanyLandingPath(
  roleCodeValue: string,
  permissions: string[],
  isSupportMode = false
): string {
  const roleCode = String(roleCodeValue || "").toUpperCase();
  const allowAll = isSupportMode || MANAGER_ROLES.has(roleCode);
  const groups = filterNavigation(
    companyNavigation,
    roleCode,
    permissions,
    allowAll
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
