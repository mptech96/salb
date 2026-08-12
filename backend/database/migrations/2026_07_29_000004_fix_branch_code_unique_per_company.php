<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            /*
            |--------------------------------------------------------------------------
            | كود الفرع فريد داخل الشركة فقط
            |--------------------------------------------------------------------------
            |
            | كان الفهرس القديم يمنع تكرار كود الفرع في جميع شركات النظام.
            | الصحيح في النظام متعدد الشركات هو منع التكرار داخل الشركة نفسها.
            |
            */
            $table->dropUnique('branch_code');

            $table->unique(
                ['company_id', 'branch_code'],
                'branches_company_branch_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(
                'branches_company_branch_code_unique'
            );

            $table->unique('branch_code', 'branch_code');
        });
    }
};
