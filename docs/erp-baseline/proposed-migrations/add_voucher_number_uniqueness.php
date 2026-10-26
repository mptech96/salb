<?php
// Isolated review copy, NOT an operational migration.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if(DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vouchers' AND INDEX_NAME='uq_vouchers_company_number'")->n)throw new RuntimeException('Existing number constraint requires review.');
        // Uses the ACTUAL column collation, including case/trailing-space semantics.
        if(DB::table('vouchers')->whereNotNull('company_id')->whereNotNull('voucher_number')->select('company_id','voucher_number')->groupBy('company_id','voucher_number')->havingRaw('COUNT(*) > 1')->exists())throw new RuntimeException('Historical duplicate voucher numbers: stop, do not rename or delete.');
        DB::unprepared(file_get_contents(__DIR__.'/add_voucher_number_uniqueness.sql'));
    }
    public function down(): void
    {
        \App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if(DB::table('vouchers')->exists())throw new RuntimeException('Keep the number constraint after financial data exists.');
        DB::statement('ALTER TABLE vouchers DROP INDEX uq_vouchers_company_number');
    }
};
