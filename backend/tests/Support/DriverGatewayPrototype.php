<?php
namespace Tests\Support;

use App\Services\Accounting\HistoricalDecimal as Money;
use App\Services\AccountingService;
use App\Services\ShipmentCostService;
use App\Services\SulbDocumentSequenceService;
use Illuminate\Support\Facades\DB;

/** Experimental trusted writer, test-only. Not registered in the application container/routes. */
final class DriverGatewayPrototype
{
    public array $lockTimings=[];
    public ?\Closure $beforeCompanyLock=null;
    private function companyLock(int $company):void
    {
        if($this->beforeCompanyLock)($this->beforeCompanyLock)();
        $start=hrtime(true);app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
        $this->lockTimings['company_ms']=(hrtime(true)-$start)/1e6;
    }
    public function __construct(private array $writer)
    {
        $c=DB::connection()->getConfig();
        if(app()->environment()!=='testing'||($c['host']??'')!=='127.0.0.1'||(string)($c['port']??'')!=='3339'||!preg_match('/^sulb_ship_test_[a-f0-9]{16}$/D',$c['database']??''))throw new \RuntimeException('Disposable gateway test only');
        $actual=DB::selectOne('SELECT @@datadir d')->d;
        if(rtrim(str_replace('\\','/',$actual),'/')!==rtrim(str_replace('\\','/',(string)getenv('SULB_SHIPMENT_TEST_DIR')),'/'))throw new \RuntimeException('Owned server required');
    }

    private function transaction(callable $action)
    {
        $previous=DB::getDefaultConnection();
        $config=DB::connection()->getConfig();
        config(['database.connections.gw_writer'=>array_replace($config,$this->writer)]);
        DB::purge('gw_writer');DB::setDefaultConnection('gw_writer');
        try{return DB::transaction($action,1);}finally{DB::setDefaultConnection($previous);DB::purge('gw_writer');}
    }

    private function scope(int $company,int $branch,int $account): int
    {
        $start=hrtime(true);
        // Stable row exists independently of ORIGIN. INSERT race is resolved by a non-null UNIQUE key.
        DB::statement("INSERT INTO gw_scopes(company_id,branch_id,account_id,currency_code) VALUES (?,?,?,'SAR') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",[$company,$branch,$account]);
        $id=(int)DB::table('gw_scopes')->where('company_id',$company)->where('branch_id',$branch)->where('account_id',$account)->where('currency_code','SAR')->lockForUpdate()->value('id');
        $this->lockTimings['scope_ms']=(hrtime(true)-$start)/1e6;return $id;
    }

