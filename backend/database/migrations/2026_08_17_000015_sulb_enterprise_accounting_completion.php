<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | SULB ERP Stage 7 — Enterprise Accounting Completion
        |------------------------------------------------------------------
        | Runs AFTER Stage 6. Non-destructive: it adds professional account
        | mapping, walk-in parties, independent weighbridge linking,
        | weighbridge-to-item allocations, safe document sequences and
        | return documents. Historical rows are preserved.
        */

        // 1) Item accounting hierarchy: Item -> Category -> Group -> Company setting.
        foreach (['item_groups','item_categories','items'] as $table) {
            $this->addColumns($table, [
                'inventory_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('inventory_account_id')->nullable(),
                'sales_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('sales_account_id')->nullable(),
                'cogs_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('cogs_account_id')->nullable(),
                'purchase_expense_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('purchase_expense_account_id')->nullable(),
                'sales_return_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('sales_return_account_id')->nullable(),
                'purchase_return_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('purchase_return_account_id')->nullable(),
            ]);
        }

        // 2) Professional service quantities: not every line is KG/TON.
        foreach (['purchase_invoice_lines','sales_invoice_lines'] as $table) {
            $this->addColumns($table, [
                'quantity' => fn (Blueprint $t) => $t->decimal('quantity',18,6)->nullable(),
                'unit_code' => fn (Blueprint $t) => $t->string('unit_code',20)->nullable(),
                'unit_factor_to_base' => fn (Blueprint $t) => $t->decimal('unit_factor_to_base',18,6)->nullable(),
            ]);
        }

        // 3) Default walk-in parties. The party is real in the subledger; no fake free text.
        $this->addColumns('customers', [
            'is_system_default' => fn (Blueprint $t) => $t->boolean('is_system_default')->default(false),
            'ledger_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ledger_account_id')->nullable(),
        ]);
        $this->addColumns('suppliers', [
            'is_system_default' => fn (Blueprint $t) => $t->boolean('is_system_default')->default(false),
            'ledger_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('ledger_account_id')->nullable(),
        ]);
        $this->addColumns('company_settings', [
            'default_customer_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_customer_id')->nullable(),
            'default_supplier_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_supplier_id')->nullable(),
            'default_customer_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_customer_account_id')->nullable(),
            'default_supplier_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_supplier_account_id')->nullable(),
            'strict_item_accounting' => fn (Blueprint $t) => $t->boolean('strict_item_accounting')->default(true),
            'default_service_unit_code' => fn (Blueprint $t) => $t->string('default_service_unit_code',20)->default('UNIT'),
        ]);

        // 4) A weighbridge card may be created before a shipment exists.
        if (Schema::hasTable('weighbridge_cards') && DB::getDriverName() === 'mysql') {
            try { DB::statement('ALTER TABLE `weighbridge_cards` MODIFY `shipment_id` BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}
        }
        $this->addColumns('weighbridge_cards', [
            'unassigned_reason' => fn (Blueprint $t) => $t->string('unassigned_reason',500)->nullable(),
            'linked_at' => fn (Blueprint $t) => $t->dateTime('linked_at')->nullable(),
            'linked_by' => fn (Blueprint $t) => $t->unsignedBigInteger('linked_by')->nullable(),
        ]);

        // 5) Exact traceability: one card may contain multiple items and one item may span cards.
        if (!Schema::hasTable('weighbridge_card_item_allocations')) {
            Schema::create('weighbridge_card_item_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('shipment_id');
                $t->unsignedBigInteger('weighbridge_card_id');
                $t->unsignedBigInteger('shipment_item_id')->nullable();
                $t->unsignedBigInteger('item_id');
                $t->decimal('gross_qty_kg',18,3)->default(0);
                $t->decimal('deduction_qty_kg',18,3)->default(0);
                $t->decimal('accepted_qty_kg',18,3)->default(0);
                $t->string('deduction_reason',500)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id','shipment_id','weighbridge_card_id'], 'idx_wb_alloc_shipment_card');
                $t->index(['company_id','item_id'], 'idx_wb_alloc_item');
            });
        }

        // Shipment-cost reissue trace: when a purchase invoice is reversed, the old posted cost remains VOID and a fresh DRAFT copy is created for safe re-invoicing.
        $this->addColumns('shipment_costs', [
            'recreated_from_cost_id' => fn (Blueprint $t) => $t->unsignedBigInteger('recreated_from_cost_id')->nullable(),
        ]);

        // 6) Concurrency-safe numbering for scale cards / returns / future documents.
        if (!Schema::hasTable('sulb_document_sequences')) {
            Schema::create('sulb_document_sequences', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id')->default(0);
                $t->string('document_type',50);
                $t->unsignedSmallInteger('document_year');
                $t->unsignedBigInteger('next_number')->default(1);
                $t->timestamps();
                $t->unique(['company_id','branch_id','document_type','document_year'], 'uq_sulb_document_sequence');
            });
        }

        // 7) Sales/Purchase returns: posted documents, never delete financial history.
        if (!Schema::hasTable('commercial_returns')) {
            Schema::create('commercial_returns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id');
                $t->string('return_type',30); // SALES_RETURN | PURCHASE_RETURN
                $t->string('return_number',100);
                $t->date('return_date');
                $t->unsignedBigInteger('source_invoice_id');
                $t->unsignedBigInteger('party_id');
                $t->string('currency_code',10)->nullable();
                $t->decimal('exchange_rate',24,10)->default(1);
                $t->decimal('total_before_vat',18,3)->default(0);
                $t->decimal('vat_amount',18,3)->default(0);
                $t->decimal('total_amount',18,3)->default(0);
                $t->decimal('base_total_before_vat',18,3)->default(0);
                $t->decimal('base_vat_amount',18,3)->default(0);
                $t->decimal('base_total_amount',18,3)->default(0);
                $t->string('document_status',20)->default('DRAFT');
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->dateTime('posted_at')->nullable();
                $t->unsignedBigInteger('posted_by')->nullable();
                $t->dateTime('voided_at')->nullable();
                $t->unsignedBigInteger('voided_by')->nullable();
                $t->text('void_reason')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id','return_type','return_number'], 'uq_commercial_return_number');
                $t->index(['company_id','branch_id','return_type','return_date'], 'idx_commercial_return_lookup');
            });
        }
        if (!Schema::hasTable('commercial_return_lines')) {
            Schema::create('commercial_return_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('return_id');
                $t->unsignedBigInteger('source_invoice_line_id');
                $t->unsignedBigInteger('item_id');
                $t->string('item_type_snapshot',20)->default('STOCK');
                $t->boolean('track_inventory_snapshot')->default(true);
                $t->decimal('quantity',18,6)->default(0);
                $t->string('unit_code',20)->default('KG');
                $t->decimal('qty_kg',18,3)->default(0);
                $t->decimal('unit_price_per_kg',18,6)->default(0);
                $t->decimal('total_before_vat',18,3)->default(0);
                $t->unsignedBigInteger('tax_code_id')->nullable();
                $t->decimal('vat_percent',9,4)->default(0);
                $t->decimal('vat_amount',18,3)->default(0);
                $t->decimal('total_after_vat',18,3)->default(0);
                $t->decimal('base_total_before_vat',18,3)->default(0);
                $t->decimal('base_vat_amount',18,3)->default(0);
                $t->decimal('base_total_after_vat',18,3)->default(0);
                $t->decimal('inventory_cost',18,3)->default(0);
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['company_id','return_id'], 'idx_commercial_return_line');
                $t->index(['company_id','source_invoice_line_id'], 'idx_commercial_return_source_line');
            });
        }
        if (!Schema::hasTable('commercial_return_lot_sources')) {
            Schema::create('commercial_return_lot_sources', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('return_line_id');
                $t->unsignedBigInteger('inventory_lot_id');
                $t->decimal('qty_kg',18,3);
                $t->decimal('unit_cost_per_kg',18,6);
                $t->decimal('total_cost',18,3);
                $t->timestamps();
                $t->index(['company_id','return_line_id'], 'idx_return_lot_source');
            });
        }

        // 8) Permissions required for the added actions.
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['ربط كرت الميزان بشحنة','weighbridge.link','weighbridge'],
                ['توزيع كروت الميزان على أصناف الشحنة','shipments.weighbridge.allocate','shipments'],
                ['إدارة حسابات الأصناف','items.accounting.manage','items'],
                ['تهيئة العميل والمورد الافتراضيين','settings.default_parties.manage','settings'],
                ['عرض فحص سلامة المحاسبة والمخزون','accounting.integrity.view','accounting'],
                ['حفظ مسودة مردود','returns.draft','returns'],
                ['ترحيل مردود','returns.post','returns'],
                ['عكس مردود مرحل','returns.void','returns'],
            ];
            foreach ($permissions as [$name,$code,$module]) {
                if (DB::table('permissions')->where('permission_code',$code)->exists()) {
                    DB::table('permissions')->where('permission_code',$code)->update(['permission_name'=>$name,'module_name'=>$module]);
                } else {
                    DB::table('permissions')->insert(['permission_name'=>$name,'permission_code'=>$code,'module_name'=>$module,'created_at'=>now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: financial/audit history is never dropped automatically.
    }

    private function addColumns(string $table, array $definitions): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($definitions as $name => $callback) {
            if (!Schema::hasColumn($table, $name)) Schema::table($table, $callback);
        }
    }
};
