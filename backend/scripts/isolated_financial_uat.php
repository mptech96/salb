<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use App\Services\Provisioning\CompanyProvisioningService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';

$application=require dirname(__DIR__).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$mode=$argv[1]??'--preflight';

function refreshUatManifest(int $companyId): string
{
    $path=dirname(__DIR__,2).'/docs/erp-baseline/uat-financial-created-records.json';
    $tables=['companies','users','user_roles','branches','company_settings','subscriptions','subscription_entitlement_snapshots','company_entitlement_overrides','financial_years','cost_centers','accounts','accounting_settings','company_currencies','financial_accounts','branch_financial_settings','company_provisioning_requests','entity_addresses','suppliers','supplier_branches','customers','customer_branches','items','item_groups','item_categories','cars','drivers','tax_codes','purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','inventory_lots','inventory_lot_movements','stock_movements','sales_line_lot_sources','commercial_returns','commercial_return_lines','commercial_return_lot_sources','expenses','vouchers','journal_entries','journal_entry_lines','audit_logs','support_sessions','user_permission_overrides','document_sequences'];
    $records=[];
    foreach($tables as$table){
        if(!Schema::hasTable($table)||!Schema::hasColumn($table,'id'))continue;
        $query=DB::table($table);
        if($table==='companies')$query->where('id',$companyId);
        elseif(Schema::hasColumn($table,'company_id'))$query->where('company_id',$companyId);
        else continue;
        $records[$table]=$query->orderBy('id')->pluck('id')->map(static fn($id):int=>(int)$id)->all();
    }
    $userIds=$records['users']??[];
    if($userIds&&Schema::hasTable('personal_access_tokens'))$records['personal_access_tokens']=DB::table('personal_access_tokens')->whereIn('tokenable_id',$userIds)->orderBy('id')->pluck('id')->map(static fn($id):int=>(int)$id)->all();
    $manifest=['generated_at'=>now()->toIso8601String(),'purpose'=>'ISOLATED_FINANCIAL_UAT','cleanup_authorized'=>false,'company_id'=>$companyId,'company_name'=>'UAT_SULB_FINANCIAL','records'=>$records];
    file_put_contents($path,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $path;
}

function uatRequest(string $token,string $method,string $path,array $payload=[]): array
{
    app('auth')->forgetGuards();
    $request=Request::create('/api/'.$path,$method,[],[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$token,'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],json_encode($payload,JSON_THROW_ON_ERROR));
    $response=app(HttpKernel::class)->handle($request);
    $decoded=json_decode($response->getContent(),true);
    return ['http_status'=>$response->getStatusCode(),'body'=>is_array($decoded)?$decoded:['message'=>'Non-JSON application response']];
}

function expectUat(array $response,array $allowed,string $scenario): array
{
    if(!in_array($response['http_status'],$allowed,true))throw new RuntimeException('STOP '.$scenario.': HTTP '.$response['http_status'].' '.json_encode($response['body'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $response['body'];
}

function assertUatFinancialIntegrity(int $companyId,string $scenario): void
{
    $unbalanced=DB::table('journal_entry_lines')->where('company_id',$companyId)->groupBy('journal_entry_id')->selectRaw('journal_entry_id, ABS(SUM(debit)-SUM(credit)) variance')->havingRaw('ABS(SUM(debit)-SUM(credit)) > 0.001')->get();
    if($unbalanced->isNotEmpty())throw new RuntimeException('STOP '.$scenario.': unbalanced journal detected.');
    $duplicates=DB::table('journal_entries')->where('company_id',$companyId)->where('status','POSTED')->select('source_type','source_id')->groupBy('source_type','source_id')->havingRaw('COUNT(*) > 1')->get();
    if($duplicates->isNotEmpty())throw new RuntimeException('STOP '.$scenario.': duplicate source journal detected.');
    $yearMismatch=DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('l.company_id',$companyId)->where(function($q){$q->whereNull('l.financial_year_id')->orWhereNull('j.financial_year_id')->orWhereColumn('l.financial_year_id','<>','j.financial_year_id');})->count();
    if($yearMismatch)throw new RuntimeException('STOP '.$scenario.': journal financial-year mismatch detected.');
    if(DB::table('inventory_lots')->where('company_id',$companyId)->where('qty_remaining_kg','<',-0.001)->exists())throw new RuntimeException('STOP '.$scenario.': negative inventory lot detected.');
}

if($mode==='--provision'){
    $companyName='UAT_SULB_FINANCIAL';
    if(DB::table('companies')->where('company_name',$companyName)->exists()){
        fwrite(STDERR,"The isolated UAT company already exists; duplicate provisioning is forbidden.\n");
        exit(2);
    }

    $plan=DB::table('plans')->where('plan_code','PRO')->where('is_active',1)->first();
    if(!$plan)throw new RuntimeException('The active PRO plan required for isolated UAT is unavailable.');

    $currenciesBefore=DB::table('currencies')->whereIn('currency_code',['SAR','USD'])->orderBy('currency_code')->get()->map(static fn(object$row):array=>(array)$row)->all();
    $result=app(CompanyProvisioningService::class)->provision([
        'idempotency_key'=>'uat-sulb-financial-20260825-v1',
        'channel'=>'PLATFORM_ADMIN',
        'company_name'=>$companyName,
        'owner_name'=>'UAT Financial Owner',
        'phone'=>'0500000099',
        'username'=>'uat_financial_owner',
        'password'=>Str::password(24),
        'plan_id'=>(int)$plan->id,
        'billing_period'=>'YEARLY',
        'start_date'=>now()->toDateString(),
        'end_date'=>now()->addYear()->subDay()->toDateString(),
        'subscription_mode'=>'TRIAL',
        'trial_allowed'=>true,
        'company_is_active'=>true,
        'currency_code'=>'SAR',
    ]);

    $companyId=(int)$result['company_id'];
    $currenciesAfter=DB::table('currencies')->whereIn('currency_code',['SAR','USD'])->orderBy('currency_code')->get()->map(static fn(object$row):array=>(array)$row)->all();
    if($currenciesBefore!==$currenciesAfter)throw new RuntimeException('STOP: existing global currency master records changed during UAT provisioning.');
    if(DB::table('company_settings')->where('company_id',$companyId)->value('currency_code')!=='SAR')throw new RuntimeException('STOP: provisioned company settings currency is not SAR.');
    if(DB::table('financial_accounts')->where('company_id',$companyId)->where('currency_code','<>','SAR')->exists())throw new RuntimeException('STOP: provisioned financial accounts are not consistently SAR.');

    $manifestPath=refreshUatManifest($companyId);

    echo json_encode(['mode'=>'UAT_PROVISION','company_id'=>$companyId,'owner_id'=>(int)$result['owner_id'],'branch_id'=>(int)$result['branch_id'],'subscription_id'=>(int)$result['subscription_id'],'subscription_status'=>$result['subscription_status'],'financial_year_id'=>(int)$result['accounting']['financial_year_id'],'entitlement_snapshot_rows'=>(int)$result['entitlement_snapshot_rows'],'global_currency_master_unchanged'=>true,'currency_code'=>'SAR','manifest'=>$manifestPath],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if($mode==='--exercise-master'){
    $company=DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->first();
    if(!$company)throw new RuntimeException('The isolated UAT company has not been provisioned.');
    $companyId=(int)$company->id;
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $token=$owner->createToken('UAT financial isolated exercise',['session'])->plainTextToken;
    $mainBranch=(int)$owner->branch_id;
    $results=[];

    try{
        $results['owner_users']=expectUat(uatRequest($token,'GET','users'),[200],'COMPANY_OWNER user listing');
        $branch=expectUat(uatRequest($token,'POST','branches',['company_id'=>$companyId,'branch_name'=>'UAT_BRANCH_2','branch_code'=>'UAT-BR-2','is_active'=>1]),[201],'secondary UAT branch creation');
        $results['secondary_branch_id']=(int)$branch['data']['branch_id'];
        refreshUatManifest($companyId);

        $supplier=expectUat(uatRequest($token,'POST','suppliers',['supplier_name'=>'UAT_SUPPLIER','supplier_code'=>'UAT-SUP','scope_all_branches'=>true,'default_branch_id'=>$mainBranch]),[201],'UAT supplier creation');
        $customer=expectUat(uatRequest($token,'POST','customers',['customer_name'=>'UAT_CUSTOMER','customer_code'=>'UAT-CUS','scope_all_branches'=>true,'default_branch_id'=>$mainBranch]),[201],'UAT customer creation');
        $results['supplier_id']=(int)$supplier['id'];
        $results['customer_id']=(int)$customer['id'];

        foreach(['COPPER','BRASS'] as$name){
            $item=expectUat(uatRequest($token,'POST','items',['item_name'=>'UAT_'.$name,'item_code'=>'UAT-'.$name,'item_type'=>'STOCK','track_inventory'=>1,'can_purchase'=>1,'can_sell'=>1,'base_unit_code'=>'KG','commercial_unit_code'=>'KG','commercial_to_base_factor'=>1]),[201],'UAT '.$name.' item creation');
            $results[strtolower($name).'_item_id']=(int)$item['id'];
        }

        $driver=expectUat(uatRequest($token,'POST','drivers',['driver_name'=>'UAT_DRIVER','affiliation_type'=>'COMPANY','is_active'=>1]),[201],'UAT driver creation');
        $results['driver_id']=(int)$driver['id'];
        $car=expectUat(uatRequest($token,'POST','cars',['branch_id'=>$mainBranch,'driver_id'=>$results['driver_id'],'plate_number'=>'UAT-VEHICLE-8','car_number'=>'UAT_VEHICLE','ownership_type'=>'COMPANY','is_active'=>1]),[201],'UAT vehicle creation');
        $results['vehicle_id']=(int)$car['id'];

        $branchManagerRole=(int)DB::table('roles')->where('role_code','BRANCH_MANAGER')->where('is_active',1)->value('id');
        if(!$branchManagerRole)throw new RuntimeException('The required BRANCH_MANAGER role is unavailable.');
        $branchUser=expectUat(uatRequest($token,'POST','users',['company_id'=>$companyId,'branch_id'=>$results['secondary_branch_id'],'role_id'=>$branchManagerRole,'name'=>'UAT Branch User','username'=>'uat_branch_user','password'=>Str::password(20),'is_active'=>1]),[201],'UAT branch-limited user creation');
        $results['branch_user_id']=(int)$branchUser['id'];

        refreshUatManifest($companyId);
        unset($results['owner_users']);
        echo json_encode(['mode'=>'UAT_MASTER_DATA','company_id'=>$companyId,...$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        refreshUatManifest($companyId);
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if($mode==='--exercise-financial'){
    $company=DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->first();
    if(!$company)throw new RuntimeException('The isolated UAT company has not been provisioned.');
    $companyId=(int)$company->id;
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $token=$owner->createToken('UAT isolated financial scenarios',['session'])->plainTextToken;
    $branchId=(int)$owner->branch_id;
    $supplierId=(int)DB::table('suppliers')->where('company_id',$companyId)->where('supplier_name','UAT_SUPPLIER')->value('id');
    $customerId=(int)DB::table('customers')->where('company_id',$companyId)->where('customer_name','UAT_CUSTOMER')->value('id');
    $itemId=(int)DB::table('items')->where('company_id',$companyId)->where('item_code','UAT-COPPER')->value('id');
    if(!$supplierId||!$customerId||!$itemId)throw new RuntimeException('Required isolated UAT master records are unavailable.');
    if(DB::table('purchase_invoices')->where('company_id',$companyId)->whereNotIn('invoice_number',['UAT-PURCHASE-1','UAT-PURCHASE-2'])->exists())throw new RuntimeException('Unexpected UAT purchase documents exist; automatic execution is forbidden.');
    if(DB::table('sales_invoices')->where('company_id',$companyId)->exists())throw new RuntimeException('A UAT sale already exists; automatic duplicate execution is forbidden.');
    $date=now()->toDateString();
    $results=[];

    try{
        foreach([[1000,10,'UAT-PURCHASE-1'],[500,12,'UAT-PURCHASE-2']] as[$quantity,$unitCost,$number]){
            $existing=DB::table('purchase_invoices')->where('company_id',$companyId)->where('invoice_number',$number)->first();
            if($existing){
                if($existing->document_status!=='POSTED'||!$existing->journal_entry_id)throw new RuntimeException('STOP '.$number.': existing document is not in the expected safely resumable POSTED state.');
                $invoiceId=(int)$existing->id;
                $posted=['data'=>['journal_entry_id'=>(int)$existing->journal_entry_id]];
            }else{
                $draft=expectUat(uatRequest($token,'POST','purchase-invoices',['branch_id'=>$branchId,'supplier_id'=>$supplierId,'invoice_number'=>$number,'invoice_date'=>$date,'currency_code'=>'SAR','items'=>[['item_id'=>$itemId,'qty_kg'=>$quantity,'price_unit'=>'KG','unit_price'=>$unitCost,'vat_percent'=>15]]]),[201],$number.' draft');
                $invoiceId=(int)$draft['id'];
                refreshUatManifest($companyId);
                $before=DB::table('purchase_invoices')->where('id',$invoiceId)->first();
                if($before->document_status!=='DRAFT'||$before->journal_entry_id||DB::table('inventory_lots')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->exists())throw new RuntimeException('STOP '.$number.': draft has financial or inventory impact.');
                $posted=expectUat(uatRequest($token,'POST','purchase-invoices/'.$invoiceId.'/post'),[200],$number.' posting');
            }
            refreshUatManifest($companyId);
            assertUatFinancialIntegrity($companyId,$number);
            $lot=DB::table('inventory_lots')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->first();
            if(!$lot||(float)$lot->qty_received_kg!==(float)$quantity||(float)$lot->unit_cost_per_kg!==(float)$unitCost)throw new RuntimeException('STOP '.$number.': inventory lot quantity/cost mismatch.');
            if((float)$lot->total_cost!==(float)($quantity*$unitCost))throw new RuntimeException('STOP '.$number.': inventory lot valuation mismatch.');
            $journalCount=DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->count();
            $movementCount=DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->count();
            expectUat(uatRequest($token,'POST','purchase-invoices/'.$invoiceId.'/post'),[200],$number.' idempotent retry');
            if($journalCount!==DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->count()||$movementCount!==DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->count())throw new RuntimeException('STOP '.$number.': duplicate posting impact.');
            $invoice=DB::table('purchase_invoices')->where('id',$invoiceId)->first();
            $results['purchases'][]=['id'=>$invoiceId,'journal_entry_id'=>(int)$posted['data']['journal_entry_id'],'lot_id'=>(int)$lot->id,'quantity_kg'=>$quantity,'unit_cost'=>$unitCost,'total_cost'=>(float)$lot->total_cost,'vat_amount'=>(float)$invoice->vat_amount,'total_amount'=>(float)$invoice->total_amount,'retry_idempotent'=>true];
        }

        $saleDraft=expectUat(uatRequest($token,'POST','sales-invoices',['branch_id'=>$branchId,'customer_id'=>$customerId,'invoice_number'=>'UAT-SALE-1','invoice_date'=>$date,'currency_code'=>'SAR','items'=>[['item_id'=>$itemId,'qty_kg'=>1200,'price_unit'=>'KG','unit_price'=>20,'vat_percent'=>15]]]),[201],'UAT sale draft');
        $saleId=(int)$saleDraft['id'];
        refreshUatManifest($companyId);
        $salePost=expectUat(uatRequest($token,'POST','sales-invoices/'.$saleId.'/post'),[200],'UAT sale posting');
        refreshUatManifest($companyId);
        assertUatFinancialIntegrity($companyId,'UAT sale');
        $saleLineIds=DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$saleId)->pluck('id')->all();
        $allocations=DB::table('sales_line_lot_sources')->where('company_id',$companyId)->whereIn('sales_invoice_line_id',$saleLineIds)->orderBy('id')->get(['id','inventory_lot_id','qty_kg','unit_cost_per_kg','total_cost']);
        $actualCogs=round((float)$allocations->sum('total_cost'),3);
        $expectedCogs=12400.0;
        if(abs($actualCogs-$expectedCogs)>0.001)throw new RuntimeException('STOP: FIFO/COGS variance detected; expected '.$expectedCogs.', actual '.$actualCogs.'.');
        $remainingQty=round((float)DB::table('inventory_lots')->where('company_id',$companyId)->where('item_id',$itemId)->sum('qty_remaining_kg'),3);
        $remainingValue=round((float)DB::table('inventory_lots')->where('company_id',$companyId)->where('item_id',$itemId)->selectRaw('SUM(qty_remaining_kg * unit_cost_per_kg) value')->value('value'),3);
        if($remainingQty!==300.0||$remainingValue!==3600.0)throw new RuntimeException('STOP: remaining FIFO inventory does not equal 300 kg / 3600 SAR.');
        $sale=DB::table('sales_invoices')->where('id',$saleId)->first();
        $saleJournalCount=DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','SALE')->where('source_id',$saleId)->count();
        $allocationCount=$allocations->count();
        expectUat(uatRequest($token,'POST','sales-invoices/'.$saleId.'/post'),[200],'UAT sale idempotent retry');
        if($saleJournalCount!==DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','SALE')->where('source_id',$saleId)->count()||$allocationCount!==DB::table('sales_line_lot_sources')->where('company_id',$companyId)->whereIn('sales_invoice_line_id',$saleLineIds)->count())throw new RuntimeException('STOP: sale retry duplicated financial or FIFO impact.');
        $results['sale']=['id'=>$saleId,'journal_entry_id'=>(int)$salePost['data']['journal_entry_id'],'expected_cogs'=>$expectedCogs,'actual_cogs'=>$actualCogs,'variance'=>0,'remaining_quantity_kg'=>$remainingQty,'remaining_inventory_value'=>$remainingValue,'revenue'=>(float)$sale->total_before_vat,'vat_amount'=>(float)$sale->vat_amount,'total_amount'=>(float)$sale->total_amount,'allocations'=>$allocations,'retry_idempotent'=>true];

        refreshUatManifest($companyId);
        echo json_encode(['mode'=>'UAT_FINANCIAL','company_id'=>$companyId,...$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        refreshUatManifest($companyId);
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if($mode==='--exercise-advanced'){
    $companyId=(int)DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->value('id');
    if(!$companyId)throw new RuntimeException('The isolated UAT company does not exist.');
    if(DB::table('commercial_returns')->where('company_id',$companyId)->exists())throw new RuntimeException('UAT returns already exist; duplicate advanced execution is forbidden.');
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $token=$owner->createToken('UAT isolated returns and vouchers',['session'])->plainTextToken;
    $sale=DB::table('sales_invoices')->where('company_id',$companyId)->where('invoice_number','UAT-SALE-1')->first();
    $purchase=DB::table('purchase_invoices')->where('company_id',$companyId)->where('invoice_number','UAT-PURCHASE-2')->first();
    if(!$sale||!$purchase)throw new RuntimeException('Posted UAT sales and purchase invoices are required.');
    $results=[];

    try{
        $saleLineId=(int)DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$sale->id)->value('id');
        $saleReturn=expectUat(uatRequest($token,'POST','commercial-returns',['return_type'=>'SALES_RETURN','return_date'=>now()->toDateString(),'source_invoice_id'=>(int)$sale->id,'notes'=>'UAT sales return','lines'=>[['source_invoice_line_id'=>$saleLineId,'quantity'=>50]]]),[201],'UAT sales-return draft');
        $salesReturnId=(int)$saleReturn['id'];
        refreshUatManifest($companyId);
        expectUat(uatRequest($token,'POST','commercial-returns/'.$salesReturnId.'/post'),[200],'UAT sales-return posting');
        refreshUatManifest($companyId);
        assertUatFinancialIntegrity($companyId,'UAT sales return');
        $salesReturnRow=DB::table('commercial_returns')->where('id',$salesReturnId)->first();
        $salesReturnLine=DB::table('commercial_return_lines')->where('return_id',$salesReturnId)->first();
        if((float)$salesReturnLine->qty_kg!==50.0||(float)$salesReturnLine->inventory_cost!==500.0||(float)$salesReturnRow->vat_amount!==150.0)throw new RuntimeException('STOP: sales return quantity/cost/VAT mismatch.');
        $returnJournalCount=DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','SALES_RETURN')->where('source_id',$salesReturnId)->count();
        expectUat(uatRequest($token,'POST','commercial-returns/'.$salesReturnId.'/post'),[200],'UAT sales-return idempotent retry');
        if($returnJournalCount!==DB::table('journal_entries')->where('company_id',$companyId)->where('source_type','SALES_RETURN')->where('source_id',$salesReturnId)->count())throw new RuntimeException('STOP: duplicate sales return journal.');
        $results['sales_return']=['id'=>$salesReturnId,'quantity_kg'=>50,'inventory_cost'=>(float)$salesReturnLine->inventory_cost,'vat_reversal'=>(float)$salesReturnRow->vat_amount,'journal_entry_id'=>(int)$salesReturnRow->journal_entry_id,'retry_idempotent'=>true];

        $purchaseLineId=(int)DB::table('purchase_invoice_lines')->where('company_id',$companyId)->where('purchase_invoice_id',$purchase->id)->value('id');
        $purchaseReturn=expectUat(uatRequest($token,'POST','commercial-returns',['return_type'=>'PURCHASE_RETURN','return_date'=>now()->toDateString(),'source_invoice_id'=>(int)$purchase->id,'notes'=>'UAT purchase return','lines'=>[['source_invoice_line_id'=>$purchaseLineId,'quantity'=>20]]]),[201],'UAT purchase-return draft');
        $purchaseReturnId=(int)$purchaseReturn['id'];
        refreshUatManifest($companyId);
        expectUat(uatRequest($token,'POST','commercial-returns/'.$purchaseReturnId.'/post'),[200],'UAT purchase-return posting');
        refreshUatManifest($companyId);
        assertUatFinancialIntegrity($companyId,'UAT purchase return');
        $purchaseReturnRow=DB::table('commercial_returns')->where('id',$purchaseReturnId)->first();
        $purchaseReturnLine=DB::table('commercial_return_lines')->where('return_id',$purchaseReturnId)->first();
        if((float)$purchaseReturnLine->inventory_cost!==240.0||(float)$purchaseReturnRow->vat_amount!==36.0)throw new RuntimeException('STOP: purchase return cost/VAT mismatch.');
        $results['purchase_return']=['id'=>$purchaseReturnId,'quantity_kg'=>20,'inventory_cost'=>(float)$purchaseReturnLine->inventory_cost,'vat_reversal'=>(float)$purchaseReturnRow->vat_amount,'journal_entry_id'=>(int)$purchaseReturnRow->journal_entry_id];

        $expenseType=(int)DB::table('expense_types')->whereNull('company_id')->where('type_code','GENERAL')->value('id');
        $expense=expectUat(uatRequest($token,'POST','expenses',['branch_id'=>(int)$owner->branch_id,'expense_type_id'=>$expenseType,'expense_date'=>now()->toDateString(),'scope_type'=>'GENERAL','amount'=>100,'payment_status'=>'PAID','payment_method'=>'CASH','currency_code'=>'SAR','notes'=>'UAT expense']),[201],'UAT expense creation/posting');
        refreshUatManifest($companyId);
        assertUatFinancialIntegrity($companyId,'UAT expense');
        $results['expense']=['id'=>(int)$expense['id'],'journal_entry_id'=>(int)$expense['journal_entry_id'],'automatic_voucher_id'=>(int)$expense['voucher_id'],'amount'=>100];

        $customerId=(int)DB::table('customers')->where('company_id',$companyId)->where('customer_name','UAT_CUSTOMER')->value('id');
        $receiptType=(int)DB::table('voucher_types')->where('type_code','RECEIPT')->value('id');
        $voucher=expectUat(uatRequest($token,'POST','vouchers',['branch_id'=>(int)$owner->branch_id,'voucher_type_id'=>$receiptType,'voucher_date'=>now()->toDateString(),'reference_type'=>'CUSTOMER','reference_id'=>$customerId,'amount'=>250,'payment_method'=>'CASH','currency_code'=>'SAR','voucher_number'=>'UAT-RECEIPT-1','notes'=>'UAT receipt voucher']),[201],'UAT receipt voucher posting');
        refreshUatManifest($companyId);
        assertUatFinancialIntegrity($companyId,'UAT receipt voucher');
        $results['voucher']=['id'=>(int)$voucher['id'],'journal_entry_id'=>(int)$voucher['journal_entry_id'],'amount'=>250];

        $balances=DB::table('journal_entry_lines')->where('company_id',$companyId)->selectRaw('SUM(debit) debit, SUM(credit) credit')->first();
        $results['trial_balance']=['debit'=>(float)$balances->debit,'credit'=>(float)$balances->credit,'balanced'=>(float)$balances->debit===(float)$balances->credit];
        echo json_encode(['mode'=>'UAT_ADVANCED','company_id'=>$companyId,...$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        refreshUatManifest($companyId);
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if($mode==='--exercise-security'){
    $companyId=(int)DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->value('id');
    if(!$companyId)throw new RuntimeException('The isolated UAT company does not exist.');
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $branchUser=User::where('company_id',$companyId)->where('username','uat_branch_user')->firstOrFail();
    $platform=User::whereNull('company_id')->where('username','admin')->firstOrFail();
    $ownerToken=$owner->createToken('UAT isolated authorization owner',['session'])->plainTextToken;
    $branchToken=$branchUser->createToken('UAT isolated branch authorization',['session'])->plainTextToken;
    $platformAccess=$platform->createToken('UAT isolated support and entitlements',['session','platform-admin']);
    $platformToken=$platformAccess->plainTextToken;
    $results=[];

    try{
        $roleId=(int)DB::table('roles')->where('role_code','BRANCH_MANAGER')->value('id');
        $rolePermissions=DB::table('role_permissions as rp')->join('permissions as p','p.id','=','rp.permission_id')->where('rp.role_id',$roleId)->where('rp.is_active',1)->where('p.permission_scope','COMPANY')->pluck('p.permission_code')->all();
        $overrides=[];
        foreach($rolePermissions as$permission)if(!in_array($permission,['branches.view','items.view'],true))$overrides[$permission]='DENY';
        expectUat(uatRequest($ownerToken,'PUT','permission-matrix/users/'.$branchUser->id,['overrides'=>$overrides]),[200],'branch user least-privilege overrides');
        refreshUatManifest($companyId);

        $branchList=expectUat(uatRequest($branchToken,'GET','branches'),[200],'branch-limited branch listing');
        $branchIds=array_map(static fn(array$row):int=>(int)$row['id'],$branchList['data']);
        if($branchIds!==[(int)$branchUser->branch_id])throw new RuntimeException('STOP: cross-branch data exposure detected.');
        $mainDenied=uatRequest($branchToken,'GET','branches/'.$owner->branch_id);
        if(!in_array($mainDenied['http_status'],[403,404],true))throw new RuntimeException('STOP: branch-limited user can access the UAT main branch.');
        $foreignBranch=(int)DB::table('branches')->where('company_id','<>',$companyId)->value('id');
        $foreignDenied=uatRequest($branchToken,'GET','branches/'.$foreignBranch);
        if(!in_array($foreignDenied['http_status'],[403,404],true))throw new RuntimeException('STOP: cross-company branch exposure detected.');
        expectUat(uatRequest($branchToken,'GET','items'),[200],'branch-limited permitted item read');
        $permissionDenied=uatRequest($branchToken,'GET','sales-invoices');
        if($permissionDenied['http_status']!==403)throw new RuntimeException('STOP: missing branch-user permission was not denied.');
        $results['branch_isolation']=['branch_ids'=>$branchIds,'main_branch_denied'=>$mainDenied['http_status'],'foreign_company_denied'=>$foreignDenied['http_status'],'permission_denied'=>$permissionDenied['http_status'],'least_privilege_denials'=>count($overrides)];

        $support=expectUat(uatRequest($platformToken,'POST','companies/'.$companyId.'/support-access',['reason'=>'Isolated financial UAT read-only verification','ticket_reference'=>'UAT-SUPPORT-8','expires_at'=>now()->addHour()->toISOString(),'access_level'=>'READ_ONLY','branch_id'=>(int)$owner->branch_id]),[200],'platform support entry');
        $supportToken=(string)$support['token'];
        $supportSessionId=(string)$support['support_session_id'];
        refreshUatManifest($companyId);
        $supportBranches=expectUat(uatRequest($supportToken,'GET','branches'),[200],'support read-only branch listing');
        $supportIds=array_map(static fn(array$row):int=>(int)$row['id'],$supportBranches['data']);
        if($supportIds!==[(int)$owner->branch_id])throw new RuntimeException('STOP: support mode escaped its authorized branch.');
        $beforeBranchCount=DB::table('branches')->where('company_id',$companyId)->count();
        $supportDenied=uatRequest($supportToken,'POST','branches',['branch_name'=>'UAT_SUPPORT_MUST_NOT_CREATE','branch_code'=>'UAT-DENIED']);
        if($supportDenied['http_status']!==403||($supportDenied['body']['code']??null)!=='SUPPORT_WRITE_DENIED')throw new RuntimeException('STOP: read-only support mutation was not denied.');
        if($beforeBranchCount!==DB::table('branches')->where('company_id',$companyId)->count())throw new RuntimeException('STOP: read-only support mutation changed branch data.');
        $auditId=(int)DB::table('audit_logs')->where('company_id',$companyId)->where('support_session_id',$supportSessionId)->where('action_type','SUPPORT_WRITE')->where('result','DENIED')->value('id');
        if(!$auditId)throw new RuntimeException('STOP: denied support mutation was not audited.');
        expectUat(uatRequest($supportToken,'POST','support/exit'),[200],'support exit');
        $supportAfterExit=uatRequest($supportToken,'GET','branches');
        if(!in_array($supportAfterExit['http_status'],[401,403],true))throw new RuntimeException('STOP: support session remained usable after exit.');
        $results['support_read_only']=['session_id'=>$supportSessionId,'branch_ids'=>$supportIds,'denied_code'=>'SUPPORT_WRITE_DENIED','denied_audit_id'=>$auditId,'post_exit_status'=>$supportAfterExit['http_status']];

        $today=now()->toDateString();
        $disabled=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>false,'effective_from'=>$today,'reason'=>'Isolated UAT feature-denial verification']),[201],'UAT-only sales feature disable');
        refreshUatManifest($companyId);
        $featureDenied=uatRequest($ownerToken,'GET','sales-invoices');
        if($featureDenied['http_status']!==403||($featureDenied['body']['code']??null)!=='FEATURE_NOT_ENTITLED')throw new RuntimeException('STOP: disabled UAT sales entitlement was not enforced by the backend.');
        $enabled=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>true,'effective_from'=>$today,'reason'=>'Restore isolated UAT sales entitlement after verification']),[201],'restore UAT-only sales feature');
        expectUat(uatRequest($ownerToken,'GET','sales-invoices'),[200],'restored UAT sales access');
        $results['entitlements']=['disabled_override_id'=>(int)$disabled['id'],'restored_override_id'=>(int)$enabled['id'],'denial_code'=>'FEATURE_NOT_ENTITLED'];

        $limit=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'max_users','limit_value'=>2,'effective_from'=>$today,'reason'=>'Isolated UAT exact user-limit verification']),[201],'UAT-only user limit override');
        $limitResponse=uatRequest($ownerToken,'POST','users',['company_id'=>$companyId,'branch_id'=>(int)$owner->branch_id,'role_id'=>$roleId,'name'=>'UAT Limit Denied','username'=>'uat_limit_must_not_exist','password'=>Str::password(20),'is_active'=>1]);
        if($limitResponse['http_status']!==409)throw new RuntimeException('STOP: UAT usage limit did not reject growth at threshold.');
        if(DB::table('users')->where('company_id',$companyId)->where('username','uat_limit_must_not_exist')->exists())throw new RuntimeException('STOP: denied usage-limit request created a user.');
        expectUat(uatRequest($ownerToken,'GET','users'),[200],'existing users readable at usage limit');
        $results['usage_limit']=['override_id'=>(int)$limit['id'],'limit'=>2,'current_users'=>2,'denied_status'=>409,'existing_records_readable'=>true];

        $vat=expectUat(uatRequest($ownerToken,'GET','tax-reports'),[200],'UAT VAT report')['data']['summary'];
        if((float)$vat['gross_output_tax']!==3600.0||(float)$vat['sales_return_tax']!==150.0||(float)$vat['gross_input_tax']!==2400.0||(float)$vat['purchase_return_tax']!==36.0)throw new RuntimeException('STOP: VAT report does not reconcile with posted UAT documents.');
        expectUat(uatRequest($ownerToken,'GET','accounting/trial-balance'),[200],'UAT trial balance API');
        $inventoryAccount=(int)DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key','INVENTORY_ACCOUNT')->value('account_id');
        expectUat(uatRequest($ownerToken,'GET','accounting/ledger?account_id='.$inventoryAccount),[200],'UAT inventory general ledger API');
        $results['vat_report']=$vat;
        assertUatFinancialIntegrity($companyId,'final isolated security scenarios');
        refreshUatManifest($companyId);
        $platformAccess->accessToken->delete();
        echo json_encode(['mode'=>'UAT_SECURITY','company_id'=>$companyId,...$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        refreshUatManifest($companyId);
        $platformAccess->accessToken->delete();
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if($mode==='--exercise-controls'){
    $companyId=(int)DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->value('id');
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $platform=User::whereNull('company_id')->where('username','admin')->firstOrFail();
    $ownerToken=$owner->createToken('UAT isolated controls',['session'])->plainTextToken;
    $platformAccess=$platform->createToken('UAT isolated control restoration',['session','platform-admin']);
    $platformToken=$platformAccess->plainTextToken;
    $today=now()->toDateString();

    try{
        $disabledAccess=uatRequest($ownerToken,'GET','sales-invoices');
        $enabled=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>true,'effective_from'=>$today,'reason'=>'Restore isolated UAT sales entitlement after enforcement defect confirmation']),[201],'restore UAT sales entitlement');
        expectUat(uatRequest($ownerToken,'GET','sales-invoices'),[200],'restored UAT sales read');

        $roleId=(int)DB::table('roles')->where('role_code','BRANCH_MANAGER')->value('id');
        $limit=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'max_users','limit_value'=>2,'effective_from'=>$today,'reason'=>'Isolated UAT exact user-limit verification']),[201],'UAT user-limit override');
        $limitResponse=uatRequest($ownerToken,'POST','users',['company_id'=>$companyId,'branch_id'=>(int)$owner->branch_id,'role_id'=>$roleId,'name'=>'UAT Limit Denied','username'=>'uat_limit_must_not_exist','password'=>Str::password(20),'is_active'=>1]);
        if($limitResponse['http_status']!==409)throw new RuntimeException('STOP: UAT usage limit did not reject growth at threshold.');
        if(DB::table('users')->where('company_id',$companyId)->where('username','uat_limit_must_not_exist')->exists())throw new RuntimeException('STOP: denied usage-limit request created a user.');
        expectUat(uatRequest($ownerToken,'GET','users'),[200],'existing UAT users readable at limit');

        $vat=expectUat(uatRequest($ownerToken,'GET','tax-reports'),[200],'UAT VAT report')['data']['summary'];
        if((float)$vat['gross_output_tax']!==3600.0||(float)$vat['sales_return_tax']!==150.0||(float)$vat['gross_input_tax']!==2400.0||(float)$vat['purchase_return_tax']!==36.0)throw new RuntimeException('STOP: VAT report does not reconcile with posted UAT documents.');
        expectUat(uatRequest($ownerToken,'GET','accounting/trial-balance'),[200],'UAT trial balance API');
        $inventoryAccount=(int)DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key','INVENTORY_ACCOUNT')->value('account_id');
        expectUat(uatRequest($ownerToken,'GET','accounting/ledger?account_id='.$inventoryAccount),[200],'UAT inventory ledger API');
        assertUatFinancialIntegrity($companyId,'UAT controls and reports');
        refreshUatManifest($companyId);
        $platformAccess->accessToken->delete();
        echo json_encode(['mode'=>'UAT_CONTROLS','company_id'=>$companyId,'entitlement_disabled_access'=>$disabledAccess,'restored_override_id'=>(int)$enabled['id'],'usage_limit'=>['override_id'=>(int)$limit['id'],'limit'=>2,'current_users'=>2,'denied_status'=>409,'existing_records_readable'=>true],'vat_report'=>$vat,'trial_balance_api'=>'PASS','ledger_api'=>'PASS'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        refreshUatManifest($companyId);
        $platformAccess->accessToken->delete();
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit(0);
}

if($mode==='--retest-entitlement'){
    $companyId=(int)DB::table('companies')->where('company_name','UAT_SULB_FINANCIAL')->value('id');
    if($companyId!==8)throw new RuntimeException('The approved UAT company 8 is unavailable.');
    $owner=User::where('company_id',$companyId)->where('username','uat_financial_owner')->firstOrFail();
    $platform=User::whereNull('company_id')->where('username','admin')->firstOrFail();
    $ownerAccess=$owner->createToken('UAT entitlement normalization retest',['session']);
    $platformAccess=$platform->createToken('UAT entitlement normalization platform retest',['session','platform-admin']);
    $ownerToken=$ownerAccess->plainTextToken;
    $platformToken=$platformAccess->plainTextToken;
    $today=now()->toDateString();
    $fingerprint=static fn():array=>[
        'sales_count'=>DB::table('sales_invoices')->where('company_id',8)->count(),
        'sales_total'=>(float)DB::table('sales_invoices')->where('company_id',8)->sum('total_amount'),
        'journal_count'=>DB::table('journal_entries')->where('company_id',8)->count(),
        'journal_debit'=>(float)DB::table('journal_entry_lines')->where('company_id',8)->sum('debit'),
        'journal_credit'=>(float)DB::table('journal_entry_lines')->where('company_id',8)->sum('credit'),
        'stock_movement_count'=>DB::table('stock_movements')->where('company_id',8)->count(),
        'lot_movement_count'=>DB::table('inventory_lot_movements')->where('company_id',8)->count(),
    ];
    $before=$fingerprint();
    $beforeEffective=app(App\Services\Entitlement\EffectiveEntitlementService::class)->allows($companyId,'sales');
    $disabledId=null;
    $restoredId=null;

    try{
        $disabled=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>false,'effective_from'=>$today,'reason'=>'DEF-UAT-ENT-001 official UAT denial retest']),[201],'disable UAT sales for normalization retest');
        $disabledId=(int)$disabled['id'];
        $denied=uatRequest($ownerToken,'GET','sales-invoices');
        if($denied['http_status']!==403||($denied['body']['code']??null)!=='FEATURE_NOT_ENTITLED')throw new RuntimeException('STOP: normalized sales entitlement did not deny the UAT request.');
        if($before!==$fingerprint())throw new RuntimeException('STOP: entitlement denial changed UAT financial data.');

        $restored=expectUat(uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>$beforeEffective,'effective_from'=>$today,'reason'=>'Restore UAT sales entitlement after DEF-UAT-ENT-001 retest']),[201],'restore UAT sales entitlement');
        $restoredId=(int)$restored['id'];
        $allowed=uatRequest($ownerToken,'GET','sales-invoices');
        if($allowed['http_status']!==200)throw new RuntimeException('STOP: restored UAT sales entitlement is not accessible.');
        if($before!==$fingerprint())throw new RuntimeException('STOP: UAT financial data changed during entitlement restore.');

        refreshUatManifest($companyId);
        echo json_encode(['mode'=>'UAT_ENTITLEMENT_RETEST','company_id'=>$companyId,'before_enabled'=>$beforeEffective,'disabled_override_id'=>$disabledId,'disabled_status'=>$denied['http_status'],'disabled_code'=>$denied['body']['code'],'restored_override_id'=>$restoredId,'restored_enabled'=>$beforeEffective,'restored_status'=>$allowed['http_status'],'financial_fingerprint_unchanged'=>true,'financial_fingerprint'=>$before],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable$e){
        if($disabledId&&!$restoredId){
            uatRequest($platformToken,'POST','system-admin/companies/'.$companyId.'/entitlement-overrides',['feature_code'=>'sales','is_enabled'=>$beforeEffective,'effective_from'=>$today,'reason'=>'Safety restore after failed DEF-UAT-ENT-001 retest']);
        }
        refreshUatManifest($companyId);
        $ownerAccess->accessToken->delete();
        $platformAccess->accessToken->delete();
        fwrite(STDERR,$e->getMessage().PHP_EOL);
        exit(1);
    }
    $ownerAccess->accessToken->delete();
    $platformAccess->accessToken->delete();
    exit(0);
}

if($mode!=='--preflight'){
    fwrite(STDERR,"Unsupported isolated UAT mode.\n");
    exit(2);
}

$essentialTables=['companies','branches','users','roles','plans','plan_features','subscriptions','subscription_entitlement_snapshots','financial_years','accounts','accounting_settings','suppliers','customers','items','tax_codes','company_currencies','purchase_invoices','purchase_invoice_lines','sales_invoices','sales_invoice_lines','inventory_lots','inventory_lot_movements','sales_line_lot_sources','stock_movements','commercial_returns','commercial_return_lines','journal_entries','journal_entry_lines','expense_types','expenses','voucher_types','vouchers'];
$plans=DB::table('plans')->where('is_active',1)->get(['id','plan_name','plan_code','max_users','max_branches']);
$planIds=$plans->pluck('id')->all();
$report=[
    'mode'=>'READ_ONLY_PREFLIGHT',
    'database_driver'=>DB::connection()->getDriverName(),
    'existing_uat_companies'=>DB::table('companies')->where('company_name','like','UAT\\_%')->get(['id','company_name']),
    'plans'=>$plans,
    'plan_features'=>DB::table('plan_features')->whereIn('plan_id',$planIds)->orderBy('plan_id')->orderBy('feature_code')->get(['plan_id','feature_code','is_enabled','limit_value']),
    'roles'=>DB::table('roles')->where('is_active',1)->get(['id','role_code','role_name']),
    'missing_tables'=>array_values(array_filter($essentialTables,static fn(string $table):bool=>!Schema::hasTable($table))),
    'global_voucher_types'=>Schema::hasTable('voucher_types')?DB::table('voucher_types')->get(['id','type_code','type_name']):[],
    'global_expense_types'=>Schema::hasTable('expense_types')?DB::table('expense_types')->whereNull('company_id')->get(['id','type_code','type_name','account_id']):[],
    'active_currencies'=>Schema::hasTable('currencies')?DB::table('currencies')->where('is_active',1)->get(['currency_code','currency_name']):[],
];

echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