    private function request(int $company,string $op,string $key,array $payload): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$key))throw new \RuntimeException('Invalid request key');
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        $r=DB::table('gw_requests')->where('company_id',$company)->where('operation',$op)->where('request_key',$key)->lockForUpdate()->first();
        if($r){if($r->fingerprint!==$hash)throw new \RuntimeException('IDEMPOTENCY_CONFLICT');return [(int)$r->id,json_decode($r->result,true,512,JSON_THROW_ON_ERROR)];}
        $id=DB::table('gw_requests')->insertGetId(['company_id'=>$company,'operation'=>$op,'request_key'=>$key,'fingerprint'=>$hash]);
        return [$id,null];
    }

    private function complete(int $id,array $result): array
    {
        DB::table('gw_requests')->where('id',$id)->update(['result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
        return $result;
    }

    public function createOrigin(array $input,string $key,bool $fault=false): array
    {
        // No enroll(existing_id), no client actor, no client original GL identifiers.
        foreach(['id','shipment_cost_id','journal_entry_id','original_line_id','cost_status','posted_at','created_by'] as $field)if(array_key_exists($field,$input))throw new \RuntimeException('Existing/history enrollment forbidden');
        $company=(int)$input['company_id'];$branch=(int)$input['branch_id'];
        $input['amount']=Money::format(Money::units($input['amount']));ksort($input);
        return $this->transaction(function()use($company,$branch,$input,$key,$fault){
            $this->companyLock($company);
            $account=(int)DB::table('accounting_settings')->where('company_id',$company)->where('setting_key','ACCRUED_EXPENSE_ACCOUNT')->value('account_id');
            $scope=$this->scope($company,$branch,$account);
            [$request,$prior]=$this->request($company,'ORIGIN',$key,$input);if($prior!==null)return $prior;
            if($input['payment_status']!=='UNPAID'||$input['payee_type']!=='DRIVER'||$input['currency_code']!=='SAR'||Money::units($input['amount'])<=0)throw new \RuntimeException('Prototype origin policy');
            $driver=DB::table('drivers')->where('company_id',$company)->where('id',$input['payee_id'])->where('is_active',1)->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$branch))->first();
            if(!$driver)throw new \RuntimeException('Driver scope denied');
            if(DB::table('shipment_costs')->where('company_id',$company)->where('shipment_id',$input['shipment_id'])->where('cost_status','DRAFT')->exists())throw new \RuntimeException('Other drafts cannot enroll');
            $cost=app(ShipmentCostService::class)->storeDraft([...$input,'created_by'=>1]);
            app(ShipmentCostService::class)->postPendingForShipment($company,(int)$input['shipment_id'],1);
            $c=DB::table('shipment_costs')->where('id',$cost)->first();
            $line=DB::table('journal_entry_lines')->where('journal_entry_id',$c->journal_entry_id)->where('credit','>',0)->first();
            if(!$line||(int)$line->account_id!==$account||$line->party_type!=='DRIVER'||(int)$line->party_id!==(int)$driver->id||Money::units($line->credit)!==Money::units($c->amount))throw new \RuntimeException('Original posting mismatch');
            if($fault)throw new \RuntimeException('FAULT_AFTER_JOURNAL');
            $origin=DB::table('gw_origins')->insertGetId(['scope_id'=>$scope,'request_id'=>$request,'shipment_cost_id'=>$cost,'journal_entry_id'=>$c->journal_entry_id,'line_id'=>$line->id,'driver_id'=>$driver->id,'amount'=>Money::format(Money::units($c->amount))]);
            return $this->complete($request,['origin_id'=>$origin,'cost_id'=>$cost,'journal_id'=>$c->journal_entry_id]);
        });
    }

    public function pay(int $company,int $branch,int $origin,string $amount,string $key,bool $fault=false): array
    {
        $amount=Money::format(Money::units($amount));if(Money::units($amount)<=0)throw new \RuntimeException('Positive payment required');
        return $this->transaction(function()use($company,$branch,$origin,$amount,$key,$fault){
            // Outer coordinator owns ordering BEFORE entering any frozen posting service.
            $this->companyLock($company);
            $source=DB::table('gw_origins as o')->join('gw_scopes as s','s.id','=','o.scope_id')->where('o.id',$origin)->where('s.company_id',$company)->where('s.branch_id',$branch)->select('o.*','s.account_id')->first();
            if(!$source)throw new \RuntimeException('Origin scope denied');
            $scope=$this->scope($company,$branch,(int)$source->account_id);
            [$request,$prior]=$this->request($company,'PAYMENT',$key,[$company,$branch,$origin,$amount]);if($prior!==null)return $prior;
            $o=DB::table('gw_origins')->where('id',$origin)->lockForUpdate()->first();
            if(DB::table('gw_exceptions')->where('scope_id',$scope)->exists())throw new \RuntimeException('UNCLASSIFIED_LEDGER_REVIEW');
            $cost=DB::table('shipment_costs')->where('id',$o->shipment_cost_id)->where('company_id',$company)->where('branch_id',$branch)->lockForUpdate()->first();
            $original=DB::table('journal_entries')->where('id',$o->journal_entry_id)->where('company_id',$company)->where('branch_id',$branch)->where('status','POSTED')->where('source_type','SHIPMENT_COST')->where('source_id',$o->shipment_cost_id)->whereNull('reversed_at')->lockForUpdate()->first();
            $line=DB::table('journal_entry_lines')->where('id',$o->line_id)->where('journal_entry_id',$o->journal_entry_id)->where('account_id',$source->account_id)->where('party_type','DRIVER')->where('party_id',$o->driver_id)->lockForUpdate()->first();
            if(!$cost||!$original||!$line||Money::units($line->credit)!==Money::units($o->amount))throw new \RuntimeException('Origin evidence drift');
            $paid=Money::units(DB::table('gw_payments')->where('origin_id',$origin)->sum('amount'));
            if($paid+Money::units($amount)>Money::units($o->amount))throw new \RuntimeException('OVERPAYMENT');
            $fa=DB::table('financial_accounts')->where('company_id',$company)->where('id',1)->where('is_active',1)->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$branch))->first();
            if(!$fa)throw new \RuntimeException('Payment account denied');
            $number=app(SulbDocumentSequenceService::class)->reserveVoucher($company,'PAYMENT','2026-03-03');
            $voucher=DB::table('vouchers')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'voucher_type_id'=>2,'voucher_number'=>$number,'voucher_date'=>'2026-03-03','reference_type'=>'GATEWAY_DRIVER_TEST','reference_id'=>$o->shipment_cost_id,'amount'=>$amount,'financial_account_id'=>$fa->id,'cash_account_id'=>$fa->gl_account_id,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>$amount,'created_by'=>1]);
            $journal=app(AccountingService::class)->post(['company_id'=>$company,'branch_id'=>$branch,'entry_date'=>'2026-03-03','source_type'=>'GW_DRIVER_PAYMENT','source_id'=>$request,'description'=>'Isolated trusted driver settlement','created_by'=>1,'lines'=>[['account_id'=>$source->account_id,'party_type'=>'DRIVER','party_id'=>$o->driver_id,'debit'=>$amount,'credit'=>0],['account_id'=>$fa->gl_account_id,'financial_account_id'=>$fa->id,'debit'=>0,'credit'=>$amount]]]);
            if($fault)throw new \RuntimeException('FAULT_PAYMENT');
            DB::table('vouchers')->where('id',$voucher)->update(['journal_entry_id'=>$journal]);
            DB::table('gw_payments')->insert(['origin_id'=>$origin,'request_id'=>$request,'voucher_id'=>$voucher,'journal_id'=>$journal,'amount'=>$amount]);
            return $this->complete($request,['voucher_id'=>$voucher,'journal_id'=>$journal,'remaining'=>Money::format(Money::units($o->amount)-$paid-Money::units($amount))]);
        });
    }

    /** Test-only fresh purchase coordinator. Never enrolls existing costs. */
    public function purchaseOrigin(string $key,bool $fault=false,?callable $beforeCommit=null,?string $failureStage=null):array
    {
        return $this->transaction(function()use($key,$fault,$beforeCommit,$failureStage){
            $armed=true;
            // Test-only after-query faults; actual SQL has executed on the writer connection.
            DB::listen(function($event)use($failureStage,&$armed){
                if(!$armed||$event->connection!==DB::connection())return;
                $sql=str_replace('`','',strtolower($event->sql));
                $hit=match($failureStage){
                    'after_inventory'=>str_starts_with($sql,'insert into stock_movements'),
                    'after_invoice'=>str_starts_with($sql,'update purchase_invoices')&&str_contains($sql,'document_status'),
                    'after_cost_journal'=>str_starts_with($sql,'update shipment_costs')&&str_contains($sql,'cost_status'),
                    default=>false,
                };
                if($hit){$armed=false;throw new \RuntimeException('FAULT_STAGE_'.$failureStage);}
            });
            try{
            $this->companyLock(1);$scope=$this->scope(1,1,2);
            [$request,$prior]=$this->request(1,'PURCHASE',$key,['fixture'=>'fresh-stock-purchase-v1']);if($prior!==null)return $prior;
            $cost=app(ShipmentCostService::class)->storeDraft(['company_id'=>1,'branch_id'=>1,'shipment_id'=>1,'expense_type_id'=>1,'expense_date'=>'2026-03-02','amount'=>'1000.000','currency_code'=>'SAR','exchange_rate'=>1,'capitalizable'=>true,'payment_status'=>'UNPAID','payee_type'=>'DRIVER','payee_id'=>1,'created_by'=>1]);
            $invoices=app(\App\Services\EnterpriseInvoiceService::class);
            $invoice=$invoices->saveDraft('PURCHASE',['invoice_date'=>'2026-03-02','invoice_number'=>'GATEWAY-PURCHASE','supplier_id'=>1,'shipment_ids'=>[1],'currency_code'=>'SAR','exchange_rate'=>1],1,1,1);
            $posted=$invoices->post('PURCHASE',1,$invoice,1,1);
            $c=DB::table('shipment_costs')->where('id',$cost)->first();
            $line=DB::table('journal_entry_lines')->where('journal_entry_id',$c->journal_entry_id)->where('account_id',2)->where('party_type','DRIVER')->where('party_id',1)->where('credit','1000.000')->sole();
            if($c->cost_status!=='POSTED'||!$c->distributed)throw new \RuntimeException('Real cost/distribution incomplete');
            if($fault)throw new \RuntimeException('FAULT_PURCHASE_BEFORE_ORIGIN');
            if($failureStage==='before_origin')throw new \RuntimeException('FAULT_STAGE_before_origin');
            $origin=DB::table('gw_origins')->insertGetId(['scope_id'=>$scope,'request_id'=>$request,'shipment_cost_id'=>$cost,'journal_entry_id'=>$c->journal_entry_id,'line_id'=>$line->id,'driver_id'=>1,'amount'=>'1000.000']);
            if($beforeCommit)$beforeCommit(); // isolated race instrumentation, not an operational hook
            if($failureStage==='after_origin')throw new \RuntimeException('FAULT_STAGE_after_origin');
            return $this->complete($request,['origin_id'=>$origin,'cost_id'=>$cost,'journal_id'=>$c->journal_entry_id,'invoice_id'=>$invoice,'invoice_journal_id'=>$posted['journal_entry_id']]);
            }finally{$armed=false;}
        });
    }

    public function manual(int $company,int $branch,int $account): int
    {
        return $this->transaction(function()use($company,$branch,$account){
            $scope=$this->scope($company,$branch,$account);
            $cash=DB::table('accounts')->where('company_id',$company)->where('account_type','ASSET')->where('is_active',1)->orderBy('id')->value('id');
            $journal=app(AccountingService::class)->post(['company_id'=>$company,'branch_id'=>$branch,'entry_date'=>'2026-03-03','source_type'=>'MANUAL','description'=>'Isolated manual ledger operation','created_by'=>1,'lines'=>[['account_id'=>$account,'debit'=>10,'credit'=>0],['account_id'=>$cash,'debit'=>0,'credit'=>10]]]);
            // Unknown intent does not stop GL; it stops eligibility. Never infer supplier/driver from a tag.
            DB::table('gw_exceptions')->insert(['scope_id'=>$scope,'journal_id'=>$journal]);
            return $journal;
        });
    }

    public function supplierVoucher(int $company,int $branch): int
    {
        return $this->transaction(function()use($company,$branch){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);$this->scope($company,$branch,2);
            $supplier=DB::table('suppliers')->where('company_id',$company)->where('id',99)->where('is_active',1)->first();if(!$supplier)throw new \RuntimeException('Supplier source denied');
            $number=app(SulbDocumentSequenceService::class)->reserveVoucher($company,'PAYMENT','2026-03-03');
            $id=DB::table('vouchers')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'voucher_type_id'=>2,'voucher_number'=>$number,'voucher_date'=>'2026-03-03','reference_type'=>'SUPPLIER','reference_id'=>99,'amount'=>50,'financial_account_id'=>1,'payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>50,'created_by'=>1]);
            $result=app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>$company,'voucher_id'=>$id,'created_by'=>1]);if(!$result->success)throw new \RuntimeException($result->message);
            return $id;
        });
    }

    public function driverAdvance(): int
    {
        return $this->transaction(function(){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany(1);$this->scope(1,1,9);
            $number=app(SulbDocumentSequenceService::class)->reserveVoucher(1,'PAYMENT','2026-03-03');
            $id=DB::table('vouchers')->insertGetId(['company_id'=>1,'branch_id'=>1,'voucher_type_id'=>2,'voucher_number'=>$number,'voucher_date'=>'2026-03-03','reference_type'=>'DRIVER','reference_id'=>1,'amount'=>200,'financial_account_id'=>1,'payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>200,'created_by'=>1]);
            $result=app(\App\Services\Accounting\VoucherPosting::class)->post(['company_id'=>1,'voucher_id'=>$id,'created_by'=>1]);if(!$result->success)throw new \RuntimeException($result->message);return $id;
        });
    }

    public function accruedExpense(): int
    {
        return $this->transaction(function(){
            $this->scope(1,1,2);
            $id=DB::table('expenses')->insertGetId(['company_id'=>1,'branch_id'=>1,'amount'=>40]);
            $result=app(\App\Services\Accounting\ExpensePosting::class)->post(['company_id'=>1,'branch_id'=>1,'expense_id'=>$id,'expense_account_id'=>8,'amount'=>40,'expense_date'=>'2026-03-03','payment_status'=>'UNPAID','created_by'=>1]);
            if(!$result->success)throw new \RuntimeException($result->message);return $id;
        });
    }
}
