<?php
/** New populated-history chain test only. Never a production runner. */
require_once __DIR__.'/history-data-rehearsal.php';
$root=dirname(__DIR__,2);$owned=[];$admin=null;$exit=0;$checks=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('HISTORY_DATA_QA_ASSERTION');$checks++;};
try{
    $dir=getenv('SULB_HISTORY_QA_DATADIR')?:'';if(!preg_match('#/sulb-shipment-[a-f0-9]{32}$#D',rtrim(str_replace('\\','/',$dir),'/')))throw new RuntimeException('HISTORY_DATA_OWNED_DATADIR_REQUIRED');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>true];$admin=new PDO('mysql:host=127.0.0.1;port=3339','root','',$options);
    if(rtrim(str_replace('\\','/',$admin->query('SELECT @@datadir')->fetchColumn()),'/')!==rtrim(str_replace('\\','/',$dir),'/'))throw new RuntimeException('HISTORY_DATA_WRONG_SERVER');
    $source='sulb_ship_test_'.bin2hex(random_bytes(8));$target='sulb_ship_test_'.bin2hex(random_bytes(8));foreach([$source,$target]as$d){$admin->exec('CREATE DATABASE '.historyId($d).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$owned[]=$d;}
    foreach(['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_SOCKET'=>'','DB_DATABASE'=>$source,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_URL'=>'','SULB_SHIPMENT_TEST_DIR'=>$dir,'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'null']as$k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
    require $root.'/backend/vendor/autoload.php';$app=require $root.'/backend/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach(['UomStageAProof','QaEngineSchema','UomOperationalLockFixture']as$c)require_once $root.'/backend/tests/Support/'.$c.'.php';
    \Tests\Support\UomOperationalLockFixture::install();$db=\Illuminate\Support\Facades\DB::connection();
    foreach([[31,'REVENUE','CREDIT'],[32,'EXPENSE','DEBIT'],[33,'ASSET','DEBIT']]as[$id,$type,$side])$db->table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>'HIST-'.$id,'account_name'=>'Synthetic historical','account_type'=>$type,'normal_side'=>$side,'allow_posting'=>1,'allow_cost_center'=>0]);
    $db->table('items')->where('id',1)->update(['sales_account_id'=>31,'cogs_account_id'=>32]);$db->table('customers')->insert(['id'=>1,'company_id'=>1,'customer_name'=>'Synthetic historical','ledger_account_id'=>33,'scope_all_branches'=>1]);
    $lots=[];
    foreach([[1,1],[1,1],[2,3]]as$i=>[$company,$item]){
        $p=\Tests\Support\UomOperationalLockFixture::purchase($company,$item);$line=$db->table('purchase_invoice_lines')->where('purchase_invoice_id',$p)->value('id');
        $journal=$db->table('journal_entries')->insertGetId(['company_id'=>$company,'branch_id'=>$company,'financial_year_id'=>$company,'entry_date'=>'2026-10-14','entry_number'=>'HIST-P-'.$p,'source_type'=>'PURCHASE','source_id'=>$p,'status'=>'POSTED','currency_code'=>'SAR','exchange_rate'=>1,'created_by'=>$company]);
        foreach([[$company*10+1,'100','0'],[$company*10+2,'0','100']]as[$account,$debit,$credit])$db->table('journal_entry_lines')->insert(['company_id'=>$company,'branch_id'=>$company,'financial_year_id'=>$company,'journal_entry_id'=>$journal,'account_id'=>$account,'debit'=>$debit,'credit'=>$credit]);
        $db->table('purchase_invoices')->where('id',$p)->update(['document_status'=>'POSTED','journal_entry_id'=>$journal]);
        $suffix=$company===1?$i+1:1;$lot=$db->table('inventory_lots')->insertGetId(['company_id'=>$company,'branch_id'=>$company,'item_id'=>$item,'purchase_invoice_id'=>$p,'purchase_invoice_line_id'=>$line,'lot_number'=>'LOT-2026-'.str_pad((string)$suffix,6,'0',STR_PAD_LEFT),'source_type'=>'PURCHASE','source_id'=>$p,'received_at'=>'2026-10-14 10:0'.$i.':00','qty_received_kg'=>'10','qty_remaining_kg'=>'10','base_cost'=>'100','total_cost'=>'100','unit_cost_per_kg'=>'10','created_by'=>$company]);$lots[]=$lot;
        $db->table('inventory_lot_movements')->insert(['company_id'=>$company,'branch_id'=>$company,'inventory_lot_id'=>$lot,'item_id'=>$item,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>$p,'movement_at'=>'2026-10-14','qty_kg'=>'10','unit_cost_per_kg'=>'10','total_cost'=>'100']);
        $db->table('stock_movements')->insert(['company_id'=>$company,'branch_id'=>$company,'item_id'=>$item,'inventory_lot_id'=>$lot,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>$p,'journal_entry_id'=>$journal,'movement_date'=>'2026-10-14','qty'=>'10','qty_kg'=>'10','unit_cost'=>'10','unit_cost_per_kg'=>'10','total_cost'=>'100']);
    }
    $sale=$db->table('sales_invoices')->insertGetId(['company_id'=>1,'branch_id'=>1,'customer_id'=>1,'invoice_number'=>'HIST-SALE','invoice_date'=>'2026-10-15','document_status'=>'POSTED','currency_code'=>'SAR','exchange_rate'=>1,'total_amount'=>'60','base_total_amount'=>'60','total_before_vat'=>'60','base_total_before_vat'=>'60']);
    $sj=$db->table('journal_entries')->insertGetId(['company_id'=>1,'branch_id'=>1,'financial_year_id'=>1,'entry_date'=>'2026-10-15','entry_number'=>'HIST-SALE','source_type'=>'SALE','source_id'=>$sale,'status'=>'POSTED','currency_code'=>'SAR','exchange_rate'=>1]);
    foreach([[33,'60','0'],[31,'0','60'],[32,'40','0'],[11,'0','40']]as[$account,$debit,$credit])$db->table('journal_entry_lines')->insert(['company_id'=>1,'branch_id'=>1,'journal_entry_id'=>$sj,'account_id'=>$account,'debit'=>$debit,'credit'=>$credit]);
    $db->table('sales_invoices')->where('id',$sale)->update(['journal_entry_id'=>$sj]);
    $sl=$db->table('sales_invoice_lines')->insertGetId(['company_id'=>1,'sales_invoice_id'=>$sale,'item_id'=>1,'qty_kg'=>'4','qty'=>'4','quantity'=>'4','unit_code'=>'KG','unit_factor_to_base'=>'1','price_unit'=>'KG','unit_price'=>'15','unit_price_per_kg'=>'15','base_total_before_vat'=>'60','total_before_vat'=>'60','total_after_vat'=>'60','base_total_after_vat'=>'60','item_type_snapshot'=>'STOCK','track_inventory_snapshot'=>1]);
    $db->table('sales_line_lot_sources')->insert(['company_id'=>1,'branch_id'=>1,'sales_invoice_line_id'=>$sl,'inventory_lot_id'=>$lots[0],'qty_kg'=>'4','unit_cost_per_kg'=>'10','total_cost'=>'40']);
    $db->table('inventory_lots')->where('id',$lots[0])->update(['qty_remaining_kg'=>'6','qty_sold_kg'=>'4']);
    $db->table('inventory_lot_movements')->insert(['company_id'=>1,'branch_id'=>1,'inventory_lot_id'=>$lots[0],'item_id'=>1,'movement_type'=>'OUT','source_type'=>'SALE','source_id'=>$sale,'movement_at'=>'2026-10-15','qty_kg'=>'4','unit_cost_per_kg'=>'10','total_cost'=>'40']);
    $db->table('stock_movements')->insert(['company_id'=>1,'branch_id'=>1,'item_id'=>1,'inventory_lot_id'=>$lots[0],'movement_type'=>'OUT','source_type'=>'SALE','source_id'=>$sale,'journal_entry_id'=>$sj,'movement_date'=>'2026-10-15','qty'=>'4','qty_kg'=>'4','unit_cost'=>'10','unit_cost_per_kg'=>'10','total_cost'=>'40']);
    foreach([1=>3,2=>2]as$c=>$n)$db->table('sulb_document_sequences')->where(['company_id'=>$c,'document_type'=>'INVENTORY_LOT'])->update(['next_number'=>$n]);
    $db->unprepared('CREATE TABLE qa_history_newer(id INT PRIMARY KEY,amount DECIMAL(18,3)) ENGINE=InnoDB');$db->table('qa_history_newer')->insert(['id'=>1,'amount'=>'180.001']);
    $sourcePdo=$db->getPdo();$work=$root.'/backend/storage/framework/testing/history-data-qa-'.bin2hex(random_bytes(8));mkdir($work,0700,true);$key=random_bytes(32);
    $manifest=historyCapture($sourcePdo,$work.'/snapshot.enc',$key,104857600,function()use($admin,$source){$admin->exec('INSERT INTO '.historyId($source).'.qa_history_newer VALUES(2,0.001)');},['page_rows'=>1]);
    $targetPdo=new PDO('mysql:host=127.0.0.1;port=3339;dbname='.$target.';charset=utf8mb4','root','',$options);$proof=historyRestore($targetPdo,$target,$dir,$work.'/snapshot.enc',$key,$manifest);$assert($proof['row_digests_match']);$targetPdo=null;$db->disconnect();$sourcePdo=null;
    // Socket empty is TCP only in this explicit, datadir-verified synthetic QA process.
    $result=historyDataRehearsal($root,'',$dir,$target,$manifest,$work.'/details-private.json');
    $assert($result['historical_status']==='PASS');$assert($result['backfill_companies_applied']===2);$assert($result['rollback']==='PASS');$assert($result['idempotency']==='PASS');$assert($result['legacy_financial_columns_preserved']);
    $assert($admin->query('SELECT SUM(amount) FROM '.historyId($source).'.qa_history_newer')->fetchColumn()==='180.002');
    $assert($admin->query('SELECT SUM(amount) FROM '.historyId($target).'.qa_history_newer')->fetchColumn()==='180.001');
    echo json_encode(['new_populated_history_chain'=>'PASS','checks'=>$checks,'synthetic_only'=>true,'uom_migrations'=>14,'dependencies'=>4,'production_connected'=>false,'source_newer_financial_marker_preserved'=>true,'historical_production_validation'=>false,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'HISTORY_DATA_QA_FAILED CHECKS='.$checks.' LINE='.$e->getLine().' CODE='.($e instanceof PDOException?(int)($e->errorInfo[1]??0):0).' REASON='.(preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'PRIVATE_EXCEPTION')."\n");$exit=1;}
finally{if(class_exists(\Illuminate\Support\Facades\DB::class))\Illuminate\Support\Facades\DB::disconnect();if($admin)foreach($owned as$d)if(preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$d))$admin->exec('DROP DATABASE IF EXISTS '.historyId($d));}
exit($exit);
