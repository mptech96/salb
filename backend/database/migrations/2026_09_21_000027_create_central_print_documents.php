<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('company_settings', 'watermark_path')) {
            Schema::table('company_settings', fn (Blueprint $table) => $table->string('watermark_path', 500)->nullable());
        }
        if (!Schema::hasColumn('official_documents', 'print_metadata')) {
            Schema::table('official_documents', fn (Blueprint $table) => $table->json('print_metadata')->nullable());
        }
        Schema::create('road_waybills', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('document_number', 80)->nullable();
                $table->date('document_date');
                $table->date('valid_until')->nullable();
                $table->string('status', 20)->default('DRAFT');
                $table->unsignedBigInteger('driver_id')->nullable();
                $table->unsignedBigInteger('car_id')->nullable();
                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->string('driver_name', 200);
                $table->string('nationality', 100)->nullable();
                $table->string('identity_number', 100)->nullable();
                $table->string('vehicle_type', 100)->nullable();
                $table->string('plate_number', 100)->nullable();
                $table->string('material_owner', 200);
                $table->string('origin_city', 150);
                $table->string('destination_city', 150);
                $table->string('phone', 80)->nullable();
                $table->text('notes')->nullable();
                $table->json('lines_json');
                $table->json('visibility_json')->nullable();
                $table->string('template_key', 80)->default('ROAD_BOXES');
                $table->timestamps();
                $table->index(['company_id', 'branch_id', 'id'], 'idx_waybill_tenant_branch');
                $table->unique(['company_id', 'document_number'], 'uq_waybill_company_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('road_waybills');
        // Existing environments may already own these nullable fields; keep user data on rollback.
    }
};
