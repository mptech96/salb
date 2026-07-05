<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_code',
        'supplier_name',
        'phone',
        'city',
        'address',
        'opening_balance',
        'notes',
        'is_active',
    ];
}