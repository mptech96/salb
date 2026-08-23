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
        |--------------------------------------------------------------------------
        | عمليات المخزون: تحويل فروع + فرز/تحويل/معالجة
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('inventory_operations')) {
            // نزيل قيد ENUM القديم حتى نستطيع إضافة أنواع معالجة بدون Migration لكل نوع.
            try {
                DB::statement("ALTER TABLE inventory_operations MODIFY operation_type VARCHAR(40) NOT NULL");
            } catch (\Throwable) {
                // إذا كانت النسخة الحالية أصلًا VARCHAR فلا نفشل.
            }

            $columns = [
                'from_branch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('from_branch_id')->nullable()->after('branch_id'),
                'to_branch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('to_branch_id')->nullable()->after('from_branch_id'),
                'allocation_method' => fn (Blueprint $t) => $t->string('allocation_method', 30)->default('RELATIVE_VALUE')->after('operation_type'),
                'input_weight_kg' => fn (Blueprint $t) => $t->decimal('input_weight_kg', 18, 3)->default(0)->after('operation_date'),
                'output_weight_kg' => fn (Blueprint $t) => $t->decimal('output_weight_kg', 18, 3)->default(0)->after('input_weight_kg'),
                'loss_qty_kg' => fn (Blueprint $t) => $t->decimal('loss_qty_kg', 18, 3)->default(0)->after('output_weight_kg'),
                'gain_qty_kg' => fn (Blueprint $t) => $t->decimal('gain_qty_kg', 18, 3)->default(0)->after('loss_qty_kg'),
                'loss_gain_reason' => fn (Blueprint $t) => $t->text('loss_gain_reason')->nullable()->after('gain_qty_kg'),
                'posted_by' => fn (Blueprint $t) => $t->unsignedBigInteger('posted_by')->nullable()->after('approved_at'),
                'posted_at' => fn (Blueprint $t) => $t->timestamp('posted_at')->nullable()->after('posted_by'),
            ];

            foreach ($columns as $name => $callback) {
                if (!Schema::hasColumn('inventory_operations', $name)) {
                    Schema::table('inventory_operations', $callback);
                }
            }
        }

        if (Schema::hasTable('inventory_operation_lines')) {
            $columns = [
                'branch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable()->after('company_id'),
                'qty_kg' => fn (Blueprint $t) => $t->decimal('qty_kg', 18, 3)->default(0)->after('qty'),
                'unit_cost_per_kg' => fn (Blueprint $t) => $t->decimal('unit_cost_per_kg', 18, 6)->default(0)->after('unit_cost'),
                'allocation_percent' => fn (Blueprint $t) => $t->decimal('allocation_percent', 9, 4)->nullable()->after('total_cost'),
                'market_value_per_kg' => fn (Blueprint $t) => $t->decimal('market_value_per_kg', 18, 6)->nullable()->after('allocation_percent'),
                'input_lot_id' => fn (Blueprint $t) => $t->unsignedBigInteger('input_lot_id')->nullable()->after('shipment_item_id'),
                'output_lot_id' => fn (Blueprint $t) => $t->unsignedBigInteger('output_lot_id')->nullable()->after('input_lot_id'),
            ];

            foreach ($columns as $name => $callback) {
                if (!Schema::hasColumn('inventory_operation_lines', $name)) {
                    Schema::table('inventory_operation_lines', $callback);
                }
            }
        }

        if (Schema::hasTable('inventory_lots')) {
            $columns = [
                'parent_lot_id' => fn (Blueprint $t) => $t->unsignedBigInteger('parent_lot_id')->nullable()->after('id'),
                'origin_lot_id' => fn (Blueprint $t) => $t->unsignedBigInteger('origin_lot_id')->nullable()->after('parent_lot_id'),
                'inventory_operation_id' => fn (Blueprint $t) => $t->unsignedBigInteger('inventory_operation_id')->nullable()->after('source_id'),
            ];

            foreach ($columns as $name => $callback) {
                if (!Schema::hasColumn('inventory_lots', $name)) {
                    Schema::table('inventory_lots', $callback);
                }
            }
        }

        if (!Schema::hasTable('inventory_operation_lot_links')) {
            Schema::create('inventory_operation_lot_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('operation_id');
                $t->unsignedBigInteger('operation_line_id')->nullable();
                $t->string('direction', 10); // FROM / TO
                $t->unsignedBigInteger('source_lot_id')->nullable();
                $t->unsignedBigInteger('produced_lot_id')->nullable();
                $t->unsignedBigInteger('item_id');
                $t->unsignedBigInteger('branch_id');
                $t->decimal('qty_kg', 18, 3);
                $t->decimal('unit_cost_per_kg', 18, 6)->default(0);
                $t->decimal('total_cost', 18, 3)->default(0);
                $t->timestamps();
                $t->index(['company_id', 'operation_id'], 'idx_invop_link_operation');
                $t->index(['company_id', 'source_lot_id'], 'idx_invop_link_source_lot');
                $t->index(['company_id', 'produced_lot_id'], 'idx_invop_link_produced_lot');
            });
        }


        /*
        |--------------------------------------------------------------------------
        | ترقية العمليات القديمة بدون تغيير أثرها التاريخي
        |--------------------------------------------------------------------------
        */
        if (Schema::hasTable('inventory_operations')) {
            DB::table('inventory_operations')
                ->whereNull('from_branch_id')
                ->update(['from_branch_id' => DB::raw('branch_id')]);

            DB::table('inventory_operations')
                ->whereNull('to_branch_id')
                ->where('operation_type', '!=', 'TRANSFER')
                ->update(['to_branch_id' => DB::raw('branch_id')]);
        }

        if (Schema::hasTable('inventory_operation_lines')) {
            DB::statement("
                UPDATE inventory_operation_lines l
                INNER JOIN inventory_operations o ON o.id = l.operation_id
                SET l.branch_id = COALESCE(l.branch_id, o.branch_id)
                WHERE l.branch_id IS NULL
            ");

            DB::statement("
                UPDATE inventory_operation_lines
                SET qty_kg = ROUND(qty * 1000, 3)
                WHERE COALESCE(qty_kg,0) = 0 AND COALESCE(qty,0) <> 0
            ");

            DB::statement("
                UPDATE inventory_operation_lines
                SET unit_cost_per_kg = ROUND(unit_cost / 1000, 6)
                WHERE COALESCE(unit_cost_per_kg,0) = 0 AND COALESCE(unit_cost,0) <> 0
            ");
        }

        if (Schema::hasTable('inventory_operations')
            && Schema::hasTable('inventory_operation_lines')) {
            DB::statement("
                UPDATE inventory_operations o
                LEFT JOIN (
                    SELECT operation_id,
                           SUM(CASE WHEN line_type='FROM' THEN qty_kg ELSE 0 END) AS in_kg,
                           SUM(CASE WHEN line_type='TO' THEN qty_kg ELSE 0 END) AS out_kg
                    FROM inventory_operation_lines
                    GROUP BY operation_id
                ) x ON x.operation_id = o.id
                SET o.input_weight_kg = COALESCE(x.in_kg,0),
                    o.output_weight_kg = COALESCE(x.out_kg,0),
                    o.loss_qty_kg = GREATEST(COALESCE(x.in_kg,0)-COALESCE(x.out_kg,0),0),
                    o.gain_qty_kg = GREATEST(COALESCE(x.out_kg,0)-COALESCE(x.in_kg,0),0)
            ");
        }

        /*
        |--------------------------------------------------------------------------
        | سجل الاستيراد
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('data_import_batches')) {
            Schema::create('data_import_batches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('entity_type', 50);
                $t->string('original_filename', 255)->nullable();
                $t->string('status', 30)->default('COMPLETED');
                $t->unsignedInteger('total_rows')->default(0);
                $t->unsignedInteger('inserted_rows')->default(0);
                $t->unsignedInteger('updated_rows')->default(0);
                $t->unsignedInteger('skipped_rows')->default(0);
                $t->unsignedInteger('failed_rows')->default(0);
                $t->longText('result_json')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'entity_type', 'created_at'], 'idx_import_batch_entity');
            });
        }

        // صلاحيات الاستيراد للمستخدمين العاديين عند الحاجة. المدير ومدير الفرع لهما bypass حسب RBAC الحالي.
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_name' => 'عرض مركز الاستيراد', 'permission_code' => 'imports.view', 'module_name' => 'imports'],
                ['permission_name' => 'تنفيذ استيراد البيانات', 'permission_code' => 'imports.execute', 'module_name' => 'imports'],
                ['permission_name' => 'تنفيذ عمليات الفرز والتحويل', 'permission_code' => 'inventory.process', 'module_name' => 'inventory'],
            ];

            foreach ($permissions as $p) {
                DB::table('permissions')->updateOrInsert(
                    ['permission_code' => $p['permission_code']],
                    [...$p, 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // متعمد: لا نحذف أثر عمليات المخزون أو سجل الاستيراد تلقائيًا.
    }
};
