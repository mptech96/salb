<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('cost_center_code', 50);
            $table->string('cost_center_name', 255);
            $table->boolean('is_group')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'cost_center_code'], 'uk_cost_centers_company_code');
            $table->index(['company_id', 'branch_id'], 'idx_cost_centers_company_branch');
            $table->index('parent_id', 'idx_cost_centers_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
