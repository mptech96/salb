<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciation', function (Blueprint $table) {

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
            | الأصل
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('asset_id');

            /*
            |--------------------------------------------------------------------------
            | الفترة
            |--------------------------------------------------------------------------
            */

            $table->date('depreciation_month');

            /*
            |--------------------------------------------------------------------------
            | القيم
            |--------------------------------------------------------------------------
            */

            $table->decimal('opening_book_value',15,3);

            $table->decimal('depreciation_amount',15,3);

            $table->decimal('accumulated_depreciation',15,3);

            $table->decimal('closing_book_value',15,3);

            /*
            |--------------------------------------------------------------------------
            | القيد المحاسبي
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('journal_entry_id')->nullable();

            /*
            |--------------------------------------------------------------------------
            | الحالة
            |--------------------------------------------------------------------------
            */

            $table->enum('status',[
                'DRAFT',
                'POSTED',
                'REVERSED'
            ])->default('DRAFT');

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->unique(
                ['asset_id','depreciation_month'],
                'uq_asset_month'
            );

            $table->index('company_id');
            $table->index('journal_entry_id');
            $table->index('status');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciation');
    }
};