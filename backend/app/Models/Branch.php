<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'branch_code',
        'branch_name',
        'phone',
        'city',
        'address',
        'is_active',
    ];
}