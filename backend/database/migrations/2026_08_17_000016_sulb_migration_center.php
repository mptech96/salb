<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('data_migration_batches')) {
            Schema::create('data_migration_batches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('entity_code', 60);
                $t->string('file_name', 255)->nullable();
                $t->string('source_system', 120)->nullable();
                $t->string('import_mode', 30)->default('UPSERT');
                $t->string('posting_mode', 30)->default('DRAFT');
                $t->string('status', 30)->default('RUNNING');
                $t->unsignedInteger('total_rows')->default(0);
                $t->unsignedInteger('valid_rows')->default(0);
                $t->unsignedInteger('imported_rows')->default(0);
                $t->unsignedInteger('skipped_rows')->default(0);
                $t->unsignedInteger('failed_rows')->default(0);
                $t->longText('options_json')->nullable();
                $t->unsignedBigInteger('started_by')->nullable();
                $t->dateTime('started_at')->nullable();
                $t->dateTime('finished_at')->nullable();
                $t->timestamps();
                $t->index(['company_id','entity_code','created_at'], 'idx_data_migration_batch_lookup');
            });
        }

        if (!Schema::hasTable('data_migration_row_logs')) {
            Schema::create('data_migration_row_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('batch_id');
                $t->unsignedInteger('row_number')->nullable();
                $t->string('external_key', 255)->nullable();
                $t->string('row_status', 30); // IMPORTED | UPDATED | SKIPPED | ERROR
                $t->text('message')->nullable();
                $t->longText('payload_json')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id','batch_id','row_status'], 'idx_data_migration_row_log');
            });
        }

        foreach (['items','customers','suppliers','cars','drivers','sales_invoices','purchase_invoices'] as $table) {
            if (!Schema::hasTable($table)) continue;
            $this->addColumns($table, [
                'external_source_system' => fn (Blueprint $t) => $t->string('external_source_system',120)->nullable(),
                'external_reference' => fn (Blueprint $t) => $t->string('external_reference',255)->nullable(),
                'migration_batch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('migration_batch_id')->nullable(),
            ]);
            try {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->index(['company_id','external_source_system','external_reference'], 'idx_'.$table.'_external_ref');
                });
            } catch (\Throwable $e) {}
        }

        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['عرض مركز نقل البيانات','imports.view','imports'],
                ['تنفيذ استيراد البيانات','imports.execute','imports'],
                ['تصدير البيانات','imports.export','imports'],
            ];
            foreach ($permissions as [$name,$code,$module]) {
                if (DB::table('permissions')->where('permission_code',$code)->exists()) {
                    DB::table('permissions')->where('permission_code',$code)->update(['permission_name'=>$name,'module_name'=>$module]);
                } else {
                    DB::table('permissions')->insert([
                        'permission_name'=>$name,'permission_code'=>$code,'module_name'=>$module,'created_at'=>now()
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Migration history and external references are audit data.
    }

    private function addColumns(string $table, array $definitions): void
    {
        foreach ($definitions as $name => $callback) {
            if (!Schema::hasColumn($table, $name)) {
                Schema::table($table, $callback);
            }
        }
    }
};
