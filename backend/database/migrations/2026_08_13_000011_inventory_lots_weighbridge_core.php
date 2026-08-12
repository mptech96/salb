<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_lots')) {
            Schema::create('inventory_lots', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('item_id');
                $t->unsignedBigInteger('car_id')->nullable(); $t->unsignedBigInteger('shipment_id')->nullable(); $t->unsignedBigInteger('shipment_item_id')->nullable();
                $t->unsignedBigInteger('purchase_invoice_id')->nullable(); $t->unsignedBigInteger('purchase_invoice_line_id')->nullable();
                $t->string('lot_number',120); $t->string('source_type',50); $t->unsignedBigInteger('source_id')->nullable(); $t->dateTime('received_at');
                $t->decimal('qty_received_kg',18,3)->default(0); $t->decimal('qty_remaining_kg',18,3)->default(0); $t->decimal('qty_sold_kg',18,3)->default(0);
                $t->decimal('base_cost',18,3)->default(0); $t->decimal('allocated_cost',18,3)->default(0); $t->decimal('total_cost',18,3)->default(0); $t->decimal('unit_cost_per_kg',18,6)->default(0);
                $t->string('lot_status',30)->default('OPEN'); $t->text('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
                $t->unique(['company_id','lot_number'],'uq_inventory_lot_number');
                $t->index(['company_id','branch_id','item_id','lot_status'],'idx_inventory_lot_balance');
                $t->index(['company_id','shipment_item_id'],'idx_inventory_lot_shipment_item');
            });
        }

        if (!Schema::hasTable('inventory_lot_movements')) {
            Schema::create('inventory_lot_movements', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('inventory_lot_id'); $t->unsignedBigInteger('item_id');
                $t->string('movement_type',40); $t->string('source_type',50); $t->unsignedBigInteger('source_id')->nullable(); $t->dateTime('movement_at');
                $t->decimal('qty_kg',18,3); $t->decimal('unit_cost_per_kg',18,6)->default(0); $t->decimal('total_cost',18,3)->default(0);
                $t->text('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
                $t->index(['company_id','branch_id','item_id','movement_at'],'idx_lot_movement_item'); $t->index(['inventory_lot_id','movement_at'],'idx_lot_movement_lot');
            });
        }

        if (!Schema::hasTable('sales_line_lot_sources')) {
            Schema::create('sales_line_lot_sources', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('sales_invoice_line_id'); $t->unsignedBigInteger('inventory_lot_id');
                $t->unsignedBigInteger('shipment_id')->nullable(); $t->unsignedBigInteger('shipment_item_id')->nullable(); $t->decimal('qty_kg',18,3); $t->decimal('unit_cost_per_kg',18,6)->default(0); $t->decimal('total_cost',18,3)->default(0); $t->timestamps();
                $t->index(['company_id','sales_invoice_line_id'],'idx_sale_lot_source_line'); $t->index(['company_id','inventory_lot_id'],'idx_sale_lot_source_lot');
            });
        }

        if (!Schema::hasTable('weighbridge_cards')) {
            Schema::create('weighbridge_cards', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('shipment_id')->nullable(); $t->unsignedBigInteger('car_id')->nullable();
                $t->string('card_number',120); $t->string('flow_type',40)->default('PURCHASE_INBOUND'); $t->string('status',30)->default('OPEN');
                $t->decimal('loaded_weight_kg',18,3)->default(0); $t->decimal('empty_weight_kg',18,3)->default(0); $t->decimal('deduction_weight_kg',18,3)->default(0); $t->decimal('net_weight_kg',18,3)->default(0);
                $t->string('scale_name',120)->nullable(); $t->string('external_ticket_number',120)->nullable(); $t->dateTime('opened_at'); $t->dateTime('closed_at')->nullable();
                $t->unsignedBigInteger('opened_by')->nullable(); $t->unsignedBigInteger('closed_by')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
                $t->unique(['company_id','card_number'],'uq_weighbridge_card_number'); $t->unique(['company_id','shipment_id'],'uq_weighbridge_shipment'); $t->index(['company_id','branch_id','status'],'idx_weighbridge_status');
            });
        }

        if (!Schema::hasTable('shipment_weights')) {
            Schema::create('shipment_weights', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('weighbridge_card_id'); $t->unsignedBigInteger('shipment_id')->nullable(); $t->unsignedBigInteger('car_id')->nullable();
                $t->string('event_type',30); $t->string('effective_weight_type',20); $t->decimal('weight_kg',18,3); $t->dateTime('recorded_at');
                $t->string('scale_name',120)->nullable(); $t->string('ticket_number',120)->nullable(); $t->text('notes')->nullable();
                $t->dateTime('cancelled_at')->nullable(); $t->unsignedBigInteger('cancelled_by')->nullable(); $t->text('cancel_reason')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
                $t->index(['company_id','weighbridge_card_id','recorded_at'],'idx_weight_card_time'); $t->index(['company_id','shipment_id','event_type'],'idx_weight_shipment_type');
            });
        }

        $this->addColumns('shipments', [
            'flow_type' => fn(Blueprint $t) => $t->string('flow_type',40)->default('PURCHASE_INBOUND'),
            'weighbridge_card_id' => fn(Blueprint $t) => $t->unsignedBigInteger('weighbridge_card_id')->nullable(),
            'total_loaded_weight_kg' => fn(Blueprint $t) => $t->decimal('total_loaded_weight_kg',18,3)->default(0),
            'total_empty_weight_kg' => fn(Blueprint $t) => $t->decimal('total_empty_weight_kg',18,3)->default(0),
            'total_deduction_weight_kg' => fn(Blueprint $t) => $t->decimal('total_deduction_weight_kg',18,3)->default(0),
            'total_net_weight_kg' => fn(Blueprint $t) => $t->decimal('total_net_weight_kg',18,3)->default(0),
            'cost_allocation_method' => fn(Blueprint $t) => $t->string('cost_allocation_method',30)->default('RELATIVE_VALUE'),
            'costing_status' => fn(Blueprint $t) => $t->string('costing_status',30)->default('PENDING'),
        ]);

        $this->addColumns('shipment_items', [
            'qty_kg' => fn(Blueprint $t) => $t->decimal('qty_kg',18,3)->default(0), 'remaining_qty_kg' => fn(Blueprint $t) => $t->decimal('remaining_qty_kg',18,3)->default(0),
            'sold_qty_kg' => fn(Blueprint $t) => $t->decimal('sold_qty_kg',18,3)->default(0), 'base_cost' => fn(Blueprint $t) => $t->decimal('base_cost',18,3)->default(0),
            'allocated_cost' => fn(Blueprint $t) => $t->decimal('allocated_cost',18,3)->default(0), 'final_unit_cost_per_kg' => fn(Blueprint $t) => $t->decimal('final_unit_cost_per_kg',18,6)->default(0),
            'cost_share_percent' => fn(Blueprint $t) => $t->decimal('cost_share_percent',9,4)->nullable(), 'manual_allocated_cost' => fn(Blueprint $t) => $t->decimal('manual_allocated_cost',18,3)->nullable(),
            'inventory_lot_id' => fn(Blueprint $t) => $t->unsignedBigInteger('inventory_lot_id')->nullable(),
        ]);

        $this->addColumns('stock_movements', [
            'inventory_lot_id' => fn(Blueprint $t) => $t->unsignedBigInteger('inventory_lot_id')->nullable(), 'qty_kg' => fn(Blueprint $t) => $t->decimal('qty_kg',18,3)->default(0),
            'unit_cost_per_kg' => fn(Blueprint $t) => $t->decimal('unit_cost_per_kg',18,6)->default(0),
        ]);

        $this->addColumns('cars', [
            'normalized_plate_number' => fn(Blueprint $t) => $t->string('normalized_plate_number',100)->nullable(), 'owner_name' => fn(Blueprint $t) => $t->string('owner_name',255)->nullable(),
            'vehicle_type' => fn(Blueprint $t) => $t->string('vehicle_type',100)->nullable(), 'is_active' => fn(Blueprint $t) => $t->boolean('is_active')->default(true),
        ]);

        DB::table('shipments')->orderBy('id')->chunkById(200, function($rows){ foreach($rows as $r) DB::table('shipments')->where('id',$r->id)->update([
            'total_loaded_weight_kg'=>(float)($r->total_gross_weight??0),'total_empty_weight_kg'=>(float)($r->total_tare_weight??0),'total_deduction_weight_kg'=>(float)($r->total_deduction_weight??0),'total_net_weight_kg'=>round((float)($r->total_net_weight??0)*1000,3)
        ]); });
        DB::table('shipment_items')->orderBy('id')->chunkById(200, function($rows){ foreach($rows as $r){$kg=round((float)($r->net_weight??0)*1000,3);$base=round((float)($r->total_before_vat??0),3);$alloc=round((float)($r->distributed_cost??0),3);DB::table('shipment_items')->where('id',$r->id)->update([
            'qty_kg'=>$kg,'remaining_qty_kg'=>round((float)($r->remaining_qty??0)*1000,3),'sold_qty_kg'=>round((float)($r->sold_qty??0)*1000,3),'base_cost'=>$base,'allocated_cost'=>$alloc,'final_unit_cost_per_kg'=>$kg>0?round(($base+$alloc)/$kg,6):0
        ]);} });
        DB::table('stock_movements')->orderBy('id')->chunkById(500, function($rows){ foreach($rows as $r){$kg=round((float)($r->qty??0)*1000,3);DB::table('stock_movements')->where('id',$r->id)->update(['qty_kg'=>$kg,'unit_cost_per_kg'=>$kg>0?round((float)($r->total_cost??0)/$kg,6):0]);} });
        DB::table('cars')->orderBy('id')->chunkById(200, function($rows){ foreach($rows as $r) DB::table('cars')->where('id',$r->id)->update(['normalized_plate_number'=>$this->normalizePlate($r->plate_number??null),'is_active'=>1]); });

        /*
        | ترحيل رصيد المخزون القديم كدفعات افتتاحية متوازنة.
        | لا نحاول اختراع مصدر تاريخي غير مؤكد؛ من هذه النقطة فصاعدًا كل دفعة جديدة تحفظ مصدرها الحقيقي.
        */
        $opening = DB::table('stock_movements')
            ->select(
                'company_id','branch_id','item_id','car_id',
                DB::raw("MIN(movement_date) first_date"),
                DB::raw("SUM(CASE WHEN movement_type='IN' THEN qty_kg ELSE -qty_kg END) balance_kg"),
                DB::raw("SUM(CASE WHEN movement_type='IN' THEN total_cost ELSE -total_cost END) balance_value")
            )
            ->whereNotNull('company_id')
            ->whereNotNull('branch_id')
            ->groupBy('company_id','branch_id','item_id','car_id')
            ->get();

        foreach ($opening as $r) {
            $kg = round((float) $r->balance_kg, 3);
            if ($kg <= 0.0001) continue;
            $value = max(0, round((float) $r->balance_value, 3));
            $lotNo = 'LOT-OPEN-' . $r->company_id . '-' . $r->branch_id . '-' . $r->item_id . '-' . ($r->car_id ?: 0);
            DB::table('inventory_lots')->updateOrInsert(
                ['company_id'=>$r->company_id,'lot_number'=>$lotNo],
                ['branch_id'=>$r->branch_id,'item_id'=>$r->item_id,'car_id'=>$r->car_id,'shipment_id'=>null,'shipment_item_id'=>null,'purchase_invoice_id'=>null,'purchase_invoice_line_id'=>null,'source_type'=>'LEGACY_OPENING','source_id'=>null,'received_at'=>$r->first_date ?: now(),'qty_received_kg'=>$kg,'qty_remaining_kg'=>$kg,'qty_sold_kg'=>0,'base_cost'=>$value,'allocated_cost'=>0,'total_cost'=>$value,'unit_cost_per_kg'=>$kg>0?round($value/$kg,6):0,'lot_status'=>'OPEN','notes'=>'رصيد افتتاحي مرحل من حركة المخزون السابقة قبل نظام الدفعات','created_at'=>now(),'updated_at'=>now()]
            );
        }
    }

    public function down(): void { /* لا نحذف أثر المخزون والميزان تلقائيًا */ }

    private function addColumns(string $table,array $columns): void { foreach($columns as $name=>$callback) if(!Schema::hasColumn($table,$name)) Schema::table($table,function(Blueprint $t)use($callback){$callback($t);}); }
    private function normalizePlate(?string $plate): ?string { $plate=trim((string)$plate); return $plate===''?null:(preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtoupper($plate))?:null); }
};
