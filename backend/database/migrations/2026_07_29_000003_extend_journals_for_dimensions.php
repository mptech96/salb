<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('financial_year_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('financial_year_id');
            $table->index(['company_id', 'branch_id', 'financial_year_id'], 'idx_journal_entries_dimensions');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->unsignedBigInteger('financial_year_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('financial_year_id');
            $table->index(
                ['company_id', 'branch_id', 'financial_year_id', 'cost_center_id'],
                'idx_journal_lines_dimensions'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('idx_journal_lines_dimensions');
            $table->dropColumn(['branch_id', 'financial_year_id', 'cost_center_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('idx_journal_entries_dimensions');
            $table->dropColumn(['financial_year_id', 'cost_center_id']);
        });
    }
};
