<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Services\PartyBranchScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantResourceScopeService
{
    public function __construct(private readonly PartyBranchScopeService $partyScopes) {}

    /** @var array<string, bool> */
    private array $tableCache = [];
    /** @var array<string, bool> */
    private array $columnCache = [];
    /** @var array<string, array{table:string,param:string,branch:bool}> */
    private const ROUTE_RESOURCES = [
        'branches/{branch}' => ['table' => 'branches', 'param' => 'branch', 'branch' => false],
        'users/{user}' => ['table' => 'users', 'param' => 'user', 'branch' => true],
        'cars/{car}' => ['table' => 'cars', 'param' => 'car', 'branch' => true],
        'suppliers/{supplier}' => ['table' => 'suppliers', 'param' => 'supplier', 'branch' => true],
        'customers/{customer}' => ['table' => 'customers', 'param' => 'customer', 'branch' => true],
        'drivers/{driver}' => ['table' => 'drivers', 'param' => 'driver', 'branch' => true],
        'workers/{worker}' => ['table' => 'workers', 'param' => 'worker', 'branch' => true],
        'workers/{id}' => ['table' => 'workers', 'param' => 'id', 'branch' => true],
        'expenses/{expense}' => ['table' => 'expenses', 'param' => 'expense', 'branch' => true],
        'vouchers/{voucher}' => ['table' => 'vouchers', 'param' => 'voucher', 'branch' => true],
        'purchase-invoices/{purchase_invoice}' => ['table' => 'purchase_invoices', 'param' => 'purchase_invoice', 'branch' => true],
        'purchase-invoices/{id}' => ['table' => 'purchase_invoices', 'param' => 'id', 'branch' => true],
        'sales-invoices/{sales_invoice}' => ['table' => 'sales_invoices', 'param' => 'sales_invoice', 'branch' => true],
        'sales-invoices/{id}' => ['table' => 'sales_invoices', 'param' => 'id', 'branch' => true],
        'quotations/{quotation}' => ['table' => 'sales_quotations', 'param' => 'quotation', 'branch' => true],
        'quotations/{id}' => ['table' => 'sales_quotations', 'param' => 'id', 'branch' => true],
        'purchase-orders/{purchase_order}' => ['table' => 'purchase_orders', 'param' => 'purchase_order', 'branch' => true],
        'purchase-orders/{id}' => ['table' => 'purchase_orders', 'param' => 'id', 'branch' => true],
        'shipments/{shipment}' => ['table' => 'shipments', 'param' => 'shipment', 'branch' => true],
        'shipments/{id}' => ['table' => 'shipments', 'param' => 'id', 'branch' => true],
        'shipments/{shipmentId}' => ['table' => 'shipments', 'param' => 'shipmentId', 'branch' => true],
        'shipment-costs/{id}' => ['table' => 'shipment_costs', 'param' => 'id', 'branch' => true],
        'items/{item}' => ['table' => 'items', 'param' => 'item', 'branch' => false],
        'financial-accounts/{id}' => ['table' => 'financial_accounts', 'param' => 'id', 'branch' => true],
        'opening-balances/{id}' => ['table' => 'opening_balance_batches', 'param' => 'id', 'branch' => false],
        'journal-entries/{id}' => ['table' => 'journal_entries', 'param' => 'id', 'branch' => true],
        'financial-years/{id}' => ['table' => 'financial_years', 'param' => 'id', 'branch' => false],
        'inventory-operations/{id}' => ['table' => 'inventory_operations', 'param' => 'id', 'branch' => true],
        'weighbridge/cards/{id}' => ['table' => 'weighbridge_cards', 'param' => 'id', 'branch' => true],
        'weighbridge/weights/{weightId}' => ['table' => 'shipment_weights', 'param' => 'weightId', 'branch' => true],
        'payroll/{id}' => ['table' => 'worker_salary_runs', 'param' => 'id', 'branch' => true],
        'fixed-assets/{id}' => ['table' => 'fixed_assets', 'param' => 'id', 'branch' => true],
        'imports/batch/{id}' => ['table' => 'data_import_batches', 'param' => 'id', 'branch' => true],
        'commercial-returns/{commercial_return}' => ['table' => 'commercial_returns', 'param' => 'commercial_return', 'branch' => true],
        'commercial-returns/{id}' => ['table' => 'commercial_returns', 'param' => 'id', 'branch' => true],
        'official-documents/{id}' => ['table' => 'official_documents', 'param' => 'id', 'branch' => true],
        'official-documents/attachments/{attachmentId}' => ['table' => 'official_document_attachments', 'param' => 'attachmentId', 'branch' => false],
        'official-documents/attachments/{attachmentId}/download' => ['table' => 'official_document_attachments', 'param' => 'attachmentId', 'branch' => false],
    ];

    /** @var array<string, array{table:string,branch:bool}> */
    private const FOREIGN_RESOURCES = [
        'branch_id' => ['table' => 'branches', 'branch' => false],
        'to_branch_id' => ['table' => 'branches', 'branch' => false],
        'user_id' => ['table' => 'users', 'branch' => true],
        'car_id' => ['table' => 'cars', 'branch' => true],
        'vehicle_id' => ['table' => 'cars', 'branch' => true],
        'supplier_id' => ['table' => 'suppliers', 'branch' => true],
        'customer_id' => ['table' => 'customers', 'branch' => true],
        'driver_id' => ['table' => 'drivers', 'branch' => true],
        'worker_id' => ['table' => 'workers', 'branch' => true],
        'shipment_id' => ['table' => 'shipments', 'branch' => true],
        'weighbridge_card_id' => ['table' => 'weighbridge_cards', 'branch' => true],
        'purchase_invoice_id' => ['table' => 'purchase_invoices', 'branch' => true],
        'sales_invoice_id' => ['table' => 'sales_invoices', 'branch' => true],
        'item_id' => ['table' => 'items', 'branch' => false],
        'store_id' => ['table' => 'stores', 'branch' => true],
        'warehouse_id' => ['table' => 'stores', 'branch' => true],
        'inventory_lot_id' => ['table' => 'inventory_lots', 'branch' => true],
        'financial_year_id' => ['table' => 'financial_years', 'branch' => false],
        'account_id' => ['table' => 'accounts', 'branch' => false],
        'financial_account_id' => ['table' => 'financial_accounts', 'branch' => true],
        'cost_center_id' => ['table' => 'cost_centers', 'branch' => true],
        'fixed_asset_id' => ['table' => 'fixed_assets', 'branch' => true],
        'document_id' => ['table' => 'official_documents', 'branch' => true],
    ];

    /** @var array<string, string> */
    private const FOREIGN_RESOURCE_LISTS = [
        'branch_ids' => 'branch_id',
        'shipment_ids' => 'shipment_id',
        'item_ids' => 'item_id',
        'inventory_lot_ids' => 'inventory_lot_id',
        'weighbridge_card_ids' => 'weighbridge_card_id',
    ];

    public function assertRequest(Request $request, int $companyId, ?int $branchId): void
    {
        $this->assertRouteResource($request, $companyId, $branchId);
        $this->assertForeignResources($request->all(), $companyId, $branchId);
    }

    public function assertOwned(string $table, int $id, int $companyId, ?int $branchId = null, bool $branchScoped = false): void
    {
        if ($id <= 0 || !$this->hasTable($table) || !$this->hasColumn($table, 'company_id')) {
            $this->deny('RESOURCE_SCOPE_UNVERIFIABLE');
        }

        $query = DB::table($table)->where('id', $id)->where('company_id', $companyId);

        if ($branchScoped && $branchId !== null && $this->hasColumn($table, 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if (!$query->exists()) {
            $this->deny('RESOURCE_OUT_OF_SCOPE');
        }
    }

    private function assertRouteResource(Request $request, int $companyId, ?int $branchId): void
    {
        $uri = $this->normalizedRouteUri($request);

        foreach (self::ROUTE_RESOURCES as $pattern => $definition) {
            if ($uri !== $pattern && !str_starts_with($uri, $pattern.'/')) {
                continue;
            }

            $value = $request->route($definition['param']);
            $value = is_object($value) && isset($value->id) ? $value->id : $value;

            if ($value !== null && is_numeric($value)) {
                if ($branchId !== null && in_array($definition['table'], ['customers', 'suppliers'], true)) {
                    $this->assertPartyAccessible($definition['table'], (int) $value, $companyId, $branchId);
                } else {
                    $this->assertOwned($definition['table'], (int) $value, $companyId, $branchId, $definition['branch']);
                }
            }

            return;
        }
    }

    public function normalizedRouteUri(Request $request): string
    {
        $uri = trim((string) optional($request->route())->uri(), '/');

        return str_starts_with($uri, 'api/') ? substr($uri, 4) : $uri;
    }

    private function assertForeignResources(array $payload, int $companyId, ?int $branchId): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && isset(self::FOREIGN_RESOURCE_LISTS[$key])) {
                if (!is_array($value)) $this->deny('FOREIGN_RESOURCE_INVALID', 422);
                foreach ($value as $id) $this->assertForeignValue(self::FOREIGN_RESOURCE_LISTS[$key], $id, $companyId, $branchId);
                continue;
            }
            if (is_string($key) && isset(self::FOREIGN_RESOURCES[$key])) {
                $this->assertForeignValue($key, $value, $companyId, $branchId);
                continue;
            }
            if (is_array($value)) $this->assertForeignResources($value, $companyId, $branchId);
        }
    }

    private function assertForeignValue(string $key, mixed $value, int $companyId, ?int $branchId): void
    {
        if ($value === null || $value === '') return;
        if (!is_numeric($value) || (int) $value <= 0) $this->deny('FOREIGN_RESOURCE_INVALID', 422);
        $definition = self::FOREIGN_RESOURCES[$key];
        if ($branchId !== null && in_array($key, ['customer_id', 'supplier_id'], true)) {
            $this->assertPartyAccessible($definition['table'], (int) $value, $companyId, $branchId);
            return;
        }
        $this->assertOwned($definition['table'], (int) $value, $companyId, $branchId, $definition['branch']);
    }

    private function assertPartyAccessible(string $table, int $id, int $companyId, int $branchId): void
    {
        try {
            $this->partyScopes->assertAccessible(
                $companyId,
                $table === 'suppliers' ? 'SUPPLIER' : 'CUSTOMER',
                $id,
                $branchId,
            );
        } catch (\RuntimeException) {
            $this->deny('RESOURCE_OUT_OF_SCOPE');
        }
    }

    private function deny(string $code, int $status = 404): never
    {
        abort(response()->json([
            'status' => false,
            'code' => $code,
            'message' => 'السجل المطلوب غير متاح ضمن نطاق الشركة أو الفرع الحالي.',
        ], $status));
    }

    private function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;
        return $this->columnCache[$key] ??= Schema::hasColumn($table, $column);
    }
}
