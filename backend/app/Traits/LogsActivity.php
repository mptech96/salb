<?php

namespace App\Traits;

use App\Services\AuditService;

trait LogsActivity
{
    protected function logCreate(string $module, $recordId = null, ?string $description = null): void
    {
        AuditService::log($module, 'CREATE', $recordId, $description);
    }

    protected function logUpdate(string $module, $recordId = null, ?string $description = null): void
    {
        AuditService::log($module, 'UPDATE', $recordId, $description);
    }

    protected function logDelete(string $module, $recordId = null, ?string $description = null): void
    {
        AuditService::log($module, 'DELETE', $recordId, $description);
    }

    protected function logView(string $module, $recordId = null, ?string $description = null): void
    {
        AuditService::log($module, 'VIEW', $recordId, $description);
    }

    protected function logLogin($recordId = null, ?string $description = null): void
    {
        AuditService::log('Auth', 'LOGIN', $recordId, $description);
    }

    protected function logSupportAccess($recordId = null, ?string $description = null): void
    {
        AuditService::log('Companies', 'SUPPORT_ACCESS', $recordId, $description);
    }
}