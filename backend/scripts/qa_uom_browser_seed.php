<?php
/** Explicit synthetic browser fixture; no production/original connection or reuse of an existing schema. */
require __DIR__.'/../vendor/autoload.php';
$directory=getenv('SULB_SHIPMENT_TEST_DIR');
if(!$directory||!preg_match('/sulb-shipment-[a-f0-9]{32}$/D',$directory))throw new RuntimeException('Owned QA directory required');
$pdo=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$pdo->query('SELECT @@datadir')->fetchColumn();
if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',$directory),'/'))throw new RuntimeException('Wrong QA server');
if(!str_starts_with($pdo->query('SELECT VERSION()')->fetchColumn(),'10.4.32-MariaDB'))throw new RuntimeException('QA version mismatch');
$prepareUat=($argv[1]??'')==='--prepare-uat';
$verifyUat=($argv[1]??'')==='--verify-uat';
$prepareAdapters=($argv[1]??'')==='--prepare-base-adapters';
$prepareLot=($argv[1]??'')==='--prepare-lot-upgrade';
$preparePolicy=($argv[1]??'')==='--prepare-approved-policy';
$verifyPolicy=($argv[1]??'')==='--verify-approved-policy';
$prepareMixed=($argv[1]??'')==='--prepare-mixed-recipe';
$prepareExtra=($argv[1]??'')==='--prepare-extra-costs';
$prepareJournalViewer=($argv[1]??'')==='--prepare-journal-viewer';
$complete=($argv[1]??'')==='--complete-existing'||$prepareUat||$verifyUat||$prepareAdapters||$prepareLot||$preparePolicy||$verifyPolicy||$prepareMixed||$prepareExtra||$prepareJournalViewer;
$schema=$complete?(string)getenv('SULB_BROWSER_QA_SCHEMA'):'sulb_ship_test_'.bin2hex(random_bytes(8));
if(!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Owned synthetic schema required');
if(!$complete)$pdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach(['APP_ENV'=>'testing','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3339','DB_DATABASE'=>$schema,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','APP_CONFIG_CACHE'=>sys_get_temp_dir().'/qa-browser-no-cache-'.$schema.'.php','SULB_UOM_OPERATIONAL_ENABLED'=>'true','SULB_INVENTORY_TRANSFER_ROOT_PROTOCOL_ENABLED'=>'true'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../tests/Support/OperationalUomFixture.php';
if($complete){
    require_once __DIR__.'/../tests/Support/UomStageAProof.php';Tests\Support\UomStageAProof::guard();
    if(Illuminate\Support\Facades\DB::table('users')->where('id',1)->value('username')!=='uom.qa')throw new RuntimeException('Synthetic fixture identity required');
    if($prepareJournalViewer){
        config(['uom.schema_install_approved'=>true]);(require __DIR__.'/../database/uom-migrations/2026_10_26_000013_manual_journal_permissions.php')->up();$db=Illuminate\Support\Facades\DB::class;
        if(!$db::table('users')->where('username','uom.journal.viewer.qa')->exists()){
            $db::transaction(function()use($db){$permission=$db::table('permissions')->where('permission_code','statements.view')->value('id');if(!$permission)$permission=$db::table('permissions')->insertGetId(['permission_code'=>'statements.view','permission_name'=>'QA read journals','permission_scope'=>'COMPANY']);$role=$db::table('roles')->insertGetId(['role_code'=>'QA_JOURNAL_VIEWER','role_name'=>'QA journal viewer']);$user=$db::table('users')->insertGetId(['company_id'=>1,'branch_id'=>1,'name'=>'Synthetic journal viewer','username'=>'uom.journal.viewer.qa','password'=>Illuminate\Support\Facades\Hash::make('Synthetic-QA-Only-2026!')]);$db::table('user_roles')->insert(['company_id'=>1,'user_id'=>$user,'role_id'=>$role]);$db::table('role_permissions')->insert(['company_id'=>1,'role_id'=>$role,'permission_id'=>$permission]);});
        }echo 'OWNED_QA_READ_ONLY_JOURNAL_VIEWER_PREPARED_NO_FINANCIAL_GRANTS'.PHP_EOL;exit(0);
    }
    if($prepareExtra){config(['uom.schema_install_approved'=>true]);(require __DIR__.'/../database/uom-migrations/2026_10_26_000012_processing_cost_sources.php')->up();Illuminate\Support\Facades\DB::table('accounting_settings')->insertOrIgnore(['company_id'=>1,'setting_key'=>'ACCRUED_EXPENSE_ACCOUNT','account_id'=>12]);echo 'OWNED_QA_EXTRA_COST_SCHEMA_PREPARED_NO_COST_POSTED'.PHP_EOL;exit(0);}
    if($prepareMixed){
        $db=Illuminate\Support\Facades\DB::class;
        require_once __DIR__.'/../tests/Support/HistoricalLotBackfill.php';Tests\Support\HistoricalLotBackfill::install();config(['inventory_value.enabled'=>true]);
        Illuminate\Support\Facades\Auth::login(App\Models\User::findOrFail(1));
        $item=$db::table('items')->where('company_id',1)->where('item_code','QA-LEGACY-RECIPE')->first();
        if(!$item){$id=$db::table('items')->insertGetId(['company_id'=>1,'item_code'=>'QA-LEGACY-RECIPE','item_name'=>'Synthetic legacy KG recipe stock','inventory_account_id'=>11]);
            $service=app(App\Services\EnterpriseInvoiceService::class);$invoice=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-26','currency_code'=>'SAR','items'=>[['item_id'=>$id,'qty_kg'=>'5','unit_price'=>'10','price_unit'=>'KG','vat_percent'=>0]]],1,1,1);$service->post('PURCHASE',1,$invoice,1,1);
        }
        echo 'OWNED_QA_SYNTHETIC_LEGACY_STOCK_PREPARED_NO_RECIPE_CREATED'.PHP_EOL;exit(0);
    }
    if($verifyPolicy){
        $db=Illuminate\Support\Facades\DB::class;$commands=$db::table('uom_processing_commands')->where('company_id',1)->get(['operation_id','status','recipe_id','payload_hash','result_hash']);
        if(!$commands->contains(fn($row)=>$row->status==='POSTED'))throw new RuntimeException('No browser processing was posted');
        $lots=$db::table('inventory_lots as l')->leftJoin('uom_units as u','u.id','=','l.base_uom_id_snapshot')->where('l.company_id',1)->orderBy('l.id')->get(['l.id','l.item_id','l.uom_version','u.code','l.remaining_base_qty','l.uom_remaining_value','l.qty_remaining_kg','l.source_type','l.source_id']);
        $lotValue='0.000';foreach($lots as $lot){$value=$lot->uom_remaining_value;if($lot->uom_version==='LEGACY_KG'){$value=$db::table('inventory_lot_value_balances')->where('company_id',1)->where('inventory_lot_id',$lot->id)->value('remaining_value');if($value===null)throw new RuntimeException('Unqualified legacy browser LOT');}$lot->verified_remaining_value=$value;$lotValue=bcadd($lotValue,$value,3);}
        $gl=$db::table('journal_entry_lines')->where('company_id',1)->where('account_id',11)->selectRaw('COALESCE(SUM(debit-credit),0) v')->first()->v;
        if(bccomp($lotValue,$gl,3)!==0)throw new RuntimeException('QA browser processing LOT GL mismatch');
        echo json_encode(['mode'=>'READ_ONLY_VERIFY','commands'=>$commands,'lots'=>$lots,'lot_value'=>$lotValue,'inventory_gl'=>$gl],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit(0);
    }
    if($preparePolicy){
        config(['uom.schema_install_approved'=>true]);
        foreach(['2026_10_25_000007_shipment_snapshots.php','2026_10_25_000008_processing_recipes.php','2026_10_25_000009_processing_commands.php'] as $file)(require __DIR__.'/../database/uom-migrations/'.$file)->up();
        foreach(['inventory.view','inventory.process','inventory.process.post','shipments.view','shipments.create','shipments.update','shipments.ready','shipments.cost'] as $code)Illuminate\Support\Facades\DB::table('permissions')->insertOrIgnore(['permission_code'=>$code,'permission_name'=>$code,'permission_scope'=>'COMPANY']);
        foreach(['processing','shipments','weighbridge'] as $feature)Illuminate\Support\Facades\DB::table('plan_features')->insertOrIgnore(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
        Illuminate\Support\Facades\Auth::login(App\Models\User::findOrFail(1));
        $kg=Illuminate\Support\Facades\DB::table('uom_units')->where('code','KG')->first();
        $unit=$kg?->id??(1+(int)Illuminate\Support\Facades\DB::table('uom_units')->max('id'));
        if(!$kg)Illuminate\Support\Facades\DB::table('uom_units')->insert(['id'=>$unit,'code'=>'KG','name_ar'=>'KG','dimension'=>'MASS','standard_factor'=>'1','allow_fractional'=>1,'kind'=>'STANDARD']);
        if(Illuminate\Support\Facades\DB::table('items')->where('company_id',1)->where('id',2)->value('inventory_quantity_version')!=='BASE_V1'){
            app(App\Services\Uom\ItemUnitService::class)->registerNew(1,2,(int)$unit);app(App\Services\Uom\ItemUnitService::class)->reviseMapping(1,2,(int)$unit,'1');
        }
        echo 'OWNED_QA_POLICY_SCHEMAS_AND_EMPTY_KG_ITEM_PREPARED_NO_RECIPE_OR_STOCK_CREATED'.PHP_EOL;exit(0);
    }
    if($prepareLot){
        require_once __DIR__.'/../tests/Support/HistoricalLotBackfill.php';
        Tests\Support\HistoricalLotBackfill::install();
        echo 'OWNED_QA_LOT_SCHEMA_PREPARED_NO_BACKFILL_APPLIED'.PHP_EOL;exit(0);
    }
    if($prepareAdapters){
        config(['uom.schema_install_approved'=>true]);
        foreach(['2026_10_25_000004_commercial_snapshots.php','2026_10_25_000005_opening_snapshots.php','2026_10_25_000006_base_adjustments.php'] as $file)(require __DIR__.'/../database/uom-migrations/'.$file)->up();
        echo 'OWNED_QA_BASE_ADAPTER_SCHEMAS_PREPARED'.PHP_EOL;exit(0);
    }
    if($verifyUat){
        $result=[];
        foreach([1,2] as $company){
            $lots=Illuminate\Support\Facades\DB::table('inventory_lots')->where('company_id',$company)->selectRaw('COALESCE(SUM(remaining_base_qty),0) quantity, COALESCE(SUM(uom_remaining_value),0) value')->first();
            $gl=Illuminate\Support\Facades\DB::table('journal_entry_lines')->where('company_id',$company)->where('account_id',$company*10+1)->selectRaw('COALESCE(SUM(debit-credit),0) value')->first();
            $result[$company]=['remaining_base_quantity'=>$lots->quantity,'lot_value'=>$lots->value,'inventory_gl'=>$gl->value,
                'returns'=>Illuminate\Support\Facades\DB::table('commercial_returns')->where('company_id',$company)->get(['return_number','document_status','total_amount'])];
            if(bccomp((string)$lots->value,(string)$gl->value,3)!==0)throw new RuntimeException('Synthetic browser LOT/GL mismatch');
        }
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit(0);
    }
    if($prepareUat){
        (require __DIR__.'/../database/migrations/2026_08_31_000026_add_print_branding_profile_to_company_settings.php')->up();
        (require __DIR__.'/../database/migrations/2026_09_21_000027_create_central_print_documents.php')->up();
        Illuminate\Support\Facades\DB::table('companies')->where('id',2)->update(['company_name'=>'Foreign synthetic QA company']);
        Illuminate\Support\Facades\DB::table('users')->where('id',2)->update(['username'=>'uom.foreign.qa','password'=>Illuminate\Support\Facades\Hash::make('Synthetic-QA-Only-2026!')]);
        Illuminate\Support\Facades\DB::table('user_roles')->insert(['user_id'=>2,'role_id'=>1,'company_id'=>2]);
        Illuminate\Support\Facades\DB::table('subscriptions')->insert(['company_id'=>2,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
        echo 'SYNTHETIC_PRINT_AND_FOREIGN_ACTOR_PREPARED'.PHP_EOL;exit(0);
    }
    foreach(['CUSTOMER_ACCOUNT'=>33,'SALES_ACCOUNT'=>31,'COGS_ACCOUNT'=>32] as $key=>$account)Illuminate\Support\Facades\DB::table('accounting_settings')->insertOrIgnore(['company_id'=>1,'setting_key'=>$key,'account_id'=>$account]);
    echo 'SYNTHETIC_METADATA_SETTINGS_PREPARED'.PHP_EOL;exit(0);
}
Tests\Support\OperationalUomFixture::install();
foreach(['CUSTOMER_ACCOUNT'=>33,'SALES_ACCOUNT'=>31,'COGS_ACCOUNT'=>32] as $key=>$account)Illuminate\Support\Facades\DB::table('accounting_settings')->insert(['company_id'=>1,'setting_key'=>$key,'account_id'=>$account]);
Illuminate\Support\Facades\DB::table('users')->where('id',1)->update(['username'=>'uom.qa','password'=>Illuminate\Support\Facades\Hash::make('Synthetic-QA-Only-2026!'),'name'=>'Synthetic UOM QA']);
Illuminate\Support\Facades\DB::table('plans')->insert(['id'=>99,'plan_code'=>'UOM-BROWSER','plan_name'=>'Synthetic local QA']);
Illuminate\Support\Facades\DB::table('subscriptions')->insert(['company_id'=>1,'plan_id'=>99,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'status'=>'ACTIVE']);
foreach(['purchases','sales','inventory','accounting','processing','reports'] as $feature)Illuminate\Support\Facades\DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>$feature,'is_enabled'=>1]);
$service=app(App\Services\EnterpriseInvoiceService::class);
$purchase=$service->saveDraft('PURCHASE',['supplier_id'=>1,'invoice_date'=>'2026-10-24','currency_code'=>'SAR','items'=>[['item_id'=>1,'unit_code'=>'CARTON','quantity'=>'1','unit_price'=>'120','price_unit'=>'UNIT','vat_percent'=>0]]],1,1,1);
$service->post('PURCHASE',1,$purchase,1,1);
echo json_encode(['QA_SCHEMA'=>$schema,'QA_DATADIR'=>$actual,'QA_PORT'=>3339,'QA_USERNAME'=>'uom.qa','QA_PASSWORD'=>'Synthetic-QA-Only-2026!','QA_PURCHASE_ID'=>$purchase],JSON_UNESCAPED_SLASHES).PHP_EOL;
