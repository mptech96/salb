<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExpenseTypeController;
use App\Http\Controllers\Api\WorkerController;
use App\Http\Controllers\Api\AccountStatementController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\OfficialDocumentController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentCostController;
use App\Http\Controllers\Api\AccountController;





Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'API Working 🔥'
    ]);
});

Route::apiResource('branches', BranchController::class);
Route::apiResource('items', ItemController::class);
Route::apiResource('cars', CarController::class);
Route::apiResource('suppliers', SupplierController::class);
Route::apiResource('customers', CustomerController::class);
Route::apiResource('purchase-invoices', PurchaseInvoiceController::class);
Route::apiResource('sales-invoices', SalesInvoiceController::class);
Route::controller(InventoryController::class)->group(function () {

    Route::get('inventory', 'index');
    Route::post('inventory/adjustment', 'adjustment');

});
Route::get('/reports/profit', [ReportController::class, 'profit']);
Route::get('/reports/car-profit', [ReportController::class, 'carProfit']);
Route::get('/reports/supplier-balances', [ReportController::class, 'supplierBalances']);
Route::get('/reports/customer-balances', [ReportController::class, 'customerBalances']);
Route::get('/reports/driver-balances', [ReportController::class, 'driverBalances']);
Route::get('/reports/expense-summary', [ReportController::class, 'expenseSummary']);
Route::apiResource('vouchers', VoucherController::class);
Route::apiResource('drivers', DriverController::class);
Route::apiResource('expenses', ExpenseController::class);
Route::get('/expense-types', [ExpenseTypeController::class, 'index']);
Route::post('/expense-types', [ExpenseTypeController::class, 'store']);
Route::get('statements/supplier/{id}', [AccountStatementController::class, 'supplier']);
Route::get('statements/customer/{id}', [AccountStatementController::class, 'customer']);
Route::get('statements/driver/{id}', [AccountStatementController::class, 'driver']);
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::apiResource('companies', CompanyController::class);
Route::get('/companies/{id}/support-access', [CompanyController::class, 'supportAccess']);
Route::get('/plans', [PlanController::class, 'index']);
Route::apiResource('users', UserController::class);
Route::get('/roles', [RoleController::class, 'index']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/audit-logs', [AuditLogController::class, 'index']);
Route::get('/company-settings', [CompanySettingController::class, 'show']);
Route::post('/company-settings', [CompanySettingController::class, 'update']);
Route::post('/company-settings/upload', [CompanySettingController::class, 'upload']);
Route::get('/official-documents', [OfficialDocumentController::class, 'index']);
Route::post('/official-documents', [OfficialDocumentController::class, 'store']);
Route::get('/official-documents/{id}', [OfficialDocumentController::class, 'show']);
Route::put('/official-documents/{id}', [OfficialDocumentController::class, 'update']);
Route::delete('/official-documents/{id}', [OfficialDocumentController::class, 'destroy']);
Route::post('/official-documents/{id}/attachments', [OfficialDocumentController::class, 'uploadAttachment']);
Route::delete('/official-documents/attachments/{attachmentId}', [OfficialDocumentController::class, 'deleteAttachment']);
Route::get('statements/worker/{id}', [AccountStatementController::class, 'worker']);
Route::post('/shipments/sell', [ShipmentController::class, 'sell']);
Route::post('/shipments/{id}/approve', [ShipmentController::class, 'approve']);
Route::apiResource('shipments', ShipmentController::class);
Route::post('/workers/{id}/loans', [WorkerController::class, 'addLoan']);
Route::post('/workers/{id}/commissions', [WorkerController::class, 'addCommission']);
Route::post('/workers/{id}/attendance', [WorkerController::class, 'addAttendance']);
Route::post('/workers/commissions/{commissionId}/approve', [WorkerController::class, 'approveCommission']);
Route::post('/workers/commissions/{commissionId}/pay', [WorkerController::class, 'payCommission']);

Route::apiResource('workers', WorkerController::class);
Route::get('/shipments/{shipmentId}/costs', [ShipmentCostController::class, 'index']);
Route::post('/shipment-costs', [ShipmentCostController::class, 'store']);
Route::put('/shipment-costs/{id}', [ShipmentCostController::class, 'update']);
Route::delete('/shipment-costs/{id}', [ShipmentCostController::class, 'destroy']);

Route::get('/accounts/tree', [AccountController::class, 'tree']);
Route::get('/accounts/posting', [AccountController::class, 'posting']);
Route::post('/accounts', [AccountController::class, 'store']);











