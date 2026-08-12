<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | الشركة والفرع
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | تعريف الأصل
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('category_id');

            $table->string('asset_code',100);
            $table->string('asset_name',255);

            $table->text('description')->nullable();

            $table->string('serial_number',150)->nullable();
            $table->string('barcode',150)->nullable();

            /*
            |--------------------------------------------------------------------------
            | الموقع والمسؤول
            |--------------------------------------------------------------------------
            */

            $table->string('location',255)->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();

            $table->unsignedBigInteger('responsible_worker_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | الشراء
            |--------------------------------------------------------------------------
            */

            $table->date('purchase_date')->nullable();

            $table->decimal('purchase_cost',15,3)->default(0);

            $table->decimal('salvage_value',15,3)->default(0);

            $table->decimal('current_book_value',15,3)->default(0);

            /*
            |--------------------------------------------------------------------------
            | الإهلاك
            |--------------------------------------------------------------------------
            */

            $table->enum('depreciation_method',[
                'STRAIGHT_LINE',
                'DECLINING_BALANCE',
                'NO_DEPRECIATION'
            ])->default('STRAIGHT_LINE');

            $table->integer('useful_life_months')->nullable();

            $table->decimal('annual_depreciation_rate',8,4)->nullable();

            $table->decimal('accumulated_depreciation',15,3)->default(0);

            $table->date('depreciation_start_date')->nullable();

            $table->date('last_depreciation_date')->nullable();

            /*
            |--------------------------------------------------------------------------
            | الحسابات
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('asset_account_id')->nullable();

            $table->unsignedBigInteger('accumulated_account_id')->nullable();

            $table->unsignedBigInteger('expense_account_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | الفواتير
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('purchase_invoice_id')->nullable();

            $table->unsignedBigInteger('journal_entry_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | الحالة
            |--------------------------------------------------------------------------
            */

            $table->enum('asset_status',[
                'ACTIVE',
                'UNDER_MAINTENANCE',
                'SOLD',
                'DISPOSED'
            ])->default('ACTIVE');

            $table->boolean('is_active')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->unique(['company_id','asset_code']);

            $table->index('category_id');
            $table->index('branch_id');
            $table->index('asset_status');
            $table->index('purchase_date');
            $table->index('responsible_worker_id');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};