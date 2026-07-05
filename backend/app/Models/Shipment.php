<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $table = 'shipments';

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'driver_id',
        'car_id',

        'shipment_number',
        'shipment_date',
        'plate_number',
        'weight_card_number',
        'status',

        'total_gross_weight',
        'total_tare_weight',
        'total_deduction_weight',
        'total_net_weight',

        'total_before_discount',
        'discount_amount',

        // مؤقتًا موجودة بالجدول، لاحقًا نخلي التكاليف في جدول مستقل
        'transport_cost',
        'extra_cost',

        'vat_percent',
        'vat_amount',
        'total_amount',

        'purchase_invoice_id',

        'approved_at',
        'approved_by',
        'finished_at',
        'finished_by',
        'closed_at',
        'closed_by',

        'distributed_cost',
        'profit',

        'notes',
        'created_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'approved_at' => 'datetime',
        'finished_at' => 'datetime',
        'closed_at' => 'datetime',

        'total_gross_weight' => 'decimal:3',
        'total_tare_weight' => 'decimal:3',
        'total_deduction_weight' => 'decimal:3',
        'total_net_weight' => 'decimal:3',
        'total_before_discount' => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'transport_cost' => 'decimal:3',
        'extra_cost' => 'decimal:3',
        'vat_percent' => 'decimal:2',
        'vat_amount' => 'decimal:3',
        'total_amount' => 'decimal:3',
        'distributed_cost' => 'decimal:3',
        'profit' => 'decimal:3',
    ];

    public function items()
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }
}