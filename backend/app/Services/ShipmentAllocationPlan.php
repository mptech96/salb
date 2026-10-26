<?php
namespace App\Services;

/** Internal immutable value. Authenticity is the issuer's object registry, never API data. */
final readonly class ShipmentAllocationPlan
{
    public function __construct(public int $company,public int $shipment,public array $input,public array $costs){}
}
