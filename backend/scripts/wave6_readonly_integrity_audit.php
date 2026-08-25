<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
$output=$argv[1]??dirname(__DIR__,2).'/docs/erp-baseline/wave6-operational-accounting-integrity.json';
if(DB::connection()->getDriverName()==='mysql')DB::statement('SET TRANSACTION READ ONLY');

function rows(string $sql,array $bindings=[]): array{return array_map(static fn(object $r):array=>(array)$r,DB::select($sql,$bindings));}
function disposition(string $name,string $severity,array $row):string{
    if($severity==='INFO')return'INFORMATIONAL';
    if($name==='closed_shipments_without_closed_weight_card'&&(int)($row['company_id']??0)===4&&(int)($row['branch_id']??0)===6&&in_array((int)($row['id']??0),[2,3,4],true)&&($row['status']??null)==='APPROVED'&&($row['commercial_status']??null)==='DRAFT')return'ACCEPTED_LEGACY';
    if($name==='missing_required_account_mappings'&&(int)($row['company_id']??0)===5){$cid=5;$active=DB::table('journal_entries')->where('company_id',$cid)->exists()||DB::table('purchase_invoices')->where('company_id',$cid)->exists()||DB::table('sales_invoices')->where('company_id',$cid)->exists()||DB::table('stock_movements')->where('company_id',$cid)->exists();if(!$active)return'NON_OPERATIONAL_CONFIGURATION';}
    return'ACTION_REQUIRED';
}
function check(string $name,string $severity,string $sql,array $bindings=[]): array{$result=rows($sql,$bindings);$counts=['ACTION_REQUIRED'=>0,'ACCEPTED_LEGACY'=>0,'NON_OPERATIONAL_CONFIGURATION'=>0,'INFORMATIONAL'=>0];foreach($result as&$row){$row['disposition']=disposition($name,$severity,$row);$counts[$row['disposition']]++;}unset($row);$used=array_keys(array_filter($counts));return['name'=>$name,'severity'=>$severity,'disposition'=>count($used)===1?$used[0]:(count($used)>1?'MIXED':($severity==='INFO'?'INFORMATIONAL':'ACTION_REQUIRED')),'disposition_counts'=>$counts,'count'=>count($result),'sample'=>array_slice($result,0,100)];}

