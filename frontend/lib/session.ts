export const SESSION_CHANGED_EVENT = "sulb-session-changed";

const STORAGE_KEYS = {
  user: "scrap_user",
  subscription: "scrap_subscription",
  permissions: "scrap_permissions",
  token: "scrap_token",
  platformBackup: "scrap_platform_session_backup",
} as const;

export type SessionUser = {
  id: number;
  company_id: number | null;
  branch_id: number | null;
  name: string;
  username: string;
  email?: string | null;
  phone?: string | null;
  company_name?: string | null;
  branch_name?: string | null;
  role?: {
    id?: number | null;
    role_name?: string | null;
    role_code?: string | null;
  } | null;
  permissions?: string[];
  is_support_mode?: boolean;
  actual_role_code?: string | null;
  platform_admin_id?: number | null;
  support_session_id?: string | null;
  support_access_level?: "READ_ONLY" | "WRITE" | null;
  support_capabilities?: string[];
  support_ticket_reference?: string | null;
  support_expires_at?: string | null;
  support_status?: string | null;
};

export type StoredSession = {
  token: string;
  user: SessionUser;
  subscription: Record<string, unknown> | null;
  permissions: string[];
};

type SaveSessionPayload = {
  token: string;
  user: SessionUser;
  subscription?: Record<string, unknown> | null;
  permissions?: string[];
};

function isBrowser(): boolean {
  return typeof window !== "undefined";
}

function parseJson<T>(value: string | null, fallback: T): T {
  if (!value) return fallback;

  try {
    return JSON.parse(value) as T;
  } catch {
    return fallback;
  }
}

export function getAuthToken(): string | null {
  if (!isBrowser()) return null;
  return localStorage.getItem(STORAGE_KEYS.token);
}

export function readSession(): StoredSession | null {
  if (!isBrowser()) return null;

  const token = localStorage.getItem(STORAGE_KEYS.token);
  const user = parseJson<SessionUser | null>(
    localStorage.getItem(STORAGE_KEYS.user),
    null
  );

  if (!token || !user) return null;

  const permissions = parseJson<string[]>(
    localStorage.getItem(STORAGE_KEYS.permissions),
    Array.isArray(user.permissions) ? user.permissions : []
  );

  return {
    token,
    user: {
      ...user,
      permissions,
    },
    subscription: parseJson<Record<string, unknown> | null>(
      localStorage.getItem(STORAGE_KEYS.subscription),
      null
    ),
    permissions,
  };
}

export function saveSession(payload: SaveSessionPayload): StoredSession {
  if (!isBrowser()) {
    throw new Error("Session storage is only available in the browser.");
  }

  const permissions = Array.isArray(payload.permissions)
    ? payload.permissions
    : Array.isArray(payload.user.permissions)
    ? payload.user.permissions
    : [];

  const user = {
    ...payload.user,
    permissions,
  };

  localStorage.setItem(STORAGE_KEYS.token, payload.token);
  localStorage.setItem(STORAGE_KEYS.user, JSON.stringify(user));
  localStorage.setItem(
    STORAGE_KEYS.subscription,
    JSON.stringify(payload.subscription ?? null)
  );
  localStorage.setItem(
    STORAGE_KEYS.permissions,
    JSON.stringify(permissions)
  );

  window.dispatchEvent(new Event(SESSION_CHANGED_EVENT));

  return {
    token: payload.token,
    user,
    subscription: payload.subscription ?? null,
    permissions,
  };
}

export function updateSessionPayload(payload: {
  user: SessionUser;
  subscription?: Record<string, unknown> | null;
}): StoredSession | null {
  const current = readSession();
  if (!current) return null;

  return saveSession({
    token: current.token,
    user: payload.user,
    subscription: payload.subscription ?? null,
    permissions: payload.user.permissions ?? current.permissions,
  });
}

export function clearCurrentSession(): void {
  if (!isBrowser()) return;

  localStorage.removeItem(STORAGE_KEYS.user);
  localStorage.removeItem(STORAGE_KEYS.subscription);
  localStorage.removeItem(STORAGE_KEYS.permissions);
  localStorage.removeItem(STORAGE_KEYS.token);
  window.dispatchEvent(new Event(SESSION_CHANGED_EVENT));
}

export function clearAllSessions(): void {
  if (!isBrowser()) return;
  clearCurrentSession();
  localStorage.removeItem(STORAGE_KEYS.platformBackup);
  localStorage.removeItem("scrap_platform_admin_user");
  localStorage.removeItem("scrap_platform_admin_permissions");
}

export function backupPlatformSession(): StoredSession {
  const current = readSession();

  if (!current) {
    throw new Error("لا توجد جلسة منصة لحفظها.");
  }

  localStorage.setItem(
    STORAGE_KEYS.platformBackup,
    JSON.stringify(current)
  );

  return current;
}

export function hasPlatformSessionBackup(): boolean {
  if (!isBrowser()) return false;
  return Boolean(localStorage.getItem(STORAGE_KEYS.platformBackup));
}

export function restorePlatformSession(): StoredSession | null {
  if (!isBrowser()) return null;

  const backup = parseJson<StoredSession | null>(
    localStorage.getItem(STORAGE_KEYS.platformBackup),
    null
  );

  if (!backup?.token || !backup.user) {
    return null;
  }

  const restored = saveSession(backup);
  localStorage.removeItem(STORAGE_KEYS.platformBackup);

  return restored;
}
