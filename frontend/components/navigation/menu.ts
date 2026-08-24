export type NavigationItem = {
  href: string;
  label: string;
  icon: string;
  permission?: string;
  roles?: string[];
  allowedRoles?: string[];
  hiddenForRoles?: string[];
  disabled?: boolean;
  badge?: string;
};

export type NavigationGroup = {
  id: string;
  label: string;
  icon: string;
  items: NavigationItem[];
};

export const platformNavigation: NavigationGroup[] = [
  {
    id: "platform-overview",
    label: "إدارة المنصة",
    icon: "▦",
    items: [
      {
        href: "/system-center",
        label: "لوحة إدارة المنصة",
        icon: "⌂",
      },
      {
        href: "/system-center/companies",
        label: "الشركات",
        icon: "🏢",
      },
      {
        href: "/system-center/subscriptions",
        label: "الاشتراكات",
        icon: "💳",
      },
      {
        href: "/system-center/plans",
        label: "الباقات",
        icon: "◆",
      },
      {
        href: "/system-center/payments",
        label: "الفواتير والمدفوعات",
        icon: "💰",
      },
    ],
  },
  {
    id: "platform-control",
    label: "الرقابة والتحكم",
    icon: "⚙",
    items: [
      {
        href: "/users",
        label: "مستخدمو الشركات",
        icon: "👥",
      },
      {
        href: "/branches",
        label: "فروع الشركات",
        icon: "🏬",
      },
      {
        href: "/audit-logs",
        label: "سجل عمليات المنصة",
        icon: "🧾",
      },
      {
        href: "/profile/security",
        label: "الحساب وكلمة المرور",
        icon: "🔐",
      },
    ],
  },
];

