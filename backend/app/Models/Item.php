<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    protected $fillable = [
        'company_id',
        'category_id',
        'item_code',
        'item_name',
        'item_grade',
        'unit_name',
        'default_buy_price',
        'default_sell_price',
        'min_sell_price',
        'color_notes',
        'notes',
        'is_active',
    ];
}