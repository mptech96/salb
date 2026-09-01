<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('header_image_path', 500)->nullable()->after('stamp_path');
            $table->string('footer_image_path', 500)->nullable()->after('header_image_path');
            $table->json('print_header_texts')->nullable()->after('report_footer');
            $table->json('print_footer_texts')->nullable()->after('print_header_texts');
            $table->json('print_options')->nullable()->after('print_footer_texts');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['header_image_path', 'footer_image_path', 'print_header_texts', 'print_footer_texts', 'print_options']);
        });
    }
};
