<?php
require_once __DIR__.'/history-data-rehearsal.php';
function verifyDerivedSource(PDO $pdo,array $a,bool $resume=false):void {
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$pdo->exec("SET SESSION time_zone='+00:00'");
    if(($a['format']??'')!=='SULB_DERIVED_BALANCES_V1'||($a['original_source_preserved']??false)!==true||!is_array($a['source_inventory']??null)||!is_array($a['source_manifest']??null))throw new RuntimeException('DERIVED_SOURCE_EVIDENCE_REQUIRED');
    $generator=hash('sha256',str_replace("\r\n","\n",file_get_contents(__DIR__.'/approved-history-rehearsal.php')));if(isset($a['generator_sha256'])&&!hash_equals($generator,$a['generator_sha256']))throw new RuntimeException('DERIVED_REHEARSAL_CODE_CHANGED');
    $required=['companies','branches','accounts','accounting_settings','items','inventory_lots','inventory_lot_movements','purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','sales_line_lot_sources','journal_entries','journal_entry_lines','opening_balance_batches','opening_inventory_lines','inventory_operations'];foreach($required as$name)if(!isset($a['source_inventory'][$name]))throw new RuntimeException('DERIVED_FINANCIAL_DEPENDENCY_MISSING');
    $optional=['item_categories','item_groups','shipment_costs','shipment_cost_allocation_receipts','shipments','shipment_items','commercial_documents','commercial_document_lines','uom_inventory_adjustments','uom_processing_commands','uom_processing_recipes','uom_processing_cost_sources','uom_lot_capitalization_events','uom_invoice_header_cost_receipts'];$financial=array_intersect_key($a['source_inventory'],array_flip(array_merge($required,$optional)));historyLegacyProjection($pdo,$financial,$a['source_manifest'],$a['max_permission']??null,false);
    foreach($a['sequences']??[]as$r){$q=$pdo->prepare("SELECT next_number FROM sulb_document_sequences WHERE company_id=? AND branch_id=0 AND document_type='INVENTORY_LOT' AND document_year=?");$q->execute([$r['company_id'],$r['document_year']]);$current=$q->fetchColumn();if($current!==false&&(int)$current>(int)$r['next_number'])throw new RuntimeException('DERIVED_SEQUENCE_DRIFT');}
    foreach($a['old_migration_rows']??[]as$row){$q=$pdo->prepare('SELECT * FROM migrations WHERE id=?');$q->execute([$row['id']]);if($q->fetch(PDO::FETCH_ASSOC)!==$row)throw new RuntimeException('DERIVED_OLD_MIGRATION_CHANGED');}
}
function applyProductionDerived(array $a,bool $resume=false):array {
    \Sulb\Release\ProductionUpgradeContext::guard();$db=\Illuminate\Support\Facades\DB::connection();$pdo=$db->getPdo();verifyDerivedSource($pdo,$a,$resume);$plans=$a['company_plans']??[];
    $companies=array_map('intval',$db->table('companies')->orderBy('id')->pluck('id')->all());$scopes=array_map('intval',array_keys($plans));sort($scopes);if($scopes!==$companies)throw new RuntimeException('DERIVED_ALL_COMPANIES_REQUIRED');
    $result=$db->transaction(function()use($db,$a,$plans){$count=0;$seen=[];$diff=[];
        foreach($plans as$company=>$plan){$company=(int)$company;if(($plan['ready']??false)!==true||(int)($plan['company_id']??0)!==$company)throw new RuntimeException('DERIVED_COMPANY_PLAN_REQUIRED');
            $lots=$db->table('inventory_lots')->where('company_id',$company)->orderBy('id')->lockForUpdate()->get()->keyBy('id');$before=$db->table('inventory_lot_value_balances')->where('company_id',$company)->count();
            foreach($plan['rows']as$row){unset($row['evidence_movement_ids']);$keys=array_keys($row);sort($keys);$expected=['company_id','inventory_lot_id','original_quantity','remaining_quantity','original_value','remaining_value','policy_version'];sort($expected);if($keys!==$expected||(int)$row['company_id']!==$company||!isset($lots[$row['inventory_lot_id']]))throw new RuntimeException('DERIVED_LOT_TENANT_SCOPE');
                $lot=$lots[$row['inventory_lot_id']];$base=($lot->uom_version??'')==='BASE_V1';$oq=$base?$lot->received_base_qty:$lot->qty_received_kg;$rq=$base?$lot->remaining_base_qty:$lot->qty_remaining_kg;
                if(bccomp($row['original_quantity'],$oq,6)!==0||bccomp($row['remaining_quantity'],$rq,6)!==0||bccomp($row['original_value'],$lot->total_cost,3)!==0||$row['policy_version']!=='FINAL_RESIDUAL_V1')throw new RuntimeException('DERIVED_LOT_QUANTITY_OR_VALUE_CHANGED');
                $key=$company.':'.$row['inventory_lot_id'];if(isset($seen[$key]))throw new RuntimeException('DERIVED_DUPLICATE_ROW');$seen[$key]=true;
                $old=$db->table('inventory_lot_value_balances')->where('company_id',$company)->where('inventory_lot_id',$row['inventory_lot_id'])->lockForUpdate()->first();
                if($old){foreach($row as$k=>$v)if((string)$old->$k!==(string)$v)throw new RuntimeException('DERIVED_EXISTING_BALANCE_CONFLICT');}
                else $db->table('inventory_lot_value_balances')->insert($row+['created_at'=>now(),'updated_at'=>now()]);$count++;
            }
            $diff[$company]=['before_derived_rows'=>$before,'after_derived_rows'=>$db->table('inventory_lot_value_balances')->where('company_id',$company)->count(),'source_values_changed'=>false];
        }
        if($db->table('inventory_lot_value_balances')->count()!==count($seen))throw new RuntimeException('DERIVED_UNREVIEWED_EXTRA_BALANCE');
        foreach($a['sequences']??[]as$r){$company=(int)$r['company_id'];if(!array_key_exists($company,$plans)||(int)$r['branch_id']!==0||$r['document_type']!=='INVENTORY_LOT'||(int)$r['next_number']<1)throw new RuntimeException('DERIVED_SEQUENCE_SCOPE');$scope=array_intersect_key($r,array_flip(['company_id','branch_id','document_type','document_year']));$old=$db->table('sulb_document_sequences')->where($scope)->lockForUpdate()->first();if($old&&(int)$old->next_number>(int)$r['next_number'])throw new RuntimeException('DERIVED_SEQUENCE_DRIFT');if(!$old)$db->table('sulb_document_sequences')->insert($scope+['next_number'=>$r['next_number'],'created_at'=>now(),'updated_at'=>now()]);elseif((int)$old->next_number<(int)$r['next_number'])$db->table('sulb_document_sequences')->where('id',$old->id)->update(['next_number'=>$r['next_number'],'updated_at'=>now()]);}
        verifyDerivedSource($db->getPdo(),$a,true);return ['derived_rows'=>$count,'per_company'=>$diff,'historical_values_changed'=>false];
    });\Sulb\Release\ProductionUpgradeContext::guard();return $result;
}

