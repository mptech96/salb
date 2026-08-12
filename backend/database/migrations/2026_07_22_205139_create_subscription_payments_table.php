<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('company_id')->index();
            $table->bigInteger('subscription_id')->nullable()->index();
            $table->bigInteger('invoice_id')->nullable()->index();

            $table->string('payment_number', 100)->unique();
            $table->date('payment_date');

            $table->decimal('amount', 15, 3)->default(0);
            $table->string('currency_code', 10)->default('SAR');

            $table->string('payment_method', 50)
                ->default('BANK_TRANSFER')
                ->index();

            $table->string('payment_status', 30)
                ->default('PAID')
                ->index();

            $table->string('reference_number', 150)->nullable();
            $table->string('gateway_name', 100)->nullable();
            $table->string('gateway_transaction_id', 200)->nullable();

            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 100)->nullable();

            $table->text('notes')->nullable();

            $table->bigInteger('received_by')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'payment_date']);
            $table->index(['invoice_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};