<?php
require_once __DIR__.'/verify-private-schema.php';
/** Offline prerequisites only. Never opens a database or executes supplied DDL. */
function privateMigrationContract(array $capture,string $root):array {
    $counts=privateSchemaCounts($capture);
    foreach(['VIEW','TRIGGER','PROCEDURE','FUNCTION','EVENT']as$kind)if($counts[$kind]!==0)throw new RuntimeException('SCHEMA_REQUIRES_NEW_REVIEW');
    $ddl=[];$columns=[];$issues=[];
    foreach($capture['definitions']as$o){
        $create=array_values(array_filter($o['ddl'][0],fn($v,$k)=>str_starts_with(strtolower((string)$k),'create '),ARRAY_FILTER_USE_BOTH))[0];
        if(!preg_match('/^CREATE TABLE `([a-zA-Z0-9_]+)`\s*\(/D',$create,$m)||$m[1]!==$o['name']||!preg_match('/\bENGINE=InnoDB\b/i',$create))throw new RuntimeException('UNSUPPORTED_TABLE_DEFINITION');
        $ddl[$o['name']]=$create;
        foreach(preg_split('/\r?\n/',$create)as$line)if(preg_match('/^\s*`([^`]+)`\s+(.+)$/',$line,$m))$columns[$o['name']][$m[1]]=$m[2];
    }
    // Existing parent keys of foundation/contracts/L3; do not normalize signedness.
    $types=['companies'=>['id'=>'bigint'],'branches'=>['id'=>'bigint unsigned','company_id'=>'bigint'],
      'items'=>['id'=>'bigint unsigned','company_id'=>'bigint'],'users'=>['id'=>'bigint unsigned'],
      'currencies'=>['currency_code'=>'varchar(10)'],'inventory_lots'=>['id'=>'bigint unsigned'],
      'shipments'=>['id'=>'bigint unsigned'],'shipment_costs'=>['id'=>'bigint unsigned','shipment_id'=>'bigint unsigned'],
      'journal_entries'=>['id'=>'bigint unsigned']];
    foreach($types as$t=>$cs)foreach($cs as$c=>$type){$definition=strtolower($columns[$t][$c]??'');$definition=preg_replace('/\b(bigint|int|tinyint|smallint|mediumint)\(\d+\)/','$1',$definition);if(!str_starts_with($definition,$type.' ')||($type==='bigint'&&str_starts_with($definition,'bigint unsigned')))$issues[]='PARENT_TYPE_MISMATCH';}
    $currency=$columns['currencies']['currency_code']??'';
    preg_match('/\bCOLLATE[= ]+(\w+)/i',$currency,$columnCollation);preg_match('/\bCOLLATE=(\w+)/i',$ddl['currencies']??'',$tableCollation);
    if(($columnCollation[1]??$tableCollation[1]??null)!=='utf8mb4_unicode_ci')$issues[]='CURRENCY_FK_COLLATION_MISMATCH';
    if(!preg_match('/UNIQUE KEY `[^`]+` \(`currency_code`\)/',$ddl['currencies']??''))$issues[]='CURRENCY_PARENT_UNIQUE_MISSING';
    $existingAdds=['items'=>['base_uom_id','inventory_quantity_version'],'branches'=>[],
      'shipments'=>['uom_cost_policy','uom_cost_basis_approved_by','uom_cost_basis_approved_at'],
      'inventory_lots'=>['received_base_qty','remaining_base_qty','sold_base_qty','unit_cost_per_base','uom_remaining_value']];
    $operational=['purchase_invoice_lines','sales_invoice_lines','inventory_lots','inventory_lot_movements','stock_movements','commercial_return_lines','sales_line_lot_sources','commercial_return_lot_sources','inventory_operation_lines','inventory_operation_lot_links','sales_quotation_lines','purchase_order_lines','opening_inventory_lines','shipment_items'];
    foreach($operational as$t){if(!isset($ddl[$t]))$issues[]='LEGACY_PARENT_TABLE_MISSING';$existingAdds[$t]=array_merge($existingAdds[$t]??[],['uom_version','uom_mapping_id','base_uom_id_snapshot','base_quantity']);}
    foreach($existingAdds as$t=>$cs)foreach($cs as$c)if(isset($columns[$t][$c]))$issues[]='EXISTING_UOM_COLUMN_REQUIRES_REVIEW';
    $catalog=json_decode(file_get_contents($root.'/ops/sulb-rc/migration-catalog.json'),true,512,JSON_THROW_ON_ERROR);$migrations=[];
    foreach($catalog['uom_order']as$m){$source=file_get_contents($root.'/'.$m['path']);if(!hash_equals($m['sha256'],hash('sha256',str_replace("\r\n","\n",$source))))throw new RuntimeException('MIGRATION_HASH_MISMATCH');preg_match_all('/CREATE TABLE\s+(\w+)/',$source,$created);$collision=(bool)array_intersect($created[1],array_keys($ddl));if($collision)$issues[]='EXISTING_UOM_TABLE_REQUIRES_REVIEW';$migrations[]=['sequence'=>$m['sequence'],'source_hash_verified'=>true,'created_table_collision'=>$collision,'qa_guard'=>str_contains($source,'UomStageAProof::guard()'),'production_approved'=>false];}
    if(count($migrations)!==14)throw new RuntimeException('MIGRATION_ORDER_INCOMPLETE');
    foreach(['inventory_lot_value_balances','shipment_cost_allocation_receipts']as$t)if(isset($ddl[$t]))$issues[]='EXISTING_L3_EVIDENCE_REQUIRES_REVIEW';
    return ['mode'=>'OFFLINE_PRIVATE_SCHEMA_CONTRACT','business_rows_read'=>false,'database_connections'=>0,'ddl_printed'=>false,'object_counts'=>$counts,
      'static_prerequisites'=>!$issues?'PASS':'BLOCKED','issue_counts'=>array_count_values($issues),'migrations'=>$migrations,
      'execution_rehearsal_passed'=>false,'production_executor_ready'=>false,'historical_validation_passed'=>false,
      'not_proven'=>['MySQL execution of all14 plus L3 dependencies on this exact schema','all historical duplicates/orphans and LOT sequence sources','legacy CHECK/default semantic interactions and data-dependent failures','financial Backfill FIFO LOT GL and restart/concurrency','private staging restore and security UAT'],
      'release_decision'=>'NO-GO'];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    $file=$argv[1]??'';$expected=$argv[2]??'';
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_file($file)||is_link($file)||!hash_equals($expected,hash_file('sha256',$file)))throw new RuntimeException('PRIVATE_INPUT_HASH_MISMATCH');
    $result=privateMigrationContract(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR),dirname(__DIR__,2));
    $result['schema_file_sha256']=$expected;
    $result['migration_count']=count($result['migrations']);
    $result['all_migration_hashes_verified']=!in_array(false,array_column($result['migrations'],'source_hash_verified'),true);
    unset($result['migrations']);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($result['static_prerequisites']==='PASS'?0:78);
}catch(Throwable $e){fwrite(STDERR,"STOP: private offline contract failed; do not send DDL or credentials.\n");exit(1);}
