<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentCost extends Model
{
    protected $table = 'shipment_costs';

    protected $guarded = [];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}