<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Technical persistence identity only. Never an approved economic rounding tie key. */
final class ShipmentLineIdentity
{
    public function enabled(): bool
    {
        // No process-wide cache: workers/tests may switch databases or deploy DDL.
        if (!Schema::hasColumn('shipment_items', 'allocation_line_ref')) return false;
        $columns = DB::select("SHOW FULL COLUMNS FROM shipment_items WHERE Field = 'allocation_line_ref'");
        $index = DB::select("SHOW INDEX FROM shipment_items WHERE Key_name = 'uq_shipment_allocation_ref'");
        usort($index, fn($a, $b) => $a->Seq_in_index <=> $b->Seq_in_index);
        $triggers = DB::select("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'shipment_items'");
        $names = array_map(fn($r) => $r->TRIGGER_NAME, $triggers);
        if (count($columns) !== 1 || strtolower($columns[0]->Type) !== 'varchar(128)' || $columns[0]->Collation !== 'ascii_bin'
            || $columns[0]->Null !== 'YES' || array_map(fn($r) => $r->Column_name, $index) !== ['company_id','shipment_id','allocation_line_ref']
            || array_filter($index, fn($r) => (int)$r->Non_unique !== 0 || $r->Sub_part !== null)
            || array_diff(['sli_insert','sli_update','sli_delete'], $names)) {
            throw new \RuntimeException('SLI_SCHEMA_NOT_READY: بنية هوية أسطر الشحنة غير مكتملة.');
        }
        return true;
    }

    public function assertServerOwned(array $items): void
    {
        foreach ($items as $row) {
            if (is_array($row) && (array_key_exists('allocation_line_ref', $row) || array_key_exists('business_line_ref', $row))) {
                throw new \RuntimeException('SLI_SERVER_OWNED_REFERENCE: مرجع السطر ينشئه الخادم ولا يقبل من الطلب.');
            }
        }
    }

    public function newAttributes(bool $enabled): array
    {
        return $enabled ? ['allocation_line_ref' => 'BL:'.strtoupper(bin2hex(random_bytes(16)))] : [];
    }
}
