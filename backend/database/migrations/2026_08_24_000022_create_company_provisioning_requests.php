<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('company_provisioning_requests')) {
            throw new RuntimeException('company_provisioning_requests already exists; reconcile it before adopting this migration.');
        }
        Schema::create('company_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->char('request_hash', 64);
            $table->string('channel', 30);
            $table->string('status', 20);
            $table->bigInteger('company_id')->nullable()->index();
            $table->json('result_json')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'idx_provisioning_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_provisioning_requests');
    }
};
