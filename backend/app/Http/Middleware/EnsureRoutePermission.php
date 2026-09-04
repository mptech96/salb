<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoutePermission
{
    private const COMPANY_MANAGER_ROLES = [
        'MANAGER','COMPANY_MANAGER','COMPANY_ADMIN','COMPANY_OWNER','ADMIN',
    ];

    private const RESOURCE_MAP = [
        'branches' => 'branches', 'items' => 'items', 'cars' => 'cars', 'suppliers' => 'suppliers',
        'customers' => 'customers', 'purchase-invoices' => 'purchases', 'sales-invoices' => 'sales',
        'vouchers' => 'vouchers', 'expenses' => 'expenses', 'shipments' => 'shipments',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $actualRole = strtoupper((string) $request->attributes->get('actual_role_code', ''));
        $role = strtoupper((string) $request->attributes->get('effective_role_code', ''));
        $support = (bool) $request->attributes->get('is_support_mode', false);
        $uri = $this->normalizeRouteUri((string) optional($request->route())->uri());
        $name = (string) optional($request->route())->getName();
        $method = strtoupper($request->method());

        // مدير المنصة خارج وضع الدعم: مسارات المنصة فقط، مع بقاء صفحات الإدارة المشتركة للعرض الإداري.
        if ($actualRole === 'SUPER_ADMIN' && !$support) {
            if ($this->isCompanyPortalUri($uri)) {
                return $this->deny('PLATFORM_COMPANY_CONTEXT_REQUIRED', 'ادخل إلى الشركة عبر وضع الدعم أولًا.');
            }
            return $next($request);
        }

        // وضع الدعم ومدير الشركة: كل وحدات الشركة، لكن العزل يظل مفروضًا من الخادم.
        if ($support) return $next($request); // EnsureSupportAccess enforced the durable support capability first.
        if (in_array($role, self::COMPANY_MANAGER_ROLES, true)) {
            return $next($request);
        }

        // مدير الفرع يظل محصورًا داخل فرعه، لكن الإجراءات التشغيلية تمر على مصفوفة الصلاحيات.
        // بهذه الطريقة يمكن للمستخدم الرئيسي تحديد: موظف ميزان / مجهز شحنات / مفوتر / مرحل / مخزون / محاسب.
        if ($role === 'BRANCH_MANAGER') {
            if (str_starts_with($name, 'branches.') && !in_array($name, ['branches.index','branches.show'], true)) {
                return $this->deny('BRANCH_ADMIN_SCOPE_DENIED', 'مدير الفرع يستطيع عرض فرعه فقط ولا يستطيع إدارة هيكل فروع الشركة.');
            }
            if (str_starts_with($uri, 'company-settings') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('COMPANY_SETTINGS_WRITE_DENIED', 'تعديل إعدادات الشركة العامة متاح للمستخدم الرئيسي/مدير الشركة فقط.');
            }
            if (str_starts_with($uri, 'financial-years') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('FINANCIAL_YEAR_MANAGEMENT_DENIED', 'إدارة وإقفال السنوات المالية متاحة للمستخدم الرئيسي/مدير الشركة فقط.');
            }
            if (str_starts_with($uri, 'opening-balances') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('OPENING_BALANCE_MANAGEMENT_DENIED', 'ترحيل وتعديل الأرصدة الافتتاحية متاح للمستخدم الرئيسي/مدير الشركة فقط.');
            }
            if (str_starts_with($uri, 'financial-setup/') && !str_ends_with($uri, '/cost-center') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('FINANCIAL_SETUP_MANAGEMENT_DENIED', 'إدارة العملات والضرائب العامة متاحة للمستخدم الرئيسي/مدير الشركة فقط.');
            }
        }

        $required = $this->requiredPermission($request, $uri);
        if (!$required) return $this->deny('PERMISSION_NOT_MAPPED', 'لم يتم تعريف صلاحية آمنة لهذا المسار.');

        $permissions = $request->attributes->get('permission_codes', []);
        if (!is_array($permissions) || !in_array($required, $permissions, true)) {
            return response()->json([
                'status' => false, 'code' => 'PERMISSION_DENIED',
                'message' => 'لا تملك الصلاحية المطلوبة لتنفيذ هذه العملية.',
                'required_permission' => $required,
            ], 403);
        }
        return $next($request);
    }

    private function requiredPermission(Request $request, string $uri): ?string
    {
        $name = (string) optional($request->route())->getName();
        $method = strtoupper($request->method());

        // SULB Stage6: صلاحيات على مستوى الإجراء، وليس الشاشة فقط.
        if ($uri === 'permission-matrix' || str_starts_with($uri, 'permission-matrix/')) return 'users.permissions.manage';
        if ($uri === 'tax-reports' || str_starts_with($uri, 'tax-reports/')) return 'tax_reports.view';
        if ($uri === 'accounting-integrity' || str_starts_with($uri, 'accounting-integrity/')) return 'accounting.integrity.view';
        if (str_starts_with($uri,'commercial-returns')) { if (str_contains($uri,'/post')) return 'returns.post'; if (str_contains($uri,'/void')) return 'returns.void'; return in_array($method,['GET','HEAD'],true)?'returns.draft':'returns.draft'; }
        if (str_starts_with($uri,'quotations')) {
            if (str_contains($uri,'/convert-to-invoice')) return 'quotations.convert';
            if (str_contains($uri,'/accept')||str_contains($uri,'/reject')) return 'quotations.accept';
            if (str_contains($uri,'/send')) return 'quotations.send'; if (str_contains($uri,'/cancel')||$method==='DELETE') return 'quotations.cancel';
            if (in_array($method,['GET','HEAD'],true)) return str_contains($uri,'/print')?'quotations.print':'quotations.view';
            return $method==='POST'?'quotations.create':'quotations.update';
        }
        if (str_starts_with($uri,'purchase-orders')) {
            if (str_contains($uri,'/convert-to-invoice')) return 'purchase_orders.convert'; if (str_contains($uri,'/approve')) return 'purchase_orders.approve';
            if (str_contains($uri,'/send')) return 'purchase_orders.send'; if (str_contains($uri,'/cancel')||$method==='DELETE') return 'purchase_orders.cancel';
            if (in_array($method,['GET','HEAD'],true)) return str_contains($uri,'/print')?'purchase_orders.print':'purchase_orders.view';
            return $method==='POST'?'purchase_orders.create':'purchase_orders.update';
        }

        if (str_starts_with($uri, 'weighbridge')) {
            if (in_array($method,['GET','HEAD'],true)) return 'weighbridge.view';
            if ($uri === 'weighbridge/cards') return 'weighbridge.open';
            if (str_contains($uri,'/link-shipment')) return 'weighbridge.link';
            if (str_contains($uri,'/close')) return 'weighbridge.close';
            if (str_contains($uri,'/weights')) return 'weighbridge.record';
            return 'weighbridge.record';
        }

        if ($uri === 'shipments/{id}/ready') return 'shipments.ready';
        if ($uri === 'shipments/{id}/reopen') return 'shipments.reopen';
        if ($uri === 'shipments/{id}/approve') return 'shipments.ready';
        if (str_contains($uri,'/weighbridge-allocations')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.weighbridge.allocate';
        if (str_starts_with($uri,'shipment-costs') || str_contains($uri,'/costs')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.cost';
        if ($uri === 'shipments/meta') return 'shipments.view';
        if (str_starts_with($uri,'shipments')) {
            if (in_array($method,['GET','HEAD'],true)) return 'shipments.view';
            if ($method === 'DELETE') return 'shipments.delete';
            return 'shipments.prepare';
        }

        if ($uri === 'purchase-invoices/{id}/post') return 'purchases.post';
        if ($uri === 'purchase-invoices/{id}/void') return 'purchases.void';
        if ($uri === 'purchase-invoices/meta') return 'purchases.view';
        if (str_starts_with($uri,'purchase-invoices')) return in_array($method,['GET','HEAD'],true) ? 'purchases.view' : 'purchases.draft';

        if ($uri === 'sales-invoices/{id}/post') return 'sales.post';
        if ($uri === 'sales-invoices/{id}/void') return 'sales.void';
        if ($uri === 'sales-invoices/meta') return 'sales.view';
        if (str_starts_with($uri,'sales-invoices')) return in_array($method,['GET','HEAD'],true) ? 'sales.view' : 'sales.draft';

        if ($uri === 'inventory-operations/{id}/approve') return 'inventory.process.post';
        if ($uri === 'inventory-operations/meta') return 'inventory.view';
        if (str_starts_with($uri,'inventory-operations')) return in_array($method,['GET','HEAD'],true) ? 'inventory.view' : 'inventory.process';

        if ($uri === 'items/meta') return 'items.view';
        if ($uri === 'item-groups' || $uri === 'item-categories') return 'items.create';
        if ($uri === 'cars/meta') return 'cars.view';
        if ($uri === 'drivers/meta') return 'drivers.view';
        if ($name && preg_match('/^([a-z-]+)\.(index|show|store|update|destroy)$/', $name, $m)) {
            [$all,$resource,$action] = $m;
            if ($resource === 'users') return 'users.view';
            if ($resource === 'drivers') return in_array($action,['index','show'],true) ? 'drivers.view' : 'drivers.manage';
            if ($resource === 'workers') return 'workers.view';
            if (isset(self::RESOURCE_MAP[$resource])) {
                $module = self::RESOURCE_MAP[$resource];
                return match ($action) {
                    'index','show' => $module.'.view', 'store' => $module.'.create',
                    'update' => $module.'.update', 'destroy' => $module.'.delete', default => null,
                };
            }
        }

        if ($uri === 'roles') return 'users.view';
        if ($uri === 'audit-logs') return 'audit_logs.view';
        if ($uri === 'dashboard') return 'dashboard.view';
        if ($uri === 'sales-invoices/meta') return 'sales.view';
        if ($uri === 'purchase-invoices/meta') return 'purchases.view';
        if ($uri === 'vouchers/meta') return 'vouchers.view';
        if ($uri === 'expenses/meta') return 'expenses.view';
        if (str_starts_with($uri, 'expense-types')) {
            return match ($method) {
                'GET', 'HEAD' => 'expenses.view',
                'POST' => 'expenses.create',
                'PUT', 'PATCH' => 'expenses.update',
                'DELETE' => 'expenses.delete',
                default => null,
            };
        }
        if ($uri === 'shipments/meta') return 'shipments.view';
        if (str_starts_with($uri, 'reports/export/')) return 'reports.export';
        if (str_starts_with($uri, 'reports/')) return 'reports.view';
        if (str_starts_with($uri, 'statements/')) return 'statements.view';
        if (str_starts_with($uri, 'company-settings')) return in_array(strtoupper($request->method()), ['GET','HEAD'], true) ? 'settings.view' : null;
        if (str_starts_with($uri, 'financial-accounts')) return in_array($method,['GET','HEAD'],true) ? 'financial_accounts.view' : 'financial_accounts.manage';
        if (str_starts_with($uri, 'financial-setup')) return in_array($method,['GET','HEAD'],true) ? 'financial_setup.view' : (str_ends_with($uri,'/cost-center') ? 'cost_centers.manage' : 'financial_setup.manage');
        if (str_starts_with($uri, 'opening-balances')) return in_array($method,['GET','HEAD'],true) ? 'opening_balances.view' : 'opening_balances.post';
        if (str_starts_with($uri, 'official-documents')) return 'official_documents.view';
        if (str_starts_with($uri, 'inventory-operations')) return in_array($method,['GET','HEAD'],true) ? 'inventory.view' : 'inventory.process';
        if (str_starts_with($uri, 'inventory')) return 'inventory.view';
        if (str_starts_with($uri, 'imports/export/')) return 'imports.export';
        if (str_starts_with($uri, 'imports')) return in_array($method,['GET','HEAD'],true) ? 'imports.view' : 'imports.execute';
        if (str_starts_with($uri, 'weighbridge')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.update';
        if (str_starts_with($uri, 'accounts')) return in_array($method,['GET','HEAD'],true) ? 'statements.view' : 'financial_setup.manage';
        if (str_starts_with($uri, 'journal-entries') || $uri === 'trial-balance' || str_starts_with($uri, 'accounting/') || str_starts_with($uri, 'financial-years')) return 'statements.view';
        if ($uri === 'shipments/sell') return 'sales.create';
        if ($uri === 'shipments/{id}/approve') return 'shipments.approve';
        if (str_starts_with($uri, 'shipment-costs') || str_contains($uri, '/costs')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.update';
        if (str_starts_with($uri, 'drivers')) return in_array($method,['GET','HEAD'],true) ? 'drivers.view' : 'drivers.manage';
        if (str_starts_with($uri, 'workers') || str_starts_with($uri, 'payroll')) return 'workers.view';
        if (str_starts_with($uri, 'fixed-asset')) return 'dashboard.view';
        return null;
    }

    private function normalizeRouteUri(string $uri): string
    {
        $normalized = trim($uri, '/');

        return str_starts_with($normalized, 'api/')
            ? substr($normalized, 4)
            : $normalized;
    }

    private function isCompanyPortalUri(string $uri): bool
    {
        foreach (['items','cars','suppliers','customers','drivers','workers','vouchers','expenses','company-settings','financial-accounts','financial-setup','opening-balances','accounts','journal-entries','trial-balance','accounting','financial-years','inventory','inventory-operations','weighbridge','imports','purchase-invoices','sales-invoices','quotations','purchase-orders','shipments','shipment-costs','payroll','fixed-assets','fixed-asset','dashboard','reports','statements','official-documents','permission-matrix','tax-reports','commercial-returns','accounting-integrity'] as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) return true;
        }
        return false;
    }

    private function deny(string $code, string $message): Response
    {
        return response()->json(['status' => false, 'code' => $code, 'message' => $message], 403);
    }
}
