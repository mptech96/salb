<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\{
    AccountController,
    AccountingIntegrityController,
    AccountStatementController,
    AccountingReportController,
    AdvancedReportController,
    AuditLogController,
    AuthController,
    BranchController,
    CarController,
    CompanyController,
    CompanySettingController,
    CommercialReturnController,
    CustomerController,
    DataImportController,
    DashboardController,
    DriverController,
    ExpenseController,
    ExpenseTypeController,
    EntitlementAdminController,
    FixedAssetCategoryController,
    FixedAssetController,
    FinancialYearController,
    OpeningBalanceController,
    FinancialSetupController,
    FinancialAccountController,
    InventoryController,
    InventoryOperationController,
    ItemController,
    JournalEntryController,
    OfficialDocumentController,
    PayrollController,
    PermissionMatrixController,
    PlanController,
    PublicRegistrationController,
    PurchaseInvoiceController,
    PurchaseOrderController,
    ReportController,
    RoleController,
    SalesInvoiceController,
    SalesQuotationController,
    ShipmentController,
    ShipmentCostController,
    ShipmentWeighbridgeAllocationController,
    SubscriptionController,
    SubscriptionPaymentController,
    SupplierController,
    SystemAdminDashboardController,
    TrialBalanceController,
    TaxReportController,
    UserController,
    VoucherController,
    WeighbridgeController,
    WorkerController
};

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'API Working 🔥',
    ]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register-company', [PublicRegistrationController::class, 'register']);
