<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');

            $table->string('shipment_number',50)->unique();

            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('car_id');

            $table->date('shipment_date');

            $table->string('plate_number')->nullable();
            $table->string('weight_card_number')->nullable();

            $table->decimal('gross_weight',15,3)->default(0);
            $table->decimal('tare_weight',15,3)->default(0);
            $table->decimal('net_weight',15,3)->default(0);

            $table->decimal('general_discount',15,3)->default(0);

            $table->decimal('transport_cost',15,3)->default(0);
            $table->decimal('loading_cost',15,3)->default(0);
            $table->decimal('other_cost',15,3)->default(0);

            $table->decimal('vat_percent',5,2)->default(15);
            $table->decimal('vat_amount',15,3)->default(0);

            $table->decimal('total_cost',15,3)->default(0);

            $table->enum('status',[
                'DRAFT',
                'APPROVED',
                'FINISHED',
                'CLOSED'
            ])->default('DRAFT');

            $table->longText('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('driver_id')->references('id')->on('drivers');
            $table->foreign('car_id')->references('id')->on('cars');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};