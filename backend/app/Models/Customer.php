<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'company_id',
        'branch_id',
        'customer_code',
        'customer_name',
        'phone',
        'city',
        'address',
        'opening_balance',
        'notes',
        'is_active',
    ];
}