<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Car extends Model
{
    protected $table = 'cars';

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'driver_id',
        'car_number',
        'plate_number',
        'weight_card_number',
        'gross_weight',
        'deduction_weight',
        'net_weight',
        'transport_cost',
        'extra_cost',
        'notes',
        'car_status',
        'arrival_date',
    ];
}