<?php
/** Read existing encrypted archive only. No PDO, source connection, restore or account mutation. */
require_once __DIR__.'/history-archive.php';
const ACCOUNT_REVIEW_SHA='8ede8362220b5bba9d794272f4ddcc2d4837dc8e8b67c91b36b43c7cfd3d8565';
function accountEvidenceRows(string $file,string $key):Generator {
    $table=null;$columns=[];
    foreach(historyRecords($file,$key)as$r){
        if($r['kind']==='table'){$table=$r['name'];$columns=array_column($r['definition']['columns'],'COLUMN_NAME');}
        elseif($r['kind']==='row'){$row=[];foreach($columns as$i=>$c){$v=$r['values'][$i];$row[$c]=$v===null?null:base64_decode($v,true);}yield[$table,$row];}
    }
}
function accountEvidenceReport(array $cases,array $data):array {
    $out=[];
    foreach($cases as$case){$company=(string)$case['company_id'];$lot=$data['inventory_lots'][$company.':'.$case['lot_id']]??null;
        $item=$lot?($data['items'][$company.':'.$lot['item_id']]??null):null;$candidates=[];$seen=[];$cycle=false;
        $add=function($source,$account)use(&$candidates,$company,$data){if(!(int)$account)return;$a=$data['accounts'][$company.':'.$account]??null;$candidates[]=['source'=>$source,'account_id'=>(int)$account,'exists_same_company'=>$a!==null,'active'=>isset($a['is_active'])?(int)$a['is_active']===1:null,'postable'=>isset($a['allow_posting'],$a['is_group'])?((int)$a['allow_posting']===1&&(int)$a['is_group']===0):null,'account_type'=>$a?preg_replace('/[^A-Z_]/','',strtoupper($a['account_type']??'')):null];};
        $add('ITEM',$item['inventory_account_id']??null);$cat=(int)($item['category_id']??0);
        while($cat){if(isset($seen[$cat])){$cycle=true;break;}$seen[$cat]=true;$c=$data['item_categories'][$company.':'.$cat]??$data['item_categories']['GLOBAL:'.$cat]??null;if(!$c)break;$add('CATEGORY:'.$cat,$c['inventory_account_id']??null);$cat=(int)($c['parent_id']??0);}
        $g=$data['item_groups'][$company.':'.($item['group_id']??'')]??null;$add('GROUP',$g['inventory_account_id']??null);
        $add('COMPANY_SETTING',$data['accounting_settings'][$company.':INVENTORY_ACCOUNT']['account_id']??null);
        $journalIds=[];$purchase=$data['purchase_invoices'][$company.':'.($lot['purchase_invoice_id']??'')]??null;
        if($purchase&&($purchase['journal_entry_id']??null))$journalIds[(string)$purchase['journal_entry_id']]='PURCHASE_HEADER';
        if($lot&&($lot['source_type']??'')==='INVENTORY_OPERATION'){$op=$data['inventory_operations'][$company.':'.$lot['source_id']]??null;if($op&&($op['journal_entry_id']??null))$journalIds[(string)$op['journal_entry_id']]='OPERATION_HEADER';}
        $gl=[];foreach($journalIds as$id=>$source){$head=$data['journal_entries'][$company.':'.$id]??null;foreach($data['journal_entry_lines'][$company.':'.$id]??[]as$line){if((string)$line['branch_id']!==(string)($lot['branch_id']??''))continue;$gl[]=['source'=>$source,'journal_id'=>(int)$id,'posted'=>($head['status']??null)==='POSTED','account_id'=>(int)$line['account_id'],'debit_present'=>$line['debit_present'],'credit_present'=>$line['credit_present'],'exists_same_company'=>isset($data['accounts'][$company.':'.$line['account_id']])];}}
        $out[]=['company_id'=>(int)$company,'lot_id'=>(int)$case['lot_id'],'branch_id'=>$lot?(int)$lot['branch_id']:null,'item_id'=>$lot?(int)$lot['item_id']:null,'reason'=>!$lot?'LOT_NOT_FOUND':(!$item?'ITEM_NOT_FOUND':(!(int)($item['inventory_account_id']??0)?'DIRECT_ITEM_ACCOUNT_UNSET':'DIRECT_ACCOUNT_PRESENT_REVIEW_REQUIRED')),'configuration_candidates'=>$candidates,'category_cycle'=>$cycle,'source_journal_evidence'=>$gl,'mapping_approved'=>false,'required_evidence'=>'ACCOUNTANT_APPROVED_COMPANY_BRANCH_ITEM_ACCOUNT_MAPPING_CORROBORATED_BY_POSTED_SOURCE_GL_AND_FULL_LOT_GL_RECONCILIATION'];
    }
    return ['account_exception_review'=>'REVIEW_REQUIRED','exception_count'=>count($out),'cases'=>$out,'production_connected'=>false,'business_values_exported'=>false,'mapping_applied'=>false,'release_decision'=>'NO-GO'];
}
function accountPrivate(string $p):void {if(is_link($p)||!is_file($p)||fileowner($p)!==0||(fileperms($p)&0077)!==0)throw new RuntimeException('PRIVATE_EVIDENCE_REQUIRED');}
function accountEvidenceCli():int {
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
    try{
        if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0||!posix_setrlimit(POSIX_RLIMIT_CORE,0,0))throw new RuntimeException('LINUX_ROOT_NO_CORE_REQUIRED');
        $matches=[];foreach(glob('/home/mgye-api-sulb/sulb-history-restore-*',GLOB_ONLYDIR)as$dir){
            if(!preg_match('#^/home/mgye-api-sulb/sulb-history-restore-[a-f0-9]{16}$#D',$dir)||is_link($dir)||realpath($dir)!==$dir||fileowner($dir)!==0||(fileperms($dir)&0077)!==0)continue;
            $detail=$dir.'/historical-details-private.json';$mf=$dir.'/manifest-private.json';if(!is_file($detail)||!is_file($mf))continue;accountPrivate($detail);accountPrivate($mf);
            if(filesize($detail)>33554432||filesize($mf)>4194304)throw new RuntimeException('EVIDENCE_SIZE_LIMIT');
            $m=json_decode(file_get_contents($mf),true,512,JSON_THROW_ON_ERROR);if(($m['readiness_sha256']??null)!==ACCOUNT_REVIEW_SHA)continue;
            $d=json_decode(file_get_contents($detail),true,512,JSON_THROW_ON_ERROR);$cases=[];foreach($d['companies']??[]as$c=>$v)foreach($v['backfill']['exceptions']??[]as$e)if(($e['reason']??null)==='INVENTORY_ACCOUNT_EVIDENCE_REQUIRED')$cases[]=['company_id'=>(int)$c,'lot_id'=>(int)$e['lot_id']];
            if(count($cases)===6)$matches[]=[$dir,$m,$cases,filemtime($detail)];
        }
        if(!$matches)throw new RuntimeException('SIX_EXCEPTION_EVIDENCE_NOT_FOUND');usort($matches,fn($a,$b)=>$b[3]<=>$a[3]);[$dir,$manifest,$cases]=$matches[0];
        foreach(['snapshot.enc','snapshot.key']as$f)accountPrivate($dir.'/'.$f);$key=file_get_contents($dir.'/snapshot.key');historyVerify($dir.'/snapshot.enc',$key,$manifest);
        $lotKeys=[];foreach($cases as$c)$lotKeys[$c['company_id'].':'.$c['lot_id']]=true;
        $data=[];$fields=['inventory_lots'=>['id','company_id','branch_id','item_id','purchase_invoice_id','source_type','source_id'],'items'=>['id','company_id','inventory_account_id','category_id','group_id'],'item_categories'=>['id','company_id','inventory_account_id','parent_id'],'item_groups'=>['id','company_id','inventory_account_id'],'accounting_settings'=>['company_id','setting_key','account_id'],'accounts'=>['id','company_id','account_type','is_active','is_postable','allow_posting','is_group','branch_id'],'purchase_invoices'=>['id','company_id','journal_entry_id'],'inventory_operations'=>['id','company_id','journal_entry_id'],'journal_entries'=>['id','company_id','status']];
        $count=0;foreach(accountEvidenceRows($dir.'/snapshot.enc',$key)as[$table,$r]){if(!isset($fields[$table]))continue;if($table==='inventory_lots'&&!isset($lotKeys[$r['company_id'].':'.$r['id']]))continue;if($table==='accounting_settings'&&$r['setting_key']!=='INVENTORY_ACCOUNT')continue;if(++$count>100000)throw new RuntimeException('EVIDENCE_MEMORY_BUDGET');$k=($r['company_id']??'GLOBAL').':'.($table==='accounting_settings'?$r['setting_key']:$r['id']);$data[$table][$k]=array_intersect_key($r,array_flip($fields[$table]));}
        $wanted=[];foreach($cases as$c){$l=$data['inventory_lots'][$c['company_id'].':'.$c['lot_id']]??null;if(!$l)continue;foreach(['purchase_invoices'=>($l['purchase_invoice_id']??''),'inventory_operations'=>(($l['source_type']??'')==='INVENTORY_OPERATION'?$l['source_id']:'')]as$t=>$id){$h=$data[$t][$c['company_id'].':'.$id]??null;if($h&&($h['journal_entry_id']??null))$wanted[$c['company_id'].':'.$h['journal_entry_id']]=true;}}
        foreach(accountEvidenceRows($dir.'/snapshot.enc',$key)as[$t,$r])if($t==='journal_entry_lines'&&isset($wanted[$r['company_id'].':'.$r['journal_entry_id']])){if(++$count>100000)throw new RuntimeException('EVIDENCE_MEMORY_BUDGET');$data[$t][$r['company_id'].':'.$r['journal_entry_id']][]=['branch_id'=>$r['branch_id'],'account_id'=>$r['account_id'],'debit_present'=>bccomp($r['debit'],'0',3)>0,'credit_present'=>bccomp($r['credit'],'0',3)>0];}
        sodium_memzero($key);$report=accountEvidenceReport($cases,$data);$report['archive_sha256']=$manifest['file_sha256'];$report['evidence_sha256']=hash_file('sha256',$dir.'/historical-details-private.json');$path=$dir.'/account-review-'.bin2hex(random_bytes(8)).'.json';historyPrivateJson($path,$report);$report['private_report']=$path;echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;return 0;
    }catch(Throwable $e){echo json_encode(['account_exception_review'=>'BLOCKED','reason'=>preg_match('/^[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'PRIVATE_REVIEW_FAILURE','production_connected'=>false,'release_decision'=>'NO-GO']).PHP_EOL;return 78;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(accountEvidenceCli());