function verifyCurrentBackupProjection(PDO $pdo,string $dir):void {
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$pdo->exec("SET SESSION time_zone='+00:00'");
    require_once __DIR__.'/review-account-evidence.php';foreach(['source-inventory.json','manifest-private.json','backup-metadata.json']as$name)accountPrivate($dir.'/'.$name);$read=fn($name)=>json_decode(file_get_contents($dir.'/'.$name),true,512,JSON_THROW_ON_ERROR);$inventory=$read('source-inventory.json');unset($inventory['migrations']);$meta=$read('backup-metadata.json');if(nonLotSequenceStats($pdo,$inventory['sulb_document_sequences']['columns'])!==($meta['non_lot_sequence_stats']??null))throw new RuntimeException('CURRENT_BACKUP_NON_LOT_COUNTER_CHANGED');historyLegacyProjection($pdo,$inventory,$read('manifest-private.json'),$meta['max_permission']??null,true);
    foreach($meta['old_migration_rows']as$row){$q=$pdo->prepare('SELECT * FROM migrations WHERE id=?');$q->execute([$row['id']]);if($q->fetch(PDO::FETCH_ASSOC)!==$row)throw new RuntimeException('CURRENT_BACKUP_OLD_MIGRATION_CHANGED');}
}

function verifyCompletedUpgradeJournal(array $rows,array $plan):void {
    if(count($rows)!==36||count($plan)!==18)throw new RuntimeException('DERIVED_RESUME_COMPLETE_18_REQUIRED');foreach($plan as$i=>$m){$start=$rows[$i*2];$done=$rows[$i*2+1];if(($start['state']??'')!=='START'||($done['state']??'')!=='DONE'||($start['step']??null)!==$m['step']||($done['step']??null)!==$m['step']||($start['source_sha256']??null)!==$m['source_sha256']||($start['adapter_sha256']??null)!==$m['adapter_sha256'])throw new RuntimeException('DERIVED_RESUME_JOURNAL_MISMATCH');}
}
function recordDerivedEvent(string $dir,array $event):void {
    $file=$dir.'/derived-events-private.jsonl';if(is_link($file)||(file_exists($file)&&(fileowner($file)!==0||(fileperms($file)&0077)!==0)))throw new RuntimeException('DERIVED_PRIVATE_AUDIT_REQUIRED');$f=fopen($file,'ab');if(!$f)throw new RuntimeException('DERIVED_AUDIT_OPEN_FAILED');try{chmod($file,0600);$bytes=json_encode($event+['utc'=>gmdate('c')],JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('DERIVED_AUDIT_SYNC_FAILED');}finally{fclose($f);}
}

function nonLotSequenceStats(PDO $pdo,array $columns):array {
    $stats=historyStatsInit();$q=$pdo->query('SELECT '.historySelectColumns($columns)." FROM sulb_document_sequences WHERE document_type<>'INVENTORY_LOT' OR branch_id<>0");while(($row=$q->fetch(PDO::FETCH_NUM))!==false)historyStatsAdd($stats,historyRow($row));return historyStatsFinish($stats);
}
