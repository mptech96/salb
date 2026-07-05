<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    protected $fillable = [
        'company_id',
        'shipment_id',
        'item_id',

        'gross_weight',
        'tare_weight',
        'deduction_weight',
        'net_weight',

        'remaining_qty',
        'sold_qty',

        'unit_price',
        'discount_amount',

        'vat_percent',
        'vat_amount',
        'total_before_vat',
        'total_after_vat',

        'line_total',
        'average_cost',
        'distributed_cost',
        'profit',

        'inventory_created',
        'purchase_line_id',
        'sorting_order',
        'status',
        'notes',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:3',
        'tare_weight' => 'decimal:3',
        'deduction_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'remaining_qty' => 'decimal:3',
        'sold_qty' => 'decimal:3',
        'unit_price' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'vat_percent' => 'decimal:2',
        'vat_amount' => 'decimal:3',
        'total_before_vat' => 'decimal:3',
        'total_after_vat' => 'decimal:3',
        'line_total' => 'decimal:3',
        'average_cost' => 'decimal:3',
        'distributed_cost' => 'decimal:3',
        'profit' => 'decimal:3',
        'inventory_created' => 'boolean',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}