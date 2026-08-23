<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | SULB ERP Stage 9 — Weighbridge Material Workflow
        |------------------------------------------------------------------
        | 1) Every NEW weighbridge card identifies the physical material.
        | 2) Vehicle becomes optional: VEHICLE / YARD_SCALE / SMALL_SCALE /
        |    NO_VEHICLE are recorded explicitly instead of fake fleet cars.
        | 3) Existing historical cards remain valid (item_id stays nullable
        |    in DB for backward compatibility, but API requires it for new).
        | 4) Repair/seed company-wide WB numbering so Stage 7 sequences do
        |    not restart at 000001 when historical WB cards already exist.
        */

        if (Schema::hasTable('weighbridge_cards')) {
            Schema::table('weighbridge_cards', function (Blueprint $t) {
                if (!Schema::hasColumn('weighbridge_cards','item_id')) {
                    $t->unsignedBigInteger('item_id')->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','item_code_snapshot')) {
                    $t->string('item_code_snapshot',80)->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','item_name_snapshot')) {
                    $t->string('item_name_snapshot',255)->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','item_assigned_at')) {
                    $t->dateTime('item_assigned_at')->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','item_assigned_by')) {
                    $t->unsignedBigInteger('item_assigned_by')->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','item_assignment_note')) {
                    $t->string('item_assignment_note',500)->nullable();
                }
                if (!Schema::hasColumn('weighbridge_cards','transport_mode')) {
                    $t->string('transport_mode',30)->default('VEHICLE');
                }
                if (!Schema::hasColumn('weighbridge_cards','transport_label')) {
                    $t->string('transport_label',150)->nullable();
                }
            });

            // Indexes are best-effort because some customer databases may already contain equivalent indexes.
            try {
                Schema::table('weighbridge_cards', function (Blueprint $t) {
                    $t->index(['company_id','item_id','status'], 'idx_wb_item_status');
                });
            } catch (\Throwable $e) {}

            DB::table('weighbridge_cards')->whereNull('car_id')->update(['transport_mode'=>'YARD_SCALE','transport_label'=>DB::raw("COALESCE(transport_label,'ميزان الحوش / بدون سيارة')")]);
            DB::table('weighbridge_cards')->whereNotNull('car_id')->update(['transport_mode'=>'VEHICLE']);

            // Historical cards that have exactly one card-allocation can be backfilled safely.
            if (Schema::hasTable('weighbridge_card_item_allocations')) {
                $single = DB::table('weighbridge_card_item_allocations as a')
                    ->select('a.weighbridge_card_id', DB::raw('MIN(a.item_id) item_id'), DB::raw('COUNT(DISTINCT a.item_id) item_count'))
                    ->groupBy('a.weighbridge_card_id')->havingRaw('COUNT(DISTINCT a.item_id)=1')->get();
                foreach ($single as $x) {
                    $item = DB::table('items')->where('id',(int)$x->item_id)->first(['id','item_code','item_name']);
                    if (!$item) continue;
                    DB::table('weighbridge_cards')->where('id',(int)$x->weighbridge_card_id)->whereNull('item_id')->update([
                        'item_id'=>(int)$item->id,'item_code_snapshot'=>$item->item_code,'item_name_snapshot'=>$item->item_name,'updated_at'=>now(),
                    ]);
                }
            }

            // If a historical card is linked to a shipment with one and only one item, that item is unambiguous.
            $cards = DB::table('weighbridge_cards')->whereNull('item_id')->whereNotNull('shipment_id')->get(['id','shipment_id']);
            foreach ($cards as $c) {
                $items = DB::table('shipment_items')->where('shipment_id',(int)$c->shipment_id)->distinct()->pluck('item_id');
                if ($items->count() !== 1) continue;
                $item = DB::table('items')->where('id',(int)$items->first())->first(['id','item_code','item_name']);
                if (!$item) continue;
                DB::table('weighbridge_cards')->where('id',(int)$c->id)->update([
                    'item_id'=>(int)$item->id,'item_code_snapshot'=>$item->item_code,'item_name_snapshot'=>$item->item_name,'updated_at'=>now(),
                ]);
            }
        }

        // Repair company-wide weighbridge numbering. Card number uniqueness is (company_id, card_number),
        // therefore WB numbers must not restart per branch.
        if (Schema::hasTable('sulb_document_sequences') && Schema::hasTable('weighbridge_cards')) {
            $rows = DB::table('weighbridge_cards')
                ->where('card_number','like','WB-%')
                ->select(
                    'company_id',
                    DB::raw('YEAR(COALESCE(entry_at, opened_at, created_at, NOW())) AS document_year'),
                    DB::raw("MAX(CAST(SUBSTRING_INDEX(card_number, '-', -1) AS UNSIGNED)) AS max_number")
                )
                ->groupBy('company_id', DB::raw('YEAR(COALESCE(entry_at, opened_at, created_at, NOW()))'))
                ->get();

            foreach ($rows as $r) {
                $year=(int)$r->document_year;$next=max(1,(int)$r->max_number+1);
                $existing=DB::table('sulb_document_sequences')->where('company_id',(int)$r->company_id)->where('branch_id',0)
                    ->where('document_type','WEIGHBRIDGE_CARD')->where('document_year',$year)->first();
                if ($existing) {
                    if ((int)$existing->next_number < $next) {
                        DB::table('sulb_document_sequences')->where('id',$existing->id)->update(['next_number'=>$next,'updated_at'=>now()]);
                    }
                } else {
                    DB::table('sulb_document_sequences')->insert([
                        'company_id'=>(int)$r->company_id,'branch_id'=>0,'document_type'=>'WEIGHBRIDGE_CARD','document_year'=>$year,
                        'next_number'=>$next,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('weighbridge_cards')) {
            try { Schema::table('weighbridge_cards', fn (Blueprint $t) => $t->dropIndex('idx_wb_item_status')); } catch (\Throwable $e) {}
            $cols=['item_id','item_code_snapshot','item_name_snapshot','item_assigned_at','item_assigned_by','item_assignment_note','transport_mode','transport_label'];
            $existing=array_values(array_filter($cols,fn($c)=>Schema::hasColumn('weighbridge_cards',$c)));
            if ($existing) Schema::table('weighbridge_cards', fn (Blueprint $t) => $t->dropColumn($existing));
        }
    }
};
