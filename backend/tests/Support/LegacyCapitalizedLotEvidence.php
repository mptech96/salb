<?php
namespace Tests\Support;

/** QA-only qualification of untouched or wholly reversed receipts, never partial issues. */
final class LegacyCapitalizedLotEvidence
{
    public static function row(object $lot,array $snapshot):array
    {
        UomStageAProof::guard();$same=fn($a,$b)=>bccomp((string)$a,(string)$b,3)===0;
        $head=collect($snapshot['purchase_invoices'])->firstWhere('id',$lot->purchase_invoice_id);
        $line=collect($snapshot['purchase_invoice_lines'])->firstWhere('id',$lot->purchase_invoice_line_id);
        if(!$head||!$line||!$lot->shipment_id||$lot->source_type!=='PURCHASE'||(int)$head->branch_id!==(int)$lot->branch_id
            ||(int)$line->purchase_invoice_id!==(int)$head->id||(int)$line->item_id!==(int)$lot->item_id||(int)$line->shipment_id!==(int)$lot->shipment_id
            ||!$same($line->qty_kg,$lot->qty_received_kg)||!$same($line->base_total_before_vat,$lot->base_cost)
            ||!$same(bcadd($lot->base_cost,$lot->allocated_cost,3),$lot->total_cost)
            ||collect($snapshot['inventory_lots'])->where('purchase_invoice_line_id',$line->id)->count()!==1)throw new \RuntimeException('SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED');
        $entry=collect($snapshot['journal_entries'])->firstWhere('id',$head->journal_entry_id);
        if(!$entry||$entry->source_type!=='PURCHASE'||(int)$entry->source_id!==(int)$head->id||(int)$entry->branch_id!==(int)$lot->branch_id||$entry->status!=='POSTED')throw new \RuntimeException('LEGACY_CAPITALIZATION_PURCHASE_GL_REQUIRED');
        $buckets=[];
        foreach(collect($snapshot['inventory_lots'])->where('purchase_invoice_id',$head->id)as$receiptLot){
            $item=collect($snapshot['items'])->firstWhere('id',$receiptLot->item_id);if(!$item||!$item->inventory_account_id)throw new \RuntimeException('LEGACY_CAPITALIZATION_PURCHASE_GL_REQUIRED');
            $account=(int)$item->inventory_account_id;$buckets[$account]=bcadd($buckets[$account]??'0',$receiptLot->base_cost,3);
        }
        foreach($buckets as$account=>$expected){$actual='0';foreach($snapshot['journal_entry_lines']as$l)if((int)$l->journal_entry_id===(int)$entry->id&&(int)$l->account_id===$account)$actual=bcadd($actual,bcsub($l->debit,$l->credit,3),3);if(!$same($actual,$expected))throw new \RuntimeException('LEGACY_CAPITALIZATION_PURCHASE_GL_VALUE');}
        $void=$head->document_status==='VOID' && $head->voided_at!==null;
        if(!$void&&($head->document_status!=='POSTED'||$head->voided_at||$entry->reversed_at))throw new \RuntimeException('LEGACY_CAPITALIZATION_PURCHASE_STATE');
        $movements=collect($snapshot['inventory_lot_movements'])->where('inventory_lot_id',$lot->id)->values();
        if($movements->count()!==($void?2:1))throw new \RuntimeException('LEGACY_CAPITALIZATION_ISSUE_ORDER_REVIEW_REQUIRED');
        $in=$movements[0];
        if($in->movement_type!=='IN'||$in->source_type!=='PURCHASE'||(int)$in->source_id!==(int)$head->id
            ||(int)$in->branch_id!==(int)$lot->branch_id||(int)$in->item_id!==(int)$lot->item_id
            ||!$same($in->qty_kg,$lot->qty_received_kg)||!$same($in->total_cost,$lot->base_cost))throw new \RuntimeException('LEGACY_CAPITALIZATION_ORIGINAL_RECEIPT_REQUIRED');
        $sum='0.000';$seen=[];
        foreach($snapshot['shipment_cost_allocation_receipts']??[]as$receipt){
            if((int)$receipt->shipment_id!==(int)$lot->shipment_id)continue;
            if(!hash_equals($receipt->payload_hash,hash('sha256',$receipt->payload)))throw new \RuntimeException('LEGACY_CAPITALIZATION_HASH_REQUIRED');
            $payload=json_decode($receipt->payload,true,512,JSON_THROW_ON_ERROR);$rows=collect($payload['recipients']??[])->where('inventory_lot_id',$lot->id)->values();
            if($rows->isEmpty())continue;if($rows->count()!==1||isset($seen[$receipt->shipment_cost_id]))throw new \RuntimeException('LEGACY_CAPITALIZATION_RECIPIENT_AMBIGUOUS');$seen[$receipt->shipment_cost_id]=true;$row=$rows[0];
            if((int)$receipt->branch_id!==(int)$lot->branch_id||($row['uom_version']??'')!=='LEGACY_KG'
                ||(int)$row['shipment_item_id']!==(int)$line->shipment_item_id||!$same($row['qty'],$lot->qty_received_kg)||!$same($row['base_cost'],$lot->base_cost))throw new \RuntimeException('LEGACY_CAPITALIZATION_RECEIPT_SCOPE');
            $cost=collect($snapshot['shipment_costs'])->firstWhere('id',$receipt->shipment_cost_id);
            if(!$cost||$cost->cost_status!==($void?'VOID':'POSTED'))throw new \RuntimeException('LEGACY_CAPITALIZATION_COST_STATE');
            // Uses immutable recipient amounts and original/reversal GL. No LR recalculation.
            [$verified,$amount]=\App\Services\Uom\BaseLotCapitalization::receipt($lot,(int)$receipt->shipment_cost_id);
            if((int)$verified->id!==(int)$receipt->id)throw new \RuntimeException('LEGACY_CAPITALIZATION_RECEIPT_CHANGED');$sum=bcadd($sum,$amount,3);
        }
        if(!$seen||!$same($sum,$lot->allocated_cost))throw new \RuntimeException('SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED');
        if($void){
            $out=$movements[1];$reversal=collect($snapshot['journal_entries'])->firstWhere('id',$entry->reversed_by_id);
            if(!$entry->reversed_at||!$reversal||$reversal->status!=='POSTED'||$reversal->source_type!=='PURCHASE_REVERSAL'||(int)$reversal->reversal_of_id!==(int)$entry->id||(int)$reversal->branch_id!==(int)$lot->branch_id
                ||$out->source_type!=='PURCHASE_REVERSAL'||$out->movement_type!=='OUT'||(int)$out->source_id!==(int)$head->id||(int)$out->item_id!==(int)$lot->item_id||(int)$out->branch_id!==(int)$lot->branch_id
                ||!$same($out->qty_kg,$lot->qty_received_kg)||!$same($out->total_cost,$lot->total_cost)||!$same($lot->qty_remaining_kg,'0'))throw new \RuntimeException('LEGACY_CAPITALIZATION_REVERSAL_REQUIRED');
            $nets=function($id)use($snapshot){$totals=[];foreach($snapshot['journal_entry_lines']as$l)if((int)$l->journal_entry_id===(int)$id)$totals[$l->account_id]=bcadd($totals[$l->account_id]??'0',bcsub($l->debit,$l->credit,3),3);ksort($totals);return$totals;};
            $original=$nets($entry->id);$opposite=$nets($reversal->id);if(array_keys($original)!==array_keys($opposite))throw new \RuntimeException('LEGACY_CAPITALIZATION_REVERSAL_AMOUNT');foreach($original as$account=>$value)if(!$same($opposite[$account],bcsub('0',$value,3)))throw new \RuntimeException('LEGACY_CAPITALIZATION_REVERSAL_AMOUNT');
        }elseif(!$same($lot->qty_remaining_kg,$lot->qty_received_kg))throw new \RuntimeException('LEGACY_CAPITALIZATION_ISSUE_ORDER_REVIEW_REQUIRED');
        return ['company_id'=>$lot->company_id,'inventory_lot_id'=>$lot->id,'original_quantity'=>$lot->qty_received_kg,'remaining_quantity'=>$void?'0.000':$lot->qty_received_kg,
            'original_value'=>$lot->total_cost,'remaining_value'=>$void?'0.000':$lot->total_cost,'policy_version'=>'FINAL_RESIDUAL_V1','evidence_movement_ids'=>$movements->pluck('id')->all()];
    }
}
