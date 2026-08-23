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
        | SULB ERP Stage 6 — Scrap Enterprise Core
        |------------------------------------------------------------------
        | Non-destructive migration. It extends the existing Stage5 model;
        | historical rows are preserved and backfilled conservatively.
        */

        // 1) Professional item master: groups / hierarchy / stock vs service.
        if (!Schema::hasTable('item_groups')) {
            Schema::create('item_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('group_code', 60)->nullable();
                $t->string('group_name', 180);
                $t->text('notes')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['company_id','group_code'], 'uq_item_group_code');
                $t->index(['company_id','is_active'], 'idx_item_group_active');
            });
        }

        $this->addColumns('item_categories', [
            'company_id' => fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable(),
            'group_id' => fn (Blueprint $t) => $t->unsignedBigInteger('group_id')->nullable(),
            'parent_id' => fn (Blueprint $t) => $t->unsignedBigInteger('parent_id')->nullable(),
            'category_code' => fn (Blueprint $t) => $t->string('category_code', 60)->nullable(),
            'is_active' => fn (Blueprint $t) => $t->boolean('is_active')->default(true),
        ]);

        $this->addColumns('items', [
            'group_id' => fn (Blueprint $t) => $t->unsignedBigInteger('group_id')->nullable(),
            'item_type' => fn (Blueprint $t) => $t->string('item_type', 20)->default('STOCK'),
            'track_inventory' => fn (Blueprint $t) => $t->boolean('track_inventory')->default(true),
            'allow_negative_stock' => fn (Blueprint $t) => $t->boolean('allow_negative_stock')->default(false),
            'can_purchase' => fn (Blueprint $t) => $t->boolean('can_purchase')->default(true),
            'can_sell' => fn (Blueprint $t) => $t->boolean('can_sell')->default(true),
            'base_unit_code' => fn (Blueprint $t) => $t->string('base_unit_code', 20)->default('KG'),
            'commercial_unit_code' => fn (Blueprint $t) => $t->string('commercial_unit_code', 20)->default('TON'),
            'commercial_to_base_factor' => fn (Blueprint $t) => $t->decimal('commercial_to_base_factor', 18, 6)->default(1000),
            'costing_method' => fn (Blueprint $t) => $t->string('costing_method', 20)->default('FIFO'),
            'is_waste_item' => fn (Blueprint $t) => $t->boolean('is_waste_item')->default(false),
            'is_byproduct' => fn (Blueprint $t) => $t->boolean('is_byproduct')->default(false),
        ]);

        if (Schema::hasTable('items')) {
            DB::table('items')->whereNull('item_type')->update(['item_type'=>'STOCK']);
            DB::table('items')->whereNull('track_inventory')->update(['track_inventory'=>1]);
            DB::table('items')->whereNull('can_purchase')->update(['can_purchase'=>1]);
            DB::table('items')->whereNull('can_sell')->update(['can_sell'=>1]);
        }

        // 2) Cars and drivers are independent masters with optional affiliation.
        $this->addColumns('cars', [
            'ownership_type' => fn (Blueprint $t) => $t->string('ownership_type', 30)->default('OTHER'),
            'owner_party_type' => fn (Blueprint $t) => $t->string('owner_party_type', 30)->nullable(),
            'owner_party_id' => fn (Blueprint $t) => $t->unsignedBigInteger('owner_party_id')->nullable(),
            'make_name' => fn (Blueprint $t) => $t->string('make_name', 120)->nullable(),
            'model_name' => fn (Blueprint $t) => $t->string('model_name', 120)->nullable(),
            'model_year' => fn (Blueprint $t) => $t->unsignedSmallInteger('model_year')->nullable(),
        ]);
        $this->addColumns('drivers', [
            'affiliation_type' => fn (Blueprint $t) => $t->string('affiliation_type', 30)->default('INDEPENDENT'),
            'affiliation_id' => fn (Blueprint $t) => $t->unsignedBigInteger('affiliation_id')->nullable(),
            'license_number' => fn (Blueprint $t) => $t->string('license_number', 100)->nullable(),
        ]);

        // Shipment header must stay flexible: a draft may exist before choosing a default vehicle/party.
        if (Schema::hasTable('shipments') && DB::getDriverName() === 'mysql') {
            try { DB::statement('ALTER TABLE `shipments` MODIFY `supplier_id` BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}
            try { DB::statement('ALTER TABLE `shipments` MODIFY `car_id` BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}
        }

        // 3) Weighbridge: many cards per shipment + exact entry/exit + snapshots.
        if (Schema::hasTable('weighbridge_cards')) {
            try {
                Schema::table('weighbridge_cards', function (Blueprint $t) {
                    $t->dropUnique('uq_weighbridge_shipment');
                });
            } catch (\Throwable $e) {
                // Index may already have been removed by a local hotfix.
            }
            try {
                Schema::table('weighbridge_cards', function (Blueprint $t) {
                    $t->index(['company_id','shipment_id','status'], 'idx_weighbridge_shipment_cards');
                });
            } catch (\Throwable $e) {}
        }
        $this->addColumns('weighbridge_cards', [
            'driver_id' => fn (Blueprint $t) => $t->unsignedBigInteger('driver_id')->nullable(),
            'party_type' => fn (Blueprint $t) => $t->string('party_type', 20)->nullable(),
            'party_id' => fn (Blueprint $t) => $t->unsignedBigInteger('party_id')->nullable(),
            'direction' => fn (Blueprint $t) => $t->string('direction', 20)->default('INBOUND'),
            'entry_at' => fn (Blueprint $t) => $t->dateTime('entry_at')->nullable(),
            'exit_at' => fn (Blueprint $t) => $t->dateTime('exit_at')->nullable(),
            'duration_minutes' => fn (Blueprint $t) => $t->unsignedInteger('duration_minutes')->nullable(),
            'plate_snapshot' => fn (Blueprint $t) => $t->string('plate_snapshot', 120)->nullable(),
            'driver_snapshot' => fn (Blueprint $t) => $t->string('driver_snapshot', 255)->nullable(),
            'party_snapshot' => fn (Blueprint $t) => $t->string('party_snapshot', 255)->nullable(),
        ]);

        // 4) Shipment = operational work order. It does not post inventory/accounting.
        $this->addColumns('shipments', [
            'shipment_type' => fn (Blueprint $t) => $t->string('shipment_type', 20)->default('PURCHASE'),
            'customer_id' => fn (Blueprint $t) => $t->unsignedBigInteger('customer_id')->nullable(),
            'commercial_status' => fn (Blueprint $t) => $t->string('commercial_status', 30)->default('DRAFT'),
            'physical_net_weight_kg' => fn (Blueprint $t) => $t->decimal('physical_net_weight_kg', 18, 3)->default(0),
            'accepted_weight_kg' => fn (Blueprint $t) => $t->decimal('accepted_weight_kg', 18, 3)->default(0),
            'item_deduction_weight_kg' => fn (Blueprint $t) => $t->decimal('item_deduction_weight_kg', 18, 3)->default(0),
            'weight_variance_kg' => fn (Blueprint $t) => $t->decimal('weight_variance_kg', 18, 3)->default(0),
            'ready_at' => fn (Blueprint $t) => $t->dateTime('ready_at')->nullable(),
            'ready_by' => fn (Blueprint $t) => $t->unsignedBigInteger('ready_by')->nullable(),
            'invoiced_at' => fn (Blueprint $t) => $t->dateTime('invoiced_at')->nullable(),
            'invoiced_by' => fn (Blueprint $t) => $t->unsignedBigInteger('invoiced_by')->nullable(),
        ]);

        $this->addColumns('shipment_items', [
            'gross_qty_kg' => fn (Blueprint $t) => $t->decimal('gross_qty_kg', 18, 3)->default(0),
            'deduction_qty_kg' => fn (Blueprint $t) => $t->decimal('deduction_qty_kg', 18, 3)->default(0),
            'accepted_qty_kg' => fn (Blueprint $t) => $t->decimal('accepted_qty_kg', 18, 3)->default(0),
            'inventory_qty_kg' => fn (Blueprint $t) => $t->decimal('inventory_qty_kg', 18, 3)->default(0),
            'deduction_reason' => fn (Blueprint $t) => $t->string('deduction_reason', 500)->nullable(),
            'price_unit' => fn (Blueprint $t) => $t->string('price_unit', 20)->default('KG'),
            'unit_price_per_kg' => fn (Blueprint $t) => $t->decimal('unit_price_per_kg', 18, 6)->default(0),
        ]);

        // Preserve Stage5 item allocations as accepted quantities when possible.
        if (Schema::hasTable('shipment_items')) {
            DB::table('shipment_items')->where('accepted_qty_kg', 0)->where('qty_kg','>',0)->update([
                'gross_qty_kg'=>DB::raw('qty_kg'),
                'accepted_qty_kg'=>DB::raw('qty_kg'),
                'inventory_qty_kg'=>DB::raw('qty_kg'),
            ]);
        }

        // 5) Draft operational shipment costs. They are posted/capitalized only with posted purchase invoice.
        $this->addColumns('shipment_costs', [
            'cost_status' => fn (Blueprint $t) => $t->string('cost_status', 20)->default('DRAFT'),
            'capitalizable' => fn (Blueprint $t) => $t->boolean('capitalizable')->default(true),
            'payee_type' => fn (Blueprint $t) => $t->string('payee_type', 30)->nullable(),
            'payee_id' => fn (Blueprint $t) => $t->unsignedBigInteger('payee_id')->nullable(),
            'cost_code' => fn (Blueprint $t) => $t->string('cost_code', 60)->nullable(),
            'posted_at' => fn (Blueprint $t) => $t->dateTime('posted_at')->nullable(),
            'posted_by' => fn (Blueprint $t) => $t->unsignedBigInteger('posted_by')->nullable(),
        ]);
        if (Schema::hasTable('shipment_costs')) {
            DB::table('shipment_costs')->whereNotNull('journal_entry_id')->update(['cost_status'=>'POSTED']);
        }

        // 6) Many shipments can feed one invoice; relation stays traceable to every line.
        if (!Schema::hasTable('invoice_shipment_links')) {
            Schema::create('invoice_shipment_links', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id');
                $t->string('invoice_type', 20); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('shipment_id');
                $t->decimal('allocated_qty_kg',18,3)->default(0); $t->decimal('allocated_amount',18,3)->default(0);
                $t->timestamps();
                $t->unique(['company_id','invoice_type','invoice_id','shipment_id'], 'uq_invoice_shipment');
                $t->index(['company_id','shipment_id','invoice_type'], 'idx_shipment_invoice_lookup');
            });
        }

        foreach (['purchase_invoices','sales_invoices'] as $table) {
            $this->addColumns($table, [
                'document_status' => fn (Blueprint $t) => $t->string('document_status', 20)->default('DRAFT'),
                'posted_at' => fn (Blueprint $t) => $t->dateTime('posted_at')->nullable(),
                'posted_by' => fn (Blueprint $t) => $t->unsignedBigInteger('posted_by')->nullable(),
                'voided_at' => fn (Blueprint $t) => $t->dateTime('voided_at')->nullable(),
                'voided_by' => fn (Blueprint $t) => $t->unsignedBigInteger('voided_by')->nullable(),
                'void_reason' => fn (Blueprint $t) => $t->text('void_reason')->nullable(),
            ]);
            if (Schema::hasColumn($table,'journal_entry_id')) {
                DB::table($table)->whereNotNull('journal_entry_id')->update(['document_status'=>'POSTED']);
            }
        }
        foreach (['purchase_invoice_lines','sales_invoice_lines'] as $table) {
            $this->addColumns($table, [
                'qty_kg' => fn (Blueprint $t) => $t->decimal('qty_kg',18,3)->default(0),
                'item_type_snapshot' => fn (Blueprint $t) => $t->string('item_type_snapshot',20)->default('STOCK'),
                'track_inventory_snapshot' => fn (Blueprint $t) => $t->boolean('track_inventory_snapshot')->default(true),
                'shipment_id' => fn (Blueprint $t) => $t->unsignedBigInteger('shipment_id')->nullable(),
                'shipment_item_id' => fn (Blueprint $t) => $t->unsignedBigInteger('shipment_item_id')->nullable(),
                'price_unit' => fn (Blueprint $t) => $t->string('price_unit',20)->default('KG'),
                'unit_price_per_kg' => fn (Blueprint $t) => $t->decimal('unit_price_per_kg',18,6)->default(0),
            ]);
        }

        // 7) Processing overhead for stripping/sorting/conversion etc.
        if (!Schema::hasTable('inventory_operation_costs')) {
            Schema::create('inventory_operation_costs', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('operation_id');
                $t->string('cost_type',60); $t->decimal('amount',18,3); $t->string('currency_code',10)->nullable(); $t->decimal('exchange_rate',24,10)->default(1);
                $t->decimal('base_amount',18,3); $t->string('payment_status',20)->default('UNPAID'); $t->unsignedBigInteger('financial_account_id')->nullable();
                $t->text('notes')->nullable(); $t->unsignedBigInteger('journal_entry_id')->nullable(); $t->dateTime('posted_at')->nullable(); $t->unsignedBigInteger('posted_by')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
                $t->index(['company_id','operation_id'], 'idx_inventory_operation_cost');
            });
        }

        // 8) Action-level user overrides. Role remains the baseline.
        if (!Schema::hasTable('user_permission_overrides')) {
            Schema::create('user_permission_overrides', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('permission_id');
                $t->string('effect',10)->default('ALLOW'); $t->unsignedBigInteger('granted_by')->nullable(); $t->timestamps();
                $t->unique(['company_id','user_id','permission_id'], 'uq_user_permission_override');
                $t->index(['company_id','user_id','effect'], 'idx_user_permission_effect');
            });
        }

        $this->addColumns('company_settings', [
            'weighbridge_tolerance_kg' => fn (Blueprint $t) => $t->decimal('weighbridge_tolerance_kg',18,3)->default(5),
            'shipment_item_tolerance_kg' => fn (Blueprint $t) => $t->decimal('shipment_item_tolerance_kg',18,3)->default(5),
        ]);

        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['عرض محطة الميزان','weighbridge.view','weighbridge'],
                ['فتح كرت ميزان','weighbridge.open','weighbridge'],
                ['تسجيل وإعادة وتصحيح الوزن','weighbridge.record','weighbridge'],
                ['إغلاق كرت الميزان','weighbridge.close','weighbridge'],
                ['طباعة كرت الميزان','weighbridge.print','weighbridge'],
                ['إدارة بيانات السائقين','drivers.manage','drivers'],
                ['تجهيز وتسعير الشحنات','shipments.prepare','shipments'],
                ['إدارة تكاليف الشحنات','shipments.cost','shipments'],
                ['جعل الشحنة جاهزة للفوترة','shipments.ready','shipments'],
                ['إعادة فتح شحنة قبل الفوترة','shipments.reopen','shipments'],
                ['حفظ مسودة مشتريات','purchases.draft','purchases'],
                ['ترحيل فاتورة مشتريات','purchases.post','purchases'],
                ['إلغاء/عكس فاتورة مشتريات مرحلة','purchases.void','purchases'],
                ['حفظ مسودة مبيعات','sales.draft','sales'],
                ['ترحيل فاتورة مبيعات','sales.post','sales'],
                ['إلغاء/عكس فاتورة مبيعات مرحلة','sales.void','sales'],
                ['إدارة عمليات المخزون','inventory.process','inventory'],
                ['ترحيل عمليات المخزون','inventory.process.post','inventory'],
                ['إدارة صلاحيات المستخدم التفصيلية','users.permissions.manage','users'],
                ['عرض التقرير الضريبي','tax_reports.view','accounting'],
            ];
            foreach ($permissions as [$name,$code,$module]) {
                $exists = DB::table('permissions')->where('permission_code',$code)->exists();
                if ($exists) {
                    DB::table('permissions')->where('permission_code',$code)->update(['permission_name'=>$name,'module_name'=>$module]);
                } else {
                    DB::table('permissions')->insert(['permission_name'=>$name,'permission_code'=>$code,'module_name'=>$module,'created_at'=>now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. ERP/audit/financial history must never be removed automatically.
    }

    private function addColumns(string $table, array $definitions): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($definitions as $name => $callback) {
            if (!Schema::hasColumn($table, $name)) {
                Schema::table($table, $callback);
            }
        }
    }
};
