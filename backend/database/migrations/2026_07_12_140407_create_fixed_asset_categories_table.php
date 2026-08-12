<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | الشركة والتعريف
            |--------------------------------------------------------------------------
            */
            $table->unsignedBigInteger('company_id');
            $table->string('category_code', 50);
            $table->string('category_name', 200);
            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | إعدادات الإهلاك الافتراضية للفئة
            |--------------------------------------------------------------------------
            |
            | STRAIGHT_LINE       = القسط الثابت
            | DECLINING_BALANCE   = الرصيد المتناقص
            | NO_DEPRECIATION     = بدون إهلاك
            |
            */
            $table
                ->enum('depreciation_method', [
                    'STRAIGHT_LINE',
                    'DECLINING_BALANCE',
                    'NO_DEPRECIATION',
                ])
                ->default('STRAIGHT_LINE');

            $table
                ->unsignedInteger('useful_life_months')
                ->nullable();

            $table
                ->decimal('annual_depreciation_rate', 8, 4)
                ->nullable();

            $table
                ->decimal('default_salvage_percentage', 8, 4)
                ->default(0);

            /*
            |--------------------------------------------------------------------------
            | الحسابات المحاسبية
            |--------------------------------------------------------------------------
            */
            $table
                ->unsignedBigInteger('asset_account_id')
                ->nullable();

            $table
                ->unsignedBigInteger('accumulated_depreciation_account_id')
                ->nullable();

            $table
                ->unsignedBigInteger('depreciation_expense_account_id')
                ->nullable();

            $table
                ->unsignedBigInteger('disposal_gain_account_id')
                ->nullable();

            $table
                ->unsignedBigInteger('disposal_loss_account_id')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | الحالة والتدقيق
            |--------------------------------------------------------------------------
            */
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | القيود والفهارس
            |--------------------------------------------------------------------------
            */
            $table->unique(
                ['company_id', 'category_code'],
                'uq_asset_category_company_code'
            );

            $table->index(
                ['company_id', 'is_active'],
                'idx_asset_category_company_active'
            );

            $table->index('asset_account_id');
            $table->index('accumulated_depreciation_account_id');
            $table->index('depreciation_expense_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_categories');
    }
};