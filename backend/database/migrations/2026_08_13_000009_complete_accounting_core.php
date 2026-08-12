<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('journal_entries', 'reversal_of_id')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->unsignedBigInteger('reversal_of_id')->nullable()->after('source_id'));
        }
        if (!Schema::hasColumn('journal_entries', 'reversed_by_id')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->unsignedBigInteger('reversed_by_id')->nullable()->after('reversal_of_id'));
        }
        if (!Schema::hasColumn('journal_entries', 'reversed_at')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->timestamp('reversed_at')->nullable()->after('reversed_by_id'));
        }
        if (!Schema::hasColumn('journal_entries', 'is_closing_entry')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->boolean('is_closing_entry')->default(false)->after('status'));
        }
        if (!Schema::hasColumn('journal_entries', 'is_system_generated')) {
            Schema::table('journal_entries', fn (Blueprint $t) => $t->boolean('is_system_generated')->default(false)->after('is_closing_entry'));
        }
        if (!Schema::hasColumn('journal_entry_lines', 'party_type')) {
            Schema::table('journal_entry_lines', fn (Blueprint $t) => $t->string('party_type', 30)->nullable()->after('account_id'));
        }
        if (!Schema::hasColumn('journal_entry_lines', 'party_id')) {
            Schema::table('journal_entry_lines', fn (Blueprint $t) => $t->unsignedBigInteger('party_id')->nullable()->after('party_type'));
        }
        if (!Schema::hasColumn('sales_invoices', 'journal_entry_id')) {
            Schema::table('sales_invoices', fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable()->after('total_amount'));
        }
        if (!Schema::hasColumn('vouchers', 'journal_entry_id')) {
            Schema::table('vouchers', fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable()->after('amount'));
        }
        if (!Schema::hasColumn('vouchers', 'cash_account_id')) {
            Schema::table('vouchers', fn (Blueprint $t) => $t->unsignedBigInteger('cash_account_id')->nullable()->after('journal_entry_id'));
        }
        if (!Schema::hasColumn('stock_movements', 'journal_entry_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable()->after('source_id'));
        }

        if (!Schema::hasTable('financial_year_closures')) {
            Schema::create('financial_year_closures', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('financial_year_id');
                $t->string('closure_number', 100);
                $t->date('close_date');
                $t->decimal('revenue_total', 18, 3)->default(0);
                $t->decimal('expense_total', 18, 3)->default(0);
                $t->decimal('net_result', 18, 3)->default(0);
                $t->unsignedBigInteger('profit_loss_entry_id')->nullable();
                $t->unsignedBigInteger('retained_earnings_entry_id')->nullable();
                $t->unsignedBigInteger('next_financial_year_id')->nullable();
                $t->string('status', 30)->default('CLOSED');
                $t->unsignedBigInteger('closed_by')->nullable();
                $t->unsignedBigInteger('reopened_by')->nullable();
                $t->timestamp('reopened_at')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->unique(['company_id','closure_number'], 'uq_fy_closure_number');
                $t->index(['company_id','financial_year_id','status'], 'idx_fy_closure_status');
            });
        }

        // استكمال الإعدادات المحاسبية للشركات الموجودة مسبقًا بدون تغيير شجرة الحسابات.
        $settingMap = [
            'ACCRUED_EXPENSE_ACCOUNT' => '2400',
            'DRIVER_ADVANCE_ACCOUNT' => '1520',
            'INVENTORY_ADJUSTMENT_ACCOUNT' => '5500',
        ];

        foreach (\Illuminate\Support\Facades\DB::table('companies')->pluck('id') as $companyId) {
            foreach ($settingMap as $key => $code) {
                $accountId = \Illuminate\Support\Facades\DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('account_code', $code)
                    ->value('id');

                if ($accountId) {
                    \Illuminate\Support\Facades\DB::table('accounting_settings')->updateOrInsert(
                        ['company_id' => $companyId, 'setting_key' => $key],
                        ['account_id' => $accountId, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }

    }

    public function down(): void
    {
        // لا نحذف حقولًا أو سجلات محاسبية تلقائيًا حفاظًا على سلامة البيانات.
    }
};
