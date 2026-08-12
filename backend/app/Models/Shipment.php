<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shipment extends Model { protected $table='shipments'; protected $guarded=[]; public function items(){return $this->hasMany(ShipmentItem::class,'shipment_id');} }