export const companyNavigation: NavigationGroup[] = [
  {
    id: "dashboard",
    label: "الرئيسية",
    icon: "⌂",
    items: [
      {
        href: "/",
        label: "لوحة الشركة",
        icon: "▦",
        permission: "dashboard.view",
        roles: ["BRANCH_MANAGER"],
      },
    ],
  },
  {
    id: "weighing-movement",
    label: "الميزان والحركة",
    icon: "⚖",
    items: [
      {
        href: "/cars",
        label: "دليل السيارات",
        icon: "🚚",
        permission: "cars.view",
        hiddenForRoles: ["SALES"],
      },
      {
        href: "/shipments",
        label: "الشحنات والحمولات",
        icon: "🚛",
        permission: "shipments.view",
      },
      {
        href: "/weighing",
        label: "محطة الميزان",
        icon: "⚖",
        permission: "weighbridge.view",
      },
    ],
  },
  {
    id: "trade",
    label: "المشتريات والمبيعات",
    icon: "↔",
    items: [
      {
        href: "/purchases",
        label: "فواتير المشتريات",
        icon: "🛒",
        permission: "purchases.view",
      },
      {
        href: "/sales",
        label: "فواتير المبيعات",
        icon: "🧾",
        permission: "sales.view",
      },
      {
        href: "/commercial-returns",
        label: "مردودات البيع والشراء",
        icon: "↩",
        permission: "returns.draft",
      },
      {
        href: "/suppliers",
        label: "الموردون",
        icon: "📦",
        permission: "suppliers.view",
      },
      {
        href: "/customers",
        label: "العملاء",
        icon: "👤",
        permission: "customers.view",
      },
    ],
  },
  {
    id: "inventory-processing",
    label: "المخزون والمعالجة",
    icon: "▤",
    items: [
      {
        href: "/items",
        label: "دليل الأصناف",
        icon: "▤",
        permission: "items.view",
        hiddenForRoles: ["SALES"],
      },
      {
        href: "/inventory",
        label: "أرصدة وحركة المخزون",
        icon: "▦",
        permission: "inventory.view",
        roles: ["STORE"],
      },
      {
        href: "/inventory-operations",
        label: "عمليات المخزون والمعالجة",
        icon: "⟳",
        permission: "inventory.view",
      },
    ],
  },
  {
    id: "transport-workforce",
    label: "النقل والعمالة",
    icon: "🚘",
    items: [
      {
        href: "/drivers",
        label: "السائقون",
        icon: "🚘",
        permission: "drivers.view",
      },
      {
        href: "/workers",
        label: "العمال والموظفون",
        icon: "👷",
        permission: "workers.view",
      },
      {
        href: "/payroll",
        label: "الرواتب والاستحقاقات",
        icon: "💳",
        permission: "workers.view",
        allowedRoles: ["ACCOUNTANT"],
      },
    ],
  },
  {
    id: "finance",
    label: "المالية والمحاسبة",
    icon: "◫",
    items: [
      {
        href: "/accounting",
        label: "المركز المحاسبي",
        icon: "📊",
        permission: "statements.view",
      },
      {
        href: "/journal-entries",
        label: "القيود اليومية",
        icon: "🧾",
        permission: "statements.view",
      },
      {
        href: "/financial-years",
        label: "السنوات المالية والإقفال",
        icon: "📅",
        permission: "statements.view",
      },
      {
        href: "/accounting-reports",
        label: "القوائم المالية",
        icon: "📈",
        permission: "statements.view",
      },
      {
        href: "/expenses",
        label: "المصروفات",
        icon: "💸",
        permission: "expenses.view",
      },
      {
        href: "/vouchers",
        label: "سندات القبض والصرف",
        icon: "📄",
        permission: "vouchers.view",
      },
      {
        href: "/financial-accounts",
        label: "الخزائن والبنوك",
        icon: "🏦",
        permission: "financial_accounts.view",
      },
      {
        href: "/opening-balances",
        label: "الأرصدة الافتتاحية",
        icon: "⚖",
        permission: "opening_balances.view",
      },
      {
        href: "/accounts",
        label: "دليل الحسابات",
        icon: "🌳",
        permission: "statements.view",
        roles: ["ACCOUNTANT"],
      },
      {
        href: "/statements",
        label: "كشوف الحسابات",
        icon: "📑",
        permission: "statements.view",
      },
      {
        href: "/tax-reports",
        label: "تقرير الضرائب",
        icon: "🧮",
        permission: "tax_reports.view",
      },
      {
        href: "/accounting-integrity",
        label: "سلامة المحاسبة والمخزون",
        icon: "✓",
        permission: "accounting.integrity.view",
      },
    ],
  },
  {
    id: "assets",
    label: "الأصول",
    icon: "🏗",
    items: [
      {
        href: "/fixed-assets/workspace",
        label: "الأصول الثابتة",
        icon: "🏗",
        permission: "dashboard.view",
        roles: ["ACCOUNTANT"],
        allowedRoles: ["ACCOUNTANT"],
      },
    ],
  },
  {
    id: "reports",
    label: "التقارير والتحليل",
    icon: "📊",
    items: [
      {
        href: "/reports",
        label: "مركز التقارير",
        icon: "📊",
        permission: "reports.view",
      },
      {
        href: "/imports",
        label: "استيراد البيانات",
        icon: "⇧",
        permission: "imports.view",
      },
    ],
  },
  {
    id: "company-management",
    label: "إدارة الشركة",
    icon: "⚙",
    items: [
      {
        href: "/branches",
        label: "الفروع ومراكز العمل",
        icon: "🏬",
        permission: "branches.view",
        roles: ["BRANCH_MANAGER"],
      },
      {
        href: "/users",
        label: "المستخدمون والصلاحيات",
        icon: "👥",
        permission: "users.view",
        roles: ["BRANCH_MANAGER"],
      },
      {
        href: "/permissions-center",
        label: "صلاحيات الإجراءات",
        icon: "🛡",
        permission: "users.permissions.manage",
      },
      {
        href: "/financial-setup",
        label: "العملات والضرائب ومراكز التكلفة",
        icon: "🌐",
        permission: "financial_setup.view",
      },
      {
        href: "/settings",
        label: "إعدادات الشركة",
        icon: "⚙",
        permission: "settings.view",
      },
      {
        href: "/official-documents",
        label: "الوثائق الرسمية",
        icon: "📁",
        permission: "official_documents.view",
      },
      {
        href: "/audit-logs",
        label: "سجل النشاط",
        icon: "🧾",
        permission: "audit_logs.view",
        roles: ["BRANCH_MANAGER"],
      },
      {
        href: "/profile/security",
        label: "الحساب وكلمة المرور",
        icon: "🔐",
      },
    ],
  },
];
