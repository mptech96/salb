<?php
/** Audit sale FIFO against immutable recorded movement order; never mutates rows. */
function historyFifoEvidence(array $lots,iterable $movements):array {
    $index=[];$available=[];$exceptions=[];$audited=0;$seen=[];$pending=[];$pendingKey=null;
    foreach($lots as$lot){$lot=(array)$lot;$id=(string)$lot['id'];$index[$id]=$lot;$available[$id]='0.000000';}
    $scope=fn($l)=>implode(':',[$l['company_id'],$l['branch_id'],$l['item_id'],($l['uom_version']??'LEGACY_KG')==='BASE_V1'?('BASE:'.($l['base_uom_id_snapshot']??'MISSING')):'LEGACY_KG']);
    $quantity=function($m)use(&$exceptions){$v=($m['uom_version']??'LEGACY_KG')==='BASE_V1'?($m['base_quantity']??null):($m['qty_kg']??null);if(!is_string($v)&&!is_int($v))throw new RuntimeException('HISTORY_FIFO_QUANTITY_REQUIRED');if(!preg_match('/^\d+(?:\.\d{1,6})?$/D',(string)$v)||bccomp((string)$v,'0',6)<=0)throw new RuntimeException('HISTORY_FIFO_QUANTITY_REQUIRED');return (string)$v;};
    $flush=function()use(&$pending,&$pendingKey,&$available,&$exceptions,&$audited,&$seen,$index,$scope,$quantity){
        if(!$pending)return;$actual=[];$demand=[];
        if(isset($seen[$pendingKey]))$exceptions[]=['reason'=>'FIFO_INTERLEAVED_EVENT_REVIEW'];$seen[$pendingKey]=true;
        foreach($pending as$m){$id=(string)$m['inventory_lot_id'];$s=$scope($index[$id]);$q=$quantity($m);$actual[$id]=bcadd($actual[$id]??'0',$q,6);$demand[$s]=bcadd($demand[$s]??'0',$q,6);}
        $ideal=[];
        foreach($demand as$s=>$needed){$eligible=array_filter($index,fn($l)=>$scope($l)===$s&&bccomp($available[(string)$l['id']],'0',6)>0);
            foreach($eligible as$l)if(empty($l['received_at'])||!preg_match('/^\d{4}-\d{2}-\d{2} /',(string)$l['received_at']))$exceptions[]=['reason'=>'FIFO_RECEIPT_DATE_REVIEW'];
            usort($eligible,fn($a,$b)=>strcmp((string)$a['received_at'],(string)$b['received_at'])?:bccomp((string)$a['id'],(string)$b['id'],0));
            foreach($eligible as$l){if(bccomp($needed,'0',6)<=0)break;$id=(string)$l['id'];$take=bccomp($available[$id],$needed,6)<0?$available[$id]:$needed;$ideal[$id]=$take;$needed=bcsub($needed,$take,6);}
            if(bccomp($needed,'0',6)>0)$exceptions[]=['reason'=>'FIFO_RECORDED_STOCK_SHORTFALL'];
        }
        foreach(array_unique(array_merge(array_keys($ideal),array_keys($actual)))as$id)if(bccomp($ideal[$id]??'0',$actual[$id]??'0',6)!==0)$exceptions[]=['reason'=>'FIFO_ALLOCATION_MISMATCH','lot_id'=>$id];
        foreach($actual as$id=>$q)$available[$id]=bcsub($available[$id],$q,6);$audited++;$pending=[];$pendingKey=null;
    };
    try{foreach($movements as$row){$m=(array)$row;$id=(string)$m['inventory_lot_id'];if(!isset($index[$id]))throw new RuntimeException('HISTORY_FIFO_LOT_REQUIRED');$l=$index[$id];
        foreach(['company_id','branch_id','item_id']as$key)if((string)$m[$key]!== (string)$l[$key])throw new RuntimeException('HISTORY_FIFO_SCOPE_MISMATCH');
        if(($m['uom_version']??'LEGACY_KG')!==($l['uom_version']??'LEGACY_KG'))throw new RuntimeException('HISTORY_FIFO_UNIT_MISMATCH');
        if(($l['uom_version']??'LEGACY_KG')==='BASE_V1'&&(string)($m['base_uom_id_snapshot']??'')!==(string)($l['base_uom_id_snapshot']??''))throw new RuntimeException('HISTORY_FIFO_UNIT_MISMATCH');
        $sale=$m['source_type']==='SALE'&&$m['movement_type']==='OUT';$event=$sale?'SALE:'.$m['source_id']:null;
        if($pending&&$event!==$pendingKey)$flush();
        if($sale){$pendingKey=$event;$pending[]=$m;continue;}
        $q=$quantity($m);
        if($m['movement_type']==='IN')$available[$id]=bcadd($available[$id],$q,6);
        elseif($m['movement_type']==='OUT')$available[$id]=bcsub($available[$id],$q,6);
        else throw new RuntimeException('HISTORY_FIFO_DIRECTION_REQUIRED');
        if(bccomp($available[$id],'0',6)<0)$exceptions[]=['reason'=>'FIFO_NEGATIVE_RECORDED_BALANCE','lot_id'=>$id];
    }$flush();}catch(Throwable $e){$exceptions[]=['reason'=>preg_match('/^HISTORY_FIFO_[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'HISTORY_FIFO_EVIDENCE_REVIEW'];}
    foreach($index as$id=>$l){$remaining=($l['uom_version']??'LEGACY_KG')==='BASE_V1'?($l['remaining_base_qty']??null):($l['qty_remaining_kg']??null);if($remaining===null||bccomp($available[$id],(string)$remaining,6)!==0)$exceptions[]=['reason'=>'FIFO_RECORDED_END_BALANCE_MISMATCH','lot_id'=>$id];}
    return ['basis'=>'SALE_FIFO_BY_RECORDED_MOVEMENT_ORDER','sale_events_audited'=>$audited,'exceptions'=>$exceptions,'ready'=>$exceptions===[]];
}
