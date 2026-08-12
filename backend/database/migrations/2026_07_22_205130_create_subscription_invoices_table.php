<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('company_id')->index();
            $table->bigInteger('subscription_id')->nullable()->index();
            $table->bigInteger('plan_id')->nullable()->index();

            $table->string('invoice_number', 100)->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 15, 3)->default(0);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->decimal('tax_rate', 8, 3)->default(15);
            $table->decimal('tax_amount', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3)->default(0);
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->decimal('remaining_amount', 15, 3)->default(0);

            $table->string('currency_code', 10)->default('SAR');

            $table->string('status', 30)
                ->default('UNPAID')
                ->index();

            $table->string('billing_period', 30)
                ->default('MONTHLY');

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->text('notes')->nullable();

            $table->bigInteger('created_by')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['invoice_date', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};