$checks=DB::transaction(function():array{
    $out=[];
    $out[]=check('unbalanced_posted_journals','CRITICAL',"SELECT j.id,j.company_id,j.entry_number,ROUND(SUM(l.debit),3) debit,ROUND(SUM(l.credit),3) credit FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id=j.id AND l.company_id=j.company_id WHERE j.status='POSTED' GROUP BY j.id,j.company_id,j.entry_number HAVING ABS(SUM(l.debit)-SUM(l.credit))>0.01");
    $out[]=check('duplicate_journal_sources','CRITICAL',"SELECT company_id,source_type,source_id,COUNT(*) occurrences FROM journal_entries WHERE source_type IS NOT NULL AND source_id IS NOT NULL AND status='POSTED' GROUP BY company_id,source_type,source_id HAVING COUNT(*)>1");
    $out[]=check('orphan_journal_lines','CRITICAL',"SELECT l.id,l.company_id,l.journal_entry_id FROM journal_entry_lines l LEFT JOIN journal_entries j ON j.id=l.journal_entry_id AND j.company_id=l.company_id WHERE j.id IS NULL");
    $out[]=check('journal_lines_foreign_company_accounts','CRITICAL',"SELECT l.id,l.company_id,l.account_id,a.company_id account_company_id FROM journal_entry_lines l JOIN accounts a ON a.id=l.account_id WHERE a.company_id<>l.company_id");
    $out[]=check('journals_invalid_financial_year','CRITICAL',"SELECT j.id,j.company_id,j.financial_year_id,(SELECT COUNT(*) FROM journal_entry_lines l WHERE l.journal_entry_id=j.id AND l.company_id=j.company_id AND (l.financial_year_id IS NULL OR l.financial_year_id<>j.financial_year_id)) invalid_line_count FROM journal_entries j LEFT JOIN financial_years y ON y.id=j.financial_year_id AND y.company_id=j.company_id WHERE j.financial_year_id IS NULL OR y.id IS NULL OR EXISTS(SELECT 1 FROM journal_entry_lines l WHERE l.journal_entry_id=j.id AND l.company_id=j.company_id AND (l.financial_year_id IS NULL OR l.financial_year_id<>j.financial_year_id))");
    foreach(['purchase_invoices','sales_invoices']as$table){$out[]=check("{$table}_duplicate_numbers",'CRITICAL',"SELECT company_id,invoice_number,COUNT(*) occurrences FROM {$table} WHERE invoice_number IS NOT NULL AND invoice_number<>'' GROUP BY company_id,invoice_number HAVING COUNT(*)>1");$out[]=check("{$table}_posted_without_journal",'CRITICAL',"SELECT id,company_id,invoice_number FROM {$table} WHERE document_status='POSTED' AND journal_entry_id IS NULL");}
    $out[]=check('negative_inventory_lots','CRITICAL',"SELECT id,company_id,branch_id,item_id,qty_remaining_kg FROM inventory_lots WHERE qty_remaining_kg < -0.001");
    $out[]=check('inventory_lot_movement_equation','CRITICAL',"SELECT l.id,l.company_id,l.item_id,l.qty_remaining_kg,COALESCE(SUM(CASE WHEN m.movement_type='IN' THEN m.qty_kg ELSE -m.qty_kg END),0) movement_balance FROM inventory_lots l LEFT JOIN inventory_lot_movements m ON m.inventory_lot_id=l.id AND m.company_id=l.company_id GROUP BY l.id,l.company_id,l.item_id,l.qty_remaining_kg HAVING ABS(movement_balance-l.qty_remaining_kg)>0.001");
    $out[]=check('orphan_stock_movement_items','CRITICAL',"SELECT m.id,m.company_id,m.item_id FROM stock_movements m LEFT JOIN items i ON i.id=m.item_id AND i.company_id=m.company_id WHERE i.id IS NULL");
    $out[]=check('orphan_stock_movement_lots','HIGH',"SELECT m.id,m.company_id,m.inventory_lot_id FROM stock_movements m LEFT JOIN inventory_lots l ON l.id=m.inventory_lot_id AND l.company_id=m.company_id WHERE m.inventory_lot_id IS NOT NULL AND l.id IS NULL");
    $out[]=check('cross_company_invoice_items','CRITICAL',"SELECT 'PURCHASE' source,l.id,l.company_id,l.item_id,i.company_id item_company_id FROM purchase_invoice_lines l JOIN items i ON i.id=l.item_id WHERE i.company_id<>l.company_id UNION ALL SELECT 'SALE',l.id,l.company_id,l.item_id,i.company_id FROM sales_invoice_lines l JOIN items i ON i.id=l.item_id WHERE i.company_id<>l.company_id");
    $out[]=check('cross_company_invoice_parties','CRITICAL',"SELECT 'PURCHASE' source,p.id,p.company_id,p.supplier_id party_id,s.company_id party_company_id FROM purchase_invoices p JOIN suppliers s ON s.id=p.supplier_id WHERE s.company_id<>p.company_id UNION ALL SELECT 'SALE',s.id,s.company_id,s.customer_id,c.company_id FROM sales_invoices s JOIN customers c ON c.id=s.customer_id WHERE c.company_id<>s.company_id");
    $branchTables=['users','cars','customers','suppliers','drivers','workers','shipments','weighbridge_cards','shipment_weights','purchase_invoices','sales_invoices','vouchers','expenses','inventory_lots','inventory_operations','journal_entries','commercial_returns','official_documents'];
    foreach($branchTables as$table)if(Schema::hasTable($table)&&Schema::hasColumn($table,'company_id')&&Schema::hasColumn($table,'branch_id'))$out[]=check("{$table}_invalid_branch",'CRITICAL',"SELECT t.id,t.company_id,t.branch_id FROM {$table} t LEFT JOIN branches b ON b.id=t.branch_id AND b.company_id=t.company_id WHERE t.branch_id IS NOT NULL AND b.id IS NULL");
    $out[]=check('closed_shipments_without_closed_weight_card','HIGH',"SELECT s.id,s.company_id,s.branch_id,s.shipment_number,s.status,s.commercial_status FROM shipments s WHERE s.status IN ('CLOSED','READY','APPROVED','INVOICED') AND NOT EXISTS (SELECT 1 FROM weighbridge_cards c WHERE c.shipment_id=s.id AND c.company_id=s.company_id AND c.status='CLOSED')");
    $out[]=check('invoiced_shipments_without_invoice_link','CRITICAL',"SELECT s.id,s.company_id,s.shipment_number FROM shipments s WHERE s.commercial_status='INVOICED' AND NOT EXISTS (SELECT 1 FROM invoice_shipment_links l WHERE l.shipment_id=s.id AND l.company_id=s.company_id)");
    $out[]=check('active_weight_history_multiple_rows','INFO',"SELECT company_id,weighbridge_card_id,effective_weight_type,COUNT(*) active_history_rows FROM shipment_weights WHERE cancelled_at IS NULL GROUP BY company_id,weighbridge_card_id,effective_weight_type HAVING COUNT(*)>1");
    $out[]=check('missing_required_account_mappings','CRITICAL',"SELECT c.id company_id,c.company_name,k.setting_key FROM companies c JOIN (SELECT 'VAT_INPUT_ACCOUNT' setting_key UNION ALL SELECT 'VAT_OUTPUT_ACCOUNT' UNION ALL SELECT 'INVENTORY_ACCOUNT' UNION ALL SELECT 'COGS_ACCOUNT' UNION ALL SELECT 'SALES_ACCOUNT' UNION ALL SELECT 'CUSTOMER_ACCOUNT' UNION ALL SELECT 'SUPPLIER_ACCOUNT') k LEFT JOIN accounting_settings s ON s.company_id=c.id AND s.setting_key=k.setting_key AND s.account_id IS NOT NULL WHERE c.is_active=1 AND s.id IS NULL");
    return$out;
});
$critical=array_sum(array_map(static fn(array$c):int=>in_array($c['severity'],['CRITICAL','HIGH'],true)?$c['count']:0,$checks));
$dispositions=['ACTION_REQUIRED'=>0,'ACCEPTED_LEGACY'=>0,'NON_OPERATIONAL_CONFIGURATION'=>0,'INFORMATIONAL'=>0];foreach($checks as$check)foreach($dispositions as$name=>$count)$dispositions[$name]+=$check['disposition_counts'][$name];
$report=['generated_at'=>now()->toIso8601String(),'mode'=>'MYSQL_READ_ONLY','database'=>DB::connection()->getDatabaseName(),'summary'=>['checks'=>count($checks),'critical_or_high_findings'=>$critical,'informational_findings'=>array_sum(array_map(static fn(array$c):int=>$c['severity']==='INFO'?$c['count']:0,$checks)),'dispositions'=>$dispositions],'checks'=>$checks];
$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create report directory.');file_put_contents($output,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));echo json_encode(['report'=>$output,'summary'=>$report['summary']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
