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
        $uri = (string) optional($request->route())->uri();
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
        if ($support || in_array($role, self::COMPANY_MANAGER_ROLES, true)) {
            return $next($request);
        }

        // مدير الفرع = مدير تشغيل كامل داخل فرعه، وليس دورًا محدودًا.
        if ($role === 'BRANCH_MANAGER') {
            // لا يسمح بإنشاء/تعديل/حذف فروع من داخل فرع، ولا تعديل إعدادات الشركة العامة.
            if (str_starts_with($name, 'branches.') && !in_array($name, ['branches.index','branches.show'], true)) {
                return $this->deny('BRANCH_ADMIN_SCOPE_DENIED', 'مدير الفرع يستطيع عرض فرعه فقط ولا يستطيع إدارة هيكل فروع الشركة.');
            }
            if (str_starts_with($uri, 'company-settings') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('COMPANY_SETTINGS_WRITE_DENIED', 'تعديل إعدادات الشركة العامة متاح لمدير الشركة فقط.');
            }
            if (str_starts_with($uri, 'financial-years') && !in_array($method, ['GET','HEAD'], true)) {
                return $this->deny('FINANCIAL_YEAR_MANAGEMENT_DENIED', 'إدارة وإقفال السنوات المالية متاحة لمدير الشركة فقط.');
            }
            return $next($request);
        }

        $required = $this->requiredPermission($request);
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

    private function requiredPermission(Request $request): ?string
    {
        $name = (string) optional($request->route())->getName();
        if ($name && preg_match('/^([a-z-]+)\.(index|show|store|update|destroy)$/', $name, $m)) {
            [$all,$resource,$action] = $m;
            if ($resource === 'users') return 'users.view';
            if ($resource === 'drivers') return 'drivers.view';
            if ($resource === 'workers') return 'workers.view';
            if (isset(self::RESOURCE_MAP[$resource])) {
                $module = self::RESOURCE_MAP[$resource];
                return match ($action) {
                    'index','show' => $module.'.view', 'store' => $module.'.create',
                    'update' => $module.'.update', 'destroy' => $module.'.delete', default => null,
                };
            }
        }

        $uri = (string) optional($request->route())->uri();
        $method = strtoupper($request->method());
        if ($uri === 'roles') return 'users.view';
        if ($uri === 'audit-logs') return 'audit_logs.view';
        if ($uri === 'dashboard') return 'dashboard.view';
        if (str_starts_with($uri, 'reports/')) return 'reports.view';
        if (str_starts_with($uri, 'statements/')) return 'statements.view';
        if (str_starts_with($uri, 'company-settings')) return 'settings.view';
        if (str_starts_with($uri, 'official-documents')) return 'official_documents.view';
        if (str_starts_with($uri, 'inventory')) return 'inventory.view';
        if (str_starts_with($uri, 'weighbridge')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.update';
        if (str_starts_with($uri, 'accounts') || str_starts_with($uri, 'journal-entries') || $uri === 'trial-balance' || str_starts_with($uri, 'accounting/') || str_starts_with($uri, 'financial-years')) return 'statements.view';
        if ($uri === 'shipments/sell') return 'sales.create';
        if ($uri === 'shipments/{id}/approve') return 'shipments.approve';
        if (str_starts_with($uri, 'shipment-costs') || str_contains($uri, '/costs')) return in_array($method,['GET','HEAD'],true) ? 'shipments.view' : 'shipments.update';
        if (str_starts_with($uri, 'drivers')) return 'drivers.view';
        if (str_starts_with($uri, 'workers') || str_starts_with($uri, 'payroll')) return 'workers.view';
        if (str_starts_with($uri, 'fixed-asset')) return 'dashboard.view';
        return null;
    }

    private function isCompanyPortalUri(string $uri): bool
    {
        foreach (['items','cars','suppliers','customers','drivers','workers','vouchers','expenses','company-settings','accounts','journal-entries','trial-balance','accounting','financial-years','inventory','weighbridge','purchase-invoices','sales-invoices','shipments','shipment-costs','payroll','fixed-assets','fixed-asset','dashboard','reports','statements','official-documents'] as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) return true;
        }
        return false;
    }

    private function deny(string $code, string $message): Response
    {
        return response()->json(['status' => false, 'code' => $code, 'message' => $message], 403);
    }
}
