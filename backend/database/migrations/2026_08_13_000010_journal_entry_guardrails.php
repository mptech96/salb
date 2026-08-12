<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('journal_entries', 'reference_no')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('reference_no', 100)->nullable()->after('entry_number');
                $table->index(['company_id', 'reference_no'], 'idx_journal_company_reference');
            });
        }

        if (!Schema::hasColumn('journal_entries', 'reversal_reason')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->text('reversal_reason')->nullable()->after('reversed_at');
            });
        }
    }

    public function down(): void
    {
        // لا نحذف حقول التدقيق المحاسبي تلقائيًا حفاظًا على أثر القيود.
    }
};
