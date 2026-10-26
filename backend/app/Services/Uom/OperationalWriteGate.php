<?php
namespace App\Services\Uom;
final class OperationalWriteGate
{
    public function assertWritable():never
    {
        // Deliberately ignores client/config overrides until the complete pipeline is approved.
        throw new \RuntimeException('UOM_OPERATIONAL_WRITES_NOT_APPROVED');
    }
}
