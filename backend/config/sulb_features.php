<?php

return [
    'features' => [
        'core' => ['name' => 'Core ERP', 'module' => 'core'],
        'weighbridge' => ['name' => 'Weighbridge', 'module' => 'weighbridge'],
        'shipments' => ['name' => 'Shipments', 'module' => 'shipments'],
        'purchases' => ['name' => 'Purchases', 'module' => 'purchases'],
        'sales' => ['name' => 'Sales', 'module' => 'sales'],
        'inventory' => ['name' => 'Inventory', 'module' => 'inventory'],
        'processing' => ['name' => 'Processing', 'module' => 'inventory'],
        'accounting' => ['name' => 'Accounting', 'module' => 'accounting'],
        'tax' => ['name' => 'Tax', 'module' => 'accounting'],
        'reports' => ['name' => 'Reports', 'module' => 'reports'],
        'imports' => ['name' => 'Import / Export', 'module' => 'imports'],
        'fixed_assets' => ['name' => 'Fixed Assets', 'module' => 'assets'],
        'payroll' => ['name' => 'Payroll', 'module' => 'payroll'],
        'official_documents' => ['name' => 'Official Documents', 'module' => 'documents'],
    ],
    'limits' => ['max_users', 'max_branches', 'max_stores', 'max_vehicles', 'max_documents'],
    'route_prefixes' => [
        'weighbridge' => 'weighbridge', 'shipments' => 'shipments', 'shipment-costs' => 'shipments',
        'purchase-invoices' => 'purchases', 'purchase-orders' => 'purchases', 'sales-invoices' => 'sales', 'quotations' => 'sales', 'commercial-returns' => 'sales',
        'inventory' => 'inventory', 'inventory-operations' => 'processing',
        'accounts' => 'accounting', 'journal-entries' => 'accounting', 'trial-balance' => 'accounting',
        'accounting' => 'accounting', 'financial-years' => 'accounting', 'opening-balances' => 'accounting',
        'financial-accounts' => 'accounting', 'financial-setup' => 'accounting', 'tax-reports' => 'tax',
        'reports' => 'reports', 'statements' => 'reports', 'imports' => 'imports',
        'fixed-assets' => 'fixed_assets', 'fixed-asset' => 'fixed_assets', 'payroll' => 'payroll',
        'official-documents' => 'official_documents',
    ],
];