Route::get('/plans', [PlanController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'auth.context'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile/password', [AuthController::class, 'updatePassword']);
    Route::post('/support/exit', [AuthController::class, 'exitSupport']);

    /*
    |-----------------------------------------------------------------------
    | Shared Administration
    |-----------------------------------------------------------------------
    | يعمل لمدير المنصة أو مدير الشركة حسب السياق الذي حسمه الخادم.
    */

    Route::middleware(['support.access', 'usage.limit', 'route.permission'])->group(function () {
        Route::apiResource('branches', BranchController::class);
        Route::apiResource('users', UserController::class);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    /*
    |-----------------------------------------------------------------------
    | Platform Administration
    |-----------------------------------------------------------------------
    */

    Route::middleware(['platform.admin','privileged.audit'])->group(function () {
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::post(
            '/companies/{id}/support-access',
            [CompanyController::class, 'supportAccess']
        );
        Route::post('/system-admin/support-sessions/{supportSessionId}/revoke',[CompanyController::class,'revokeSupport']);

        Route::get(
            '/system-admin/dashboard',
            [SystemAdminDashboardController::class, 'index']
        );

        Route::get(
            '/system-admin/subscriptions',
            [SubscriptionController::class, 'index']
        );
        Route::get(
            '/system-admin/subscriptions/{id}',
            [SubscriptionController::class, 'show']
        );
        Route::post(
            '/system-admin/subscriptions/{id}/renew',
            [SubscriptionController::class, 'renew']
        );
        Route::put(
            '/system-admin/subscriptions/{id}/plan',
            [SubscriptionController::class, 'changePlan']
        );
        Route::put(
            '/system-admin/subscriptions/{id}/status',
            [SubscriptionController::class, 'updateStatus']
        );
        Route::post(
            '/system-admin/subscriptions/{id}/extend',
            [SubscriptionController::class, 'extend']
        );

        Route::get('/system-admin/plans', [PlanController::class, 'adminIndex']);
        Route::post('/system-admin/plans', [PlanController::class, 'store']);
        Route::get('/system-admin/plans/{id}', [PlanController::class, 'show']);
        Route::put('/system-admin/plans/{id}', [PlanController::class, 'update']);
        Route::put('/system-admin/plans/{id}/toggle', [PlanController::class, 'toggle']);
        Route::delete('/system-admin/plans/{id}', [PlanController::class, 'destroy']);
        Route::get('/system-admin/features', [EntitlementAdminController::class, 'catalog']);
        Route::get('/system-admin/plans/{planId}/features', [EntitlementAdminController::class, 'plan']);
        Route::put('/system-admin/plans/{planId}/features', [EntitlementAdminController::class, 'updatePlan']);
        Route::get('/system-admin/companies/{companyId}/entitlements', [EntitlementAdminController::class, 'effective']);
        Route::post('/system-admin/companies/{companyId}/entitlement-overrides', [EntitlementAdminController::class, 'override']);
        Route::post('/system-admin/subscriptions/{subscriptionId}/entitlement-snapshot', [EntitlementAdminController::class, 'snapshot']);

        Route::get(
            '/system-admin/payment-dashboard',
            [SubscriptionPaymentController::class, 'dashboard']
        );
        Route::get(
            '/system-admin/invoices',
            [SubscriptionPaymentController::class, 'invoices']
        );
        Route::post(
            '/system-admin/invoices',
            [SubscriptionPaymentController::class, 'storeInvoice']
        );
        Route::put(
            '/system-admin/invoices/{id}/cancel',
            [SubscriptionPaymentController::class, 'cancelInvoice']
        );
        Route::get(
            '/system-admin/payments',
            [SubscriptionPaymentController::class, 'payments']
        );
        Route::post(
            '/system-admin/payments',
            [SubscriptionPaymentController::class, 'storePayment']
        );

    });

    /*
    |-----------------------------------------------------------------------
    | Company Portal
    |-----------------------------------------------------------------------
    */

    Route::middleware(['company.context', 'support.access', 'subscription.access', 'tenant.scope', 'feature.entitlement', 'usage.limit', 'route.permission'])->group(function () {
        Route::get('/items/meta', [ItemController::class, 'meta']);
        Route::post('/item-groups', [ItemController::class, 'storeGroup']);
        Route::post('/item-categories', [ItemController::class, 'storeCategory']);
        Route::apiResource('items', ItemController::class);
        Route::get('/cars/meta', [CarController::class, 'meta']);
        Route::apiResource('cars', CarController::class);
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::get('/drivers/meta', [DriverController::class, 'meta']);
        Route::apiResource('drivers', DriverController::class);
        Route::apiResource('workers', WorkerController::class);
        Route::get('/vouchers/meta', [VoucherController::class, 'meta']);
        Route::apiResource('vouchers', VoucherController::class);
        Route::get('/expenses/meta', [ExpenseController::class, 'meta']);
        Route::apiResource('expenses', ExpenseController::class);
        Route::get('/expense-types/accounts', [ExpenseTypeController::class, 'accounts']);
        Route::get('/expense-types', [ExpenseTypeController::class, 'index']);
        Route::post('/expense-types', [ExpenseTypeController::class, 'store']);
        Route::put('/expense-types/{id}', [ExpenseTypeController::class, 'update']);
        Route::delete('/expense-types/{id}', [ExpenseTypeController::class, 'destroy']);

        Route::get('/company-settings', [CompanySettingController::class, 'show']);
        Route::post('/company-settings', [CompanySettingController::class, 'update']);
        Route::post('/company-settings/upload', [CompanySettingController::class, 'upload']);
        Route::get('/company-settings/assets/{type}', [CompanySettingController::class, 'asset']);
        Route::delete('/company-settings/assets/{type}', [CompanySettingController::class, 'removeAsset']);

        Route::get('/financial-accounts/meta', [FinancialAccountController::class, 'meta']);
        Route::get('/financial-accounts', [FinancialAccountController::class, 'index']);
        Route::get('/financial-accounts/{id}/transactions', [FinancialAccountController::class, 'transactions']);
        Route::post('/financial-accounts', [FinancialAccountController::class, 'store']);
        Route::put('/financial-accounts/{id}', [FinancialAccountController::class, 'update']);
        Route::delete('/financial-accounts/{id}', [FinancialAccountController::class, 'destroy']);

        Route::get('/financial-setup', [FinancialSetupController::class, 'index']);
        Route::post('/financial-setup/settings', [FinancialSetupController::class, 'settings']);
        Route::post('/financial-setup/currency', [FinancialSetupController::class, 'currency']);
        Route::post('/financial-setup/exchange-rate', [FinancialSetupController::class, 'exchangeRate']);
        Route::post('/financial-setup/tax-code', [FinancialSetupController::class, 'taxCode']);
        Route::post('/financial-setup/cost-center', [FinancialSetupController::class, 'costCenter']);

        Route::get('/opening-balances/meta', [OpeningBalanceController::class, 'meta']);
        Route::get('/opening-balances', [OpeningBalanceController::class, 'index']);
        Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);
        Route::get('/opening-balances/{id}', [OpeningBalanceController::class, 'show']);
        Route::put('/opening-balances/{id}', [OpeningBalanceController::class, 'update']);
        Route::post('/opening-balances/{id}/post', [OpeningBalanceController::class, 'post']);

        Route::get('/accounts/tree', [AccountController::class, 'tree']);
        Route::get('/accounts/posting', [AccountController::class, 'posting']);
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::get('/journal-entries', [JournalEntryController::class, 'index']);
        Route::post('/journal-entries', [JournalEntryController::class, 'store']);
        Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
        Route::post('/journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse']);
        Route::get('/trial-balance', [TrialBalanceController::class, 'index']);

        Route::get('/financial-years', [FinancialYearController::class, 'index']);
        Route::post('/financial-years', [FinancialYearController::class, 'store']);
        Route::get('/financial-years/{id}/close-preview', [FinancialYearController::class, 'preview']);
        Route::post('/financial-years/{id}/close', [FinancialYearController::class, 'close']);
        Route::post('/financial-years/{id}/reopen', [FinancialYearController::class, 'reopen']);

        Route::get('/accounting/overview', [AccountingReportController::class, 'overview']);
        Route::get('/accounting/trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('/accounting/income-statement', [AccountingReportController::class, 'incomeStatement']);
        Route::get('/accounting/balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('/accounting/ledger', [AccountingReportController::class, 'ledger']);

        Route::controller(InventoryController::class)->group(function () {
            Route::get('/inventory', 'index');
            Route::get('/inventory/lots', 'lots');
            Route::get('/inventory/movements', 'movements');
            Route::get('/inventory/valuation', 'valuation');
            Route::post('/inventory/adjustment', 'adjustment');
        });

        Route::get('/inventory-operations', [InventoryOperationController::class, 'index']);
        Route::get('/inventory-operations-meta', [InventoryOperationController::class, 'meta']);
        Route::get('/inventory-operations/meta', [InventoryOperationController::class, 'meta']);
        Route::post('/inventory-operations', [InventoryOperationController::class, 'store']);
        Route::get('/inventory-operations/{id}', [InventoryOperationController::class, 'show']);
        Route::post('/inventory-operations/{id}/approve', [InventoryOperationController::class, 'approve']);
        Route::delete('/inventory-operations/{id}', [InventoryOperationController::class, 'destroy']);

        Route::get('/weighbridge/cards', [WeighbridgeController::class, 'index']);
        Route::get('/weighbridge/available-shipments', [WeighbridgeController::class, 'availableShipments']);
        Route::get('/weighbridge/meta', [WeighbridgeController::class, 'meta']);
        Route::post('/weighbridge/cards', [WeighbridgeController::class, 'open']);
        Route::get('/weighbridge/cards/{id}', [WeighbridgeController::class, 'show']);
        Route::put('/weighbridge/cards/{id}/material', [WeighbridgeController::class, 'material']);
        Route::post('/weighbridge/cards/{id}/weights', [WeighbridgeController::class, 'recordWeight']);
        Route::post('/weighbridge/cards/{id}/link-shipment', [WeighbridgeController::class, 'linkShipment']);
        Route::post('/weighbridge/cards/{id}/deduction', [WeighbridgeController::class, 'deduction']);
        Route::post('/weighbridge/cards/{id}/close', [WeighbridgeController::class, 'close']);
        Route::post('/weighbridge/weights/{weightId}/cancel', [WeighbridgeController::class, 'cancelWeight']);

        Route::get('/purchase-invoices/meta', [PurchaseInvoiceController::class, 'meta']);
        Route::post('/purchase-invoices/{id}/post', [PurchaseInvoiceController::class, 'post']);
        Route::post('/purchase-invoices/{id}/void', [PurchaseInvoiceController::class, 'void']);
        Route::apiResource('purchase-invoices', PurchaseInvoiceController::class);
        Route::get('/sales-invoices/meta', [SalesInvoiceController::class, 'meta']);
        Route::post('/sales-invoices/{id}/post', [SalesInvoiceController::class, 'post']);
        Route::post('/sales-invoices/{id}/void', [SalesInvoiceController::class, 'void']);
        Route::apiResource('sales-invoices', SalesInvoiceController::class);

        Route::get('/quotations/meta', [SalesQuotationController::class, 'meta']);
        Route::get('/quotations/{id}/print', [SalesQuotationController::class, 'show']);
        Route::post('/quotations/{id}/send', [SalesQuotationController::class, 'send']);
        Route::post('/quotations/{id}/accept', [SalesQuotationController::class, 'accept']);
        Route::post('/quotations/{id}/reject', [SalesQuotationController::class, 'reject']);
        Route::post('/quotations/{id}/cancel', [SalesQuotationController::class, 'cancel']);
        Route::post('/quotations/{id}/convert-to-invoice', [SalesQuotationController::class, 'convert']);
        Route::apiResource('quotations', SalesQuotationController::class);

        Route::get('/purchase-orders/meta', [PurchaseOrderController::class, 'meta']);
        Route::get('/purchase-orders/{id}/print', [PurchaseOrderController::class, 'show']);
        Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
        Route::post('/purchase-orders/{id}/send', [PurchaseOrderController::class, 'send']);
        Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::post('/purchase-orders/{id}/convert-to-invoice', [PurchaseOrderController::class, 'convert']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);

        Route::get('/shipments/meta', [ShipmentController::class, 'meta']);
        Route::post('/shipments/{id}/ready', [ShipmentController::class, 'ready']);
        Route::post('/shipments/{id}/reopen', [ShipmentController::class, 'reopen']);
        Route::post('/shipments/{id}/approve', [ShipmentController::class, 'approve']);
        Route::get('/shipments/{shipmentId}/weighbridge-allocations', [ShipmentWeighbridgeAllocationController::class, 'show']);
        Route::put('/shipments/{shipmentId}/weighbridge-allocations', [ShipmentWeighbridgeAllocationController::class, 'update']);
        Route::apiResource('shipments', ShipmentController::class);
        Route::get('/shipment-costs/types', [ShipmentCostController::class, 'types']);
        Route::get('/shipments/{shipmentId}/costs', [ShipmentCostController::class, 'index']);
        Route::post('/shipment-costs', [ShipmentCostController::class, 'store']);
        Route::put('/shipment-costs/{id}', [ShipmentCostController::class, 'update']);
        Route::delete('/shipment-costs/{id}', [ShipmentCostController::class, 'destroy']);

        Route::post('/workers/{id}/loans', [WorkerController::class, 'addLoan']);
        Route::post('/workers/{id}/commissions', [WorkerController::class, 'addCommission']);
        Route::post('/workers/{id}/attendance', [WorkerController::class, 'addAttendance']);
        Route::post(
            '/workers/commissions/{commissionId}/approve',
            [WorkerController::class, 'approveCommission']
        );
        Route::post(
            '/workers/commissions/{commissionId}/pay',
            [WorkerController::class, 'payCommission']
        );

        Route::prefix('payroll')->group(function () {
            Route::get('/meta', [PayrollController::class, 'meta']);
            Route::get('/', [PayrollController::class, 'index']);
            Route::post('/generate', [PayrollController::class, 'generate']);
            Route::post('/{id}/approve', [PayrollController::class, 'approve']);
            Route::post('/{id}/pay', [PayrollController::class, 'pay']);
            Route::get(
                '/{runId}/salary-slip/{workerId}',
                [PayrollController::class, 'salarySlip']
            );
            Route::get('/{id}', [PayrollController::class, 'show']);
        });

        Route::get('/fixed-asset-categories', [FixedAssetCategoryController::class, 'index']);
        Route::post('/fixed-asset-categories', [FixedAssetCategoryController::class, 'store']);
        Route::put('/fixed-asset-categories/{id}', [FixedAssetCategoryController::class, 'update']);

        Route::get('/fixed-assets', [FixedAssetController::class, 'index']);
        Route::post('/fixed-assets', [FixedAssetController::class, 'store']);
        Route::post('/fixed-assets/{id}/transfer', [FixedAssetController::class, 'transfer']);
        Route::post('/fixed-assets/{id}/maintenance', [FixedAssetController::class, 'createMaintenance']);
        Route::post(
            '/fixed-asset-maintenance/{id}/approve',
            [FixedAssetController::class, 'approveMaintenance']
        );
        Route::post(
            '/fixed-asset-maintenance/{id}/complete',
            [FixedAssetController::class, 'completeMaintenance']
        );
        Route::post('/fixed-assets/depreciation/run', [FixedAssetController::class, 'runDepreciation']);
        Route::post('/fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose']);
        Route::post('/fixed-assets/{id}/sell', [FixedAssetController::class, 'sell']);
        Route::get('/fixed-assets/reports/summary', [FixedAssetController::class, 'reportSummary']);
        Route::get('/fixed-assets/reports/assets', [FixedAssetController::class, 'reportAssets']);
        Route::get('/fixed-assets/reports/depreciations', [FixedAssetController::class, 'reportDepreciations']);
        Route::get('/fixed-assets/reports/maintenances', [FixedAssetController::class, 'reportMaintenances']);
        Route::get('/fixed-assets/reports/movements', [FixedAssetController::class, 'reportMovements']);
        Route::get('/fixed-assets/{id}', [FixedAssetController::class, 'show']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/car-profit', [ReportController::class, 'carProfit']);
        Route::get('/reports/supplier-balances', [ReportController::class, 'supplierBalances']);
        Route::get('/reports/customer-balances', [ReportController::class, 'customerBalances']);
        Route::get('/reports/driver-balances', [ReportController::class, 'driverBalances']);
        Route::get('/reports/expense-summary', [ReportController::class, 'expenseSummary']);

        Route::get('/reports/catalog', [AdvancedReportController::class, 'catalog']);
        Route::get('/reports/run/{key}', [AdvancedReportController::class, 'run']);
        Route::get('/reports/export/{key}', [AdvancedReportController::class, 'export']);

        Route::get('/imports/catalog', [DataImportController::class, 'catalog']);
        Route::get('/imports/history', [DataImportController::class, 'history']);
        Route::get('/imports/batch/{id}', [DataImportController::class, 'batch']);
        Route::get('/imports/template/{entity}', [DataImportController::class, 'template']);
        Route::get('/imports/export/{entity}', [DataImportController::class, 'export']);
        Route::post('/imports/preview/{entity}', [DataImportController::class, 'preview']);
        Route::post('/imports/{entity}', [DataImportController::class, 'import']);

        Route::get('/statements/entities/{kind}', [AccountStatementController::class, 'entities']);
        Route::get('/statements/account/{id}', [AccountStatementController::class, 'account']);
        Route::get('/statements/account/{id}/export', [AccountStatementController::class, 'accountExport']);
        Route::get('/statements/supplier/{id}', [AccountStatementController::class, 'supplier']);
        Route::get('/statements/customer/{id}', [AccountStatementController::class, 'customer']);
        Route::get('/statements/driver/{id}', [AccountStatementController::class, 'driver']);
        Route::get('/statements/worker/{id}', [AccountStatementController::class, 'worker']);

        Route::get('/permission-matrix', [PermissionMatrixController::class, 'index']);
        Route::get('/permission-matrix/users/{userId}', [PermissionMatrixController::class, 'show']);
        Route::put('/permission-matrix/users/{userId}', [PermissionMatrixController::class, 'update']);
        Route::get('/commercial-returns/meta', [CommercialReturnController::class, 'meta']);
        Route::get('/commercial-returns/source/{invoiceId}', [CommercialReturnController::class, 'source']);
        Route::post('/commercial-returns/{id}/post', [CommercialReturnController::class, 'post']);
        Route::post('/commercial-returns/{id}/void', [CommercialReturnController::class, 'void']);
        Route::apiResource('commercial-returns', CommercialReturnController::class)->except(['destroy']);

        Route::get('/tax-reports', [TaxReportController::class, 'index']);
        Route::get('/accounting-integrity', [AccountingIntegrityController::class, 'index']);

        Route::get('/official-documents', [OfficialDocumentController::class, 'index']);
        Route::post('/official-documents', [OfficialDocumentController::class, 'store']);
        Route::get(
            '/official-documents/attachments/{attachmentId}/download',
            [OfficialDocumentController::class, 'downloadAttachment']
        );
        Route::get('/official-documents/{id}', [OfficialDocumentController::class, 'show']);
        Route::put('/official-documents/{id}', [OfficialDocumentController::class, 'update']);
        Route::delete('/official-documents/{id}', [OfficialDocumentController::class, 'destroy']);
        Route::post(
            '/official-documents/{id}/attachments',
            [OfficialDocumentController::class, 'uploadAttachment']
        );
        Route::delete(
            '/official-documents/attachments/{attachmentId}',
            [OfficialDocumentController::class, 'deleteAttachment']
        );
    });
});
