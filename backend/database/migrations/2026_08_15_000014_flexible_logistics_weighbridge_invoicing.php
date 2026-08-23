<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('cars', [
            'owner_type' => fn(Blueprint $t) => $t->string('owner_type',30)->default('OTHER'),
            'owner_id' => fn(Blueprint $t) => $t->unsignedBigInteger('owner_id')->nullable(),
        ]);

        $this->addColumns('drivers', [
            'affiliation_type' => fn(Blueprint $t) => $t->string('affiliation_type',30)->default('INDEPENDENT'),
            'affiliation_id' => fn(Blueprint $t) => $t->unsignedBigInteger('affiliation_id')->nullable(),
            'affiliation_name' => fn(Blueprint $t) => $t->string('affiliation_name',255)->nullable(),
            'license_number' => fn(Blueprint $t) => $t->string('license_number',120)->nullable(),
        ]);

        $this->addColumns('weighbridge_cards', [
            'sales_invoice_id' => fn(Blueprint $t) => $t->unsignedBigInteger('sales_invoice_id')->nullable(),
            'movement_direction' => fn(Blueprint $t) => $t->string('movement_direction',20)->default('IN'),
            'party_type' => fn(Blueprint $t) => $t->string('party_type',30)->nullable(),
            'party_id' => fn(Blueprint $t) => $t->unsignedBigInteger('party_id')->nullable(),
            'driver_id' => fn(Blueprint $t) => $t->unsignedBigInteger('driver_id')->nullable(),
            'party_name_snapshot' => fn(Blueprint $t) => $t->string('party_name_snapshot',255)->nullable(),
            'driver_name_snapshot' => fn(Blueprint $t) => $t->string('driver_name_snapshot',255)->nullable(),
            'plate_number_snapshot' => fn(Blueprint $t) => $t->string('plate_number_snapshot',120)->nullable(),
            'weight_tolerance_kg' => fn(Blueprint $t) => $t->decimal('weight_tolerance_kg',18,3)->default(5),
        ]);

        $this->addColumns('shipment_weights', [
            'sales_invoice_id' => fn(Blueprint $t) => $t->unsignedBigInteger('sales_invoice_id')->nullable(),
        ]);

        if (!Schema::hasTable('weighbridge_card_items')) {
            Schema::create('weighbridge_card_items', function(Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('weighbridge_card_id');
                $t->unsignedBigInteger('item_id');
                $t->decimal('weighed_qty_kg',18,3)->default(0);
                $t->decimal('deduction_qty_kg',18,3)->default(0);
                $t->decimal('accepted_qty_kg',18,3)->default(0);
                $t->string('deduction_reason',255)->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['company_id','weighbridge_card_id'],'idx_wb_items_card');
            });
        }

        $this->addColumns('shipment_items', [
            'weighed_qty_kg' => fn(Blueprint $t) => $t->decimal('weighed_qty_kg',18,3)->default(0),
            'item_deduction_qty_kg' => fn(Blueprint $t) => $t->decimal('item_deduction_qty_kg',18,3)->default(0),
            'deduction_reason' => fn(Blueprint $t) => $t->string('deduction_reason',255)->nullable(),
            'invoiced_qty_kg' => fn(Blueprint $t) => $t->decimal('invoiced_qty_kg',18,3)->default(0),
        ]);

        $this->addColumns('shipments', [
            'invoice_status' => fn(Blueprint $t) => $t->string('invoice_status',30)->default('UNINVOICED'),
            'ready_for_invoice_at' => fn(Blueprint $t) => $t->timestamp('ready_for_invoice_at')->nullable(),
        ]);

        if (!Schema::hasTable('purchase_invoice_shipments')) {
            Schema::create('purchase_invoice_shipments', function(Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('purchase_invoice_id');
                $t->unsignedBigInteger('shipment_id');
                $t->decimal('allocated_qty_kg',18,3)->default(0);
                $t->decimal('supplier_amount_base',18,3)->default(0);
                $t->decimal('capitalized_cost_base',18,3)->default(0);
                $t->timestamps();
                $t->unique(['purchase_invoice_id','shipment_id'],'uq_purchase_invoice_shipment');
                $t->index(['company_id','shipment_id'],'idx_purchase_shipment_source');
            });
        }

        $this->addColumns('purchase_invoices', [
            'source_mode' => fn(Blueprint $t) => $t->string('source_mode',30)->default('MANUAL'),
            'source_shipment_count' => fn(Blueprint $t) => $t->unsignedInteger('source_shipment_count')->default(0),
        ]);

        $this->addColumns('sales_invoices', [
            'weighbridge_card_id' => fn(Blueprint $t) => $t->unsignedBigInteger('weighbridge_card_id')->nullable(),
            'total_loaded_weight_kg' => fn(Blueprint $t) => $t->decimal('total_loaded_weight_kg',18,3)->default(0),
            'total_empty_weight_kg' => fn(Blueprint $t) => $t->decimal('total_empty_weight_kg',18,3)->default(0),
            'total_deduction_weight_kg' => fn(Blueprint $t) => $t->decimal('total_deduction_weight_kg',18,3)->default(0),
            'total_net_weight_kg' => fn(Blueprint $t) => $t->decimal('total_net_weight_kg',18,3)->default(0),
            'weight_variance_kg' => fn(Blueprint $t) => $t->decimal('weight_variance_kg',18,3)->default(0),
            'weight_status' => fn(Blueprint $t) => $t->string('weight_status',30)->default('NOT_WEIGHED'),
        ]);

        $this->addColumns('shipment_costs', [
            'beneficiary_type' => fn(Blueprint $t) => $t->string('beneficiary_type',30)->nullable(),
            'beneficiary_id' => fn(Blueprint $t) => $t->unsignedBigInteger('beneficiary_id')->nullable(),
            'beneficiary_name' => fn(Blueprint $t) => $t->string('beneficiary_name',255)->nullable(),
            'allocation_method' => fn(Blueprint $t) => $t->string('allocation_method',30)->nullable(),
        ]);

        $this->addColumns('company_settings', [
            'weighbridge_tolerance_kg' => fn(Blueprint $t) => $t->decimal('weighbridge_tolerance_kg',18,3)->default(5),
        ]);

        // Backfill without changing historical accounting.
        if (Schema::hasTable('cars') && Schema::hasColumn('cars','owner_type')) {
            DB::table('cars')->whereNotNull('supplier_id')->update(['owner_type'=>'SUPPLIER']);
            DB::table('cars')->where('owner_type','SUPPLIER')->whereNull('owner_id')
                ->update(['owner_id'=>DB::raw('supplier_id')]);
        }

        if (Schema::hasTable('shipment_items') && Schema::hasColumn('shipment_items','weighed_qty_kg')) {
            DB::table('shipment_items')->where('weighed_qty_kg',0)->update([
                'weighed_qty_kg'=>DB::raw('qty_kg')
            ]);
        }

        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments','invoice_status')) {
            DB::table('shipments')->whereNotNull('purchase_invoice_id')->update(['invoice_status'=>'INVOICED']);
        }

        // Indexes are additive; ignore if a DB already has them.
        $this->safeIndex('weighbridge_cards', function(Blueprint $t) {
            $t->index(['company_id','sales_invoice_id'],'idx_wb_sales_invoice');
        });
        $this->safeIndex('weighbridge_cards', function(Blueprint $t) {
            $t->unique(['company_id','sales_invoice_id'],'uq_weighbridge_sales_invoice');
        });
        $this->safeIndex('shipment_weights', function(Blueprint $t) {
            $t->index(['company_id','sales_invoice_id','event_type'],'idx_weight_sales_type');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: operational and audit history is preserved.
    }

    private function addColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($columns as $name=>$definition) {
            if (Schema::hasColumn($table,$name)) continue;
            Schema::table($table, function(Blueprint $t) use ($definition) { $definition($t); });
        }
    }

    private function safeIndex(string $table, callable $callback): void
    {
        if (!Schema::hasTable($table)) return;
        try { Schema::table($table,$callback); } catch (\Throwable $e) {}
    }
};
