<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_maintenance', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | الشركة والفرع والأصل
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('asset_id');

            /*
            |--------------------------------------------------------------------------
            | بيانات الصيانة
            |--------------------------------------------------------------------------
            */
            $table->date('maintenance_date');
            $table->string('maintenance_type', 100)->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->string('invoice_number', 100)->nullable();

            $table->decimal('maintenance_cost', 15, 3)->default(0);

            /*
            |--------------------------------------------------------------------------
            | طبيعة المعالجة
            |--------------------------------------------------------------------------
            |
            | EXPENSE    = مصروف صيانة فوري
            | CAPITALIZE = يضاف إلى تكلفة الأصل
            |
            */
            $table->enum('cost_treatment', [
                'EXPENSE',
                'CAPITALIZE',
            ])->default('EXPENSE');

            /*
            |--------------------------------------------------------------------------
            | الحالة
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'DRAFT',
                'APPROVED',
                'PAID',
                'CANCELLED',
            ])->default('DRAFT');

            /*
            |--------------------------------------------------------------------------
            | القيود والمستندات
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->unsignedBigInteger('payment_account_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('voucher_id')->nullable();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(
                ['company_id', 'asset_id'],
                'idx_asset_maintenance_company_asset'
            );

            $table->index('maintenance_date');
            $table->index('status');
            $table->index('journal_entry_id');
            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_maintenance');
    }
};