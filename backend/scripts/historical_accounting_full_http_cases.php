<?php
// Test-only proposed HTTP adapters. Loaded ONLY by the owned-database CLI.
use Illuminate\Support\Facades\DB;
use App\Services\Accounting\HistoricalAccountingAuthorization;
use App\Services\Accounting\InvoiceSnapshotPostingService;
use App\Services\Accounting\InvoiceAllocationService;
use App\Services\Accounting\SnapshotReturnPostingService;

final class IsolatedHistoricalHttpRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        // Tenant middleware overwrites CID/BID with authenticated scope. These
        // values are NEVER forwarded as accounting identity from validated input.
        $base=['user_id'=>'prohibited','account_id'=>'prohibited','gl_account_id'=>'prohibited','snapshot_id'=>'prohibited','policy'=>'prohibited'];
        return $base + match ($this->route()->getActionMethod()) {
            'allocate'=>['key'=>'required|string|max:100','invoice_type'=>'required|in:SALE,PURCHASE','allocations'=>'required|array|min:1|max:100','allocations.*.invoice_id'=>'required|integer|distinct','allocations.*.amount'=>'required|regex:/^\d+(\.\d{1,3})?$/'],
            'reverse'=>['date'=>'required|date','reason'=>'required|string|min:5|max:500','allocation_ids'=>'prohibited','allocation_id'=>'prohibited','allocations'=>'prohibited','amount'=>'prohibited','voucher'=>'prohibited'],
            'atomic'=>['key'=>'required|string|max:90','invoice_type'=>'required|in:SALE,PURCHASE','voucher'=>'required|array','allocations'=>'required|array|min:1|max:100','allocations.*.invoice_id'=>'required|integer|distinct','allocations.*.amount'=>'required|regex:/^\d+(\.\d{1,3})?$/'],
            'draftReturn'=>['return_type'=>'required|in:SALES_RETURN,PURCHASE_RETURN','source_invoice_id'=>'required|integer','return_date'=>'required|date','lines'=>'required|array|min:1|max:100','lines.*.source_invoice_line_id'=>'required|integer|distinct','lines.*.quantity'=>'required|numeric|gt:0'],
            default=>[],
        };
    }
}

final class IsolatedHistoricalHttpPolicy
{
    public function scope(\Illuminate\Http\Request $r,array $permissions): array
    {
        $scope=[(int)$r->attributes->get('tenant_company_id'),(int)$r->attributes->get('tenant_branch_id'),(int)$r->user()->id];
        try { HistoricalAccountingAuthorization::assert(...[...$scope,$permissions]); }
        catch (\RuntimeException) { abort(403,'Historical accounting action denied.'); }
        return $scope;
    }
}

// The exception is a server-selected action, not a request flag. This class is
// registered only in the owned database test process, never in the runtime kernel.
final class IsolatedHistoricalSubscriptionGate
{
    public function handle(\Illuminate\Http\Request $r,\Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        if($r->route()->getActionName()===IsolatedHistoricalHttpController::class.'@reverse'){
            try{\App\Services\Accounting\AtomicInvoiceVoucherService::authorize((int)$r->attributes->get('tenant_company_id'),(int)$r->attributes->get('tenant_branch_id'),(int)$r->user()->id,true);}
            catch(\RuntimeException){return response()->json(['code'=>'CORRECTIVE_ACCESS_DENIED'],403);}
            return $next($r);
        }
        return app(\App\Http\Middleware\EnsureSubscriptionAccess::class)->handle($r,$next);
    }
}
final class IsolatedHistoricalRoutePermission extends \App\Http\Middleware\EnsureRoutePermission
{
    public function handle(\Illuminate\Http\Request $r,\Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        if($r->route()->getActionName()===IsolatedHistoricalHttpController::class.'@reverse'){
            app(IsolatedHistoricalHttpPolicy::class)->scope($r,['vouchers.delete']);return $next($r);
        }
        return parent::handle($r,$next);
    }
}

final class IsolatedHistoricalHttpController extends \App\Http\Controllers\Controller
{
    private function execute(callable $operation)
    {
        try { return response()->json(['data'=>$operation()]); }
        catch (\RuntimeException $e) { return response()->json(['code'=>'ACCOUNTING_REVIEW_REQUIRED','message'=>$e->getMessage()],422); }
    }
    public function sale(IsolatedHistoricalHttpRequest $r,int $id) { return $this->invoice($r,$id,'SALE','sales.post'); }
    public function purchase(IsolatedHistoricalHttpRequest $r,int $id) { return $this->invoice($r,$id,'PURCHASE','purchases.post'); }
    private function invoice($r,$id,$kind,$permission)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,[$permission]);
        return $this->execute(fn()=>['journal_entry_id'=>app(InvoiceSnapshotPostingService::class)->post($c,$b,$kind,$id,$u,true)]);
    }
    public function allocate(IsolatedHistoricalHttpRequest $r,int $voucher)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,['vouchers.create']);$v=$r->validated();
        try{\App\Services\Accounting\AtomicInvoiceVoucherService::authorize($c,$b,$u);}catch(\RuntimeException){abort(403);}
        return $this->execute(function()use($c,$b,$u,$v,$voucher){
            $allocations=[];
            foreach($v['allocations'] as$row){
                $sid=DB::table('invoice_accounting_snapshots')->where('company_id',$c)->where('branch_id',$b)->where($v['invoice_type']==='SALE'?'sales_invoice_id':'purchase_invoice_id',$row['invoice_id'])->value('id');
                if(!$sid)throw new \RuntimeException('Scoped captured invoice required.');
                $allocations[$sid]=$row['amount'];
            }
            return ['journal_entry_id'=>app(InvoiceAllocationService::class)->post($c,$b,$voucher,$allocations,$v['key'],$u)];
        });
    }
    public function reverse(IsolatedHistoricalHttpRequest $r,int $voucher)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,['vouchers.delete']);
        return $this->execute(fn()=>['journal_entry_id'=>app(\App\Services\Accounting\AtomicInvoiceVoucherService::class)->reverse($c,$b,$u,$voucher,$r->validated()['date'],$r->validated()['reason'])]);
    }
    public function atomic(IsolatedHistoricalHttpRequest $r)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,['vouchers.create']);
        try{\App\Services\Accounting\AtomicInvoiceVoucherService::authorize($c,$b,$u);}catch(\RuntimeException){abort(403);}
        $v=$r->validated();
        return $this->execute(function()use($c,$b,$u,$v){
            $allocations=[];foreach($v['allocations']as$row){$sid=DB::table('invoice_accounting_snapshots')->where('company_id',$c)->where('branch_id',$b)->where($v['invoice_type']==='SALE'?'sales_invoice_id':'purchase_invoice_id',$row['invoice_id'])->value('id');if(!$sid)throw new \RuntimeException('Scoped captured invoice required.');$allocations[$sid]=$row['amount'];}
            return app(\App\Services\Accounting\AtomicInvoiceVoucherService::class)->create($c,$b,$u,$v['voucher'],$allocations,$v['key']);
        });
    }
    public function draftReturn(IsolatedHistoricalHttpRequest $r)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,['returns.draft']);
        return $this->execute(fn()=>['id'=>app(SnapshotReturnPostingService::class)->saveDraft($c,$b,$u,$r->validated())]);
    }
    public function postReturn(IsolatedHistoricalHttpRequest $r,int $id)
    {
        [$c,$b,$u]=app(IsolatedHistoricalHttpPolicy::class)->scope($r,['returns.post']);
        return $this->execute(fn()=>app(SnapshotReturnPostingService::class)->post($c,$b,$id,$u));
    }
}

\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$chain=['auth:sanctum','auth.context','company.context','support.access',IsolatedHistoricalSubscriptionGate::class,'tenant.scope','feature.entitlement','usage.limit',IsolatedHistoricalRoutePermission::class];
// Same URIs as production where available: catalog/permission mapping is real,
// not a mocked prefix. Replacements exist only in this process, never on disk.
foreach([['sales-invoices/{id}/post','sale'],['purchase-invoices/{id}/post','purchase'],['vouchers/{voucher}/allocations','allocate'],['vouchers/{voucher}/reverse','reverse'],['vouchers/atomic','atomic'],['commercial-returns','draftReturn'],['commercial-returns/{id}/post','postReturn']] as[$uri,$action])
    \Illuminate\Support\Facades\Route::post('api/'.$uri,[IsolatedHistoricalHttpController::class,$action])->middleware($chain)->name('isolated.historical.'.$action);
// Actual current controller is used for draft creation and plan-limit acceptance.
\Illuminate\Support\Facades\Route::post('api/sales-invoices',[\App\Http\Controllers\Api\SalesInvoiceController::class,'store'])->middleware($chain);
$http=function(?string $token,string $uri,array $body=[])use($app){
    // Older fixtures also send an explicit reason under the newly approved contract.
    if(str_ends_with($uri,'/reverse')&&!array_key_exists('reason',$body))$body['reason']='ISOLATED approved full settlement correction';
    \Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');
    $r=\Illuminate\Http\Request::create('/api/'.$uri,'POST',$body,[],[],['HTTP_ACCEPT'=>'application/json']);
    if($token)$r->headers->set('Authorization','Bearer '.$token);
    foreach(['Company','Branch','User']as$header)$r->headers->set('X-'.$header.'-ID','999999');
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($r);$kernel->terminate($r,$response);return$response;
};
$ok=function($response,string $label){check($response->getStatusCode()<300,$label);if($response->getStatusCode()>=300)throw new \RuntimeException($label.' HTTP '.$response->getStatusCode().' '.$response->getContent());return json_decode($response->getContent(),true)['data'];};
$owner=\App\Models\User::findOrFail($uid)->createToken('ISOLATED FULL HTTP')->plainTextToken;
$reader=\App\Models\User::findOrFail($httpUser)->createToken('ISOLATED FULL READER')->plainTextToken;
$plan=fixture('plans',['plan_code'=>'HTTP-TEST','plan_name'=>'ISOLATED HTTP','max_invoices'=>null]);
$sub=fixture('subscriptions',['company_id'=>$cid,'plan_id'=>$plan,'start_date'=>now()->subDay()->toDateString(),'end_date'=>now()->addMonth()->toDateString(),'status'=>'ACTIVE']);
foreach(['sales','purchases','accounting']as$feature)fixture('plan_features',['plan_id'=>$plan,'feature_code'=>$feature,'is_enabled'=>1]);
$a=fixture('plan_features',['plan_id'=>$plan,'feature_code'=>'max_documents','limit_value'=>9999]);
check($http(null,'sales-invoices/'.$httpSource.'/post')->getStatusCode()===401,'FULL HTTP anonymous denied');
$r=$http($reader,'sales-invoices/'.$httpSource.'/post');check($r->getStatusCode()===403,'FULL HTTP actual route permission denied');if($r->getStatusCode()!==403)throw new \RuntimeException('Reader denial '.$r->getStatusCode().' '.$r->getContent());
// Correct company context is mandatory even for platform tokens.
$platformActual=fixture('users',['company_id'=>null,'username'=>'HTTP_PLATFORM_ONLY','name'=>'HTTP PLATFORM','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);
fixture('user_roles',['company_id'=>null,'user_id'=>$platformActual,'role_id'=>$platformRole,'is_active'=>1]);
$pt=\App\Models\User::findOrFail($platformActual)->createToken('ISOLATED PLATFORM',['platform-admin'])->plainTextToken;
check($http($pt,'sales-invoices/'.$httpSource.'/post')->getStatusCode()===403,'FULL HTTP platform cannot enter tenant posting');

// Real durable support creation (not a spoofed READ_ONLY attribute).
$supportRequest=\Illuminate\Http\Request::create('/api/companies/'.$cid.'/support-access','POST');
$actor=\App\Models\User::findOrFail($platformActual);$actor->withAccessToken(\Laravel\Sanctum\PersonalAccessToken::findToken($pt));$supportRequest->setUserResolver(fn()=>$actor);$supportRequest->attributes->set('actual_role_code','SUPER_ADMIN');
$support=app(\App\Services\Support\SupportSessionService::class)->create($supportRequest,$actor,['company_id'=>$cid,'branch_id'=>$bid,'access_level'=>'READ_ONLY','reason'=>'ISOLATED HTTP ONLY','ticket_reference'=>'TEST-HTTP','expires_at'=>now()->addHour()]);
$denied=$http($support['plain_text_token'],'sales-invoices/'.$httpSource.'/post');
check($denied->getStatusCode()===403&&str_contains($denied->getContent(),'SUPPORT_WRITE_DENIED'),'FULL HTTP durable READ_ONLY denied by real middleware');
foreach(['purchase-invoices/'.$httpSource.'/post','vouchers/1/allocations','vouchers/1/reverse','commercial-returns']as$uri){$r=$http($support['plain_text_token'],$uri);check($r->getStatusCode()===403&&str_contains($r->getContent(),'SUPPORT_WRITE_DENIED'),'FULL HTTP READ_ONLY rejects '.$uri);}

// Existing service/stock fixture is reused, not historical production data.
\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
foreach(['SALE','PURCHASE']as$kind){
    $source=$realDraft($kind,[['item_id'=>$item,'quantity'=>10,'unit_price'=>10,'vat_percent'=>0]]);$uri=($kind==='SALE'?'sales':'purchase').'-invoices/'.$source.'/post';
    $j=$ok($http($owner,$uri),'FULL HTTP '.$kind.' post')['journal_entry_id'];
    $s=$snapshotFor($kind,$source);check($s&&$s->journal_entry_id==$j,'FULL HTTP '.$kind.' snapshot saved atomically');
    check($ok($http($owner,$uri),'FULL HTTP '.$kind.' post retry')['journal_entry_id']===$j,'FULL HTTP '.$kind.' one journal on retry');
    $partyTable=$kind==='SALE'?'customers':'suppliers';$partyId=$kind==='SALE'?$customer:$supplier;
    $replacement=$kind==='SALE'?((int)$s->party_account_id===$gl['new_ar']?$gl['ar']:$gl['new_ar']):((int)$s->party_account_id===$gl['new_ap']?$gl['ap']:$gl['new_ap']);
    DB::table($partyTable)->where('id',$partyId)->update(['ledger_account_id'=>$replacement]);
    check($replacement!==(int)$s->party_account_id,'FULL HTTP '.$kind.' party mapping actually changed after posting');
    $vouchers=[];
    foreach(['40.000','60.000']as$n=>$amount){
        $v=$voucher($kind==='SALE',(float)$amount);$vouchers[]=$v;$payload=['key'=>'HTTP-'.$kind.'-'.$n,'invoice_type'=>$kind,'allocations'=>[['invoice_id'=>$source,'amount'=>$amount]]];
        $aj=$ok($http($owner,'vouchers/'.$v.'/allocations',$payload),'FULL HTTP '.$kind.' settlement '.$amount)['journal_entry_id'];
        check(DB::table('journal_entry_lines')->where('journal_entry_id',$aj)->where('account_id',$s->party_account_id)->exists(),'FULL HTTP settlement keeps original party GL');
        check($ok($http($owner,'vouchers/'.$v.'/allocations',$payload),'FULL HTTP allocation retry')['journal_entry_id']===$aj,'FULL HTTP allocation retry stable');
        check($http($reader,'vouchers/'.$v.'/allocations',$payload)->getStatusCode()===403,'FULL HTTP unauthorized allocation denied');
    }
    \Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
    check(app(InvoiceAllocationService::class)->outstandingDecimal($s)==='0.000'&&DB::table($kind==='SALE'?'sales_invoices':'purchase_invoices')->where('id',$source)->value('payment_status')==='PAID','FULL HTTP '.$kind.' fully paid projection');
    $v=$voucher($kind==='SALE',1);check($http($owner,'vouchers/'.$v.'/allocations',['key'=>'OVER-'.$kind,'invoice_type'=>$kind,'allocations'=>[['invoice_id'=>$source,'amount'=>'1.000']]])->getStatusCode()===422,'FULL HTTP overpayment rejected');
    $rev=$ok($http($owner,'vouchers/'.$vouchers[1].'/reverse',['date'=>'2026-10-10']),'FULL HTTP '.$kind.' reverse allocation')['journal_entry_id'];
    $count=DB::table('journal_entries')->count();check($http($owner,'vouchers/'.$vouchers[1].'/reverse',['date'=>'2026-10-10'])->getStatusCode()===422&&DB::table('journal_entries')->count()===$count,'FULL HTTP reversal repeat rejected without duplicate');
    check($http($reader,'vouchers/'.$vouchers[1].'/reverse',['date'=>'2026-10-10'])->getStatusCode()===403,'FULL HTTP unauthorized reversal denied');
    \Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
    check(app(InvoiceAllocationService::class)->outstandingDecimal($s)==='60.000','FULL HTTP '.$kind.' reverse restores outstanding');
}

// Independent unpaid stock originals: paid returns remain closed pending policy.
\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
$hp=$realDraft('PURCHASE',[['item_id'=>$cycleItem,'qty_kg'=>20,'price_unit'=>'KG','unit_price'=>10,'vat_percent'=>0]]);
$ok($http($owner,'purchase-invoices/'.$hp.'/post'),'FULL HTTP stock purchase');
\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
$hs=$realDraft('SALE',[['item_id'=>$cycleItem,'qty_kg'=>10,'price_unit'=>'KG','unit_price'=>20,'vat_percent'=>0]]);
$ok($http($owner,'sales-invoices/'.$hs.'/post'),'FULL HTTP stock sale');
DB::table('items')->where('id',$cycleItem)->update(['inventory_account_id'=>$gl['inventory'],'cogs_account_id'=>$gl['cogs']]);
foreach([['SALES_RETURN',$hs,'sales_invoice_lines','sales_invoice_id',[2,8]],['PURCHASE_RETURN',$hp,'purchase_invoice_lines','purchase_invoice_id',[2,18]]]as[$type,$source,$table,$fk,$quantities]){
    $line=DB::table($table)->where($fk,$source)->value('id');$snap=$snapshotFor($type==='SALES_RETURN'?'SALE':'PURCHASE',$source);
    foreach($quantities as$quantity){
        $rid=$ok($http($owner,'commercial-returns',['return_type'=>$type,'source_invoice_id'=>$source,'return_date'=>'2026-10-10','lines'=>[['source_invoice_line_id'=>$line,'quantity'=>$quantity]]]),'FULL HTTP '.$type.' draft')['id'];
        $ok($http($owner,'commercial-returns/'.$rid.'/post'),'FULL HTTP '.$type.' partial/final post');
        $count=DB::table('journal_entries')->count();$ok($http($owner,'commercial-returns/'.$rid.'/post'),'FULL HTTP return retry');check(DB::table('journal_entries')->count()===$count,'FULL HTTP return retry no journal');
        check($http($reader,'commercial-returns/'.$rid.'/post')->getStatusCode()===403,'FULL HTTP unauthorized return denied');
    }
}

$target='sales-invoices/'.$hs.'/post';$n=DB::table('journal_entries')->count();
foreach(['SUSPENDED','EXPIRED','CANCELLED']as$status){DB::table('subscriptions')->where('id',$sub)->update(['status'=>$status]);check($http($owner,$target)->getStatusCode()===403,'FULL HTTP subscription '.$status.' blocks mutation');}
DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','sales')->update(['is_enabled'=>0]);$r=$http($owner,$target);check($r->getStatusCode()===403&&str_contains($r->getContent(),'FEATURE_NOT_ENTITLED'),'FULL HTTP sales entitlement denies post');
DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','sales')->update(['is_enabled'=>1]);
check($http($owner,$target,['gl_account_id'=>$gl['cash']])->getStatusCode()===422,'FULL HTTP GL override prohibited');
check($http($owner,$target,['user_id'=>$httpUser])->getStatusCode()===422,'FULL HTTP client UID prohibited');
$foreignGl=fixture('accounts',['company_id'=>$foreign,'account_code'=>'HTTP-FOREIGN','account_name'=>'TEST','account_type'=>'ASSET','normal_side'=>'DEBIT','is_group'=>0,'allow_posting'=>1,'is_active'=>1]);
$foreignFa=fixture('financial_accounts',['company_id'=>$foreign,'branch_id'=>$foreignBid,'account_code'=>'HTTP-FOREIGN','account_name'=>'HTTP FOREIGN','account_type'=>'CASH','currency_code'=>'SAR','gl_account_id'=>$foreignGl,'is_active'=>1]);
check($http($owner,$target,['financial_account_id'=>$foreignFa])->getStatusCode()===404,'FULL HTTP foreign operational financial account rejected');
check($http($owner,'sales-invoices/'.$foreignSource.'/post')->getStatusCode()===404,'FULL HTTP foreign source rejected by tenant middleware');
$branchActor=DB::table('users')->where('username','TEST-NO-PERMISSION')->value('id'); // ordinary role, sales.post only
$branchToken=\App\Models\User::findOrFail($branchActor)->createToken('ISOLATED BRANCH HTTP')->plainTextToken;
$otherBranch=fixture('branches',['company_id'=>$cid,'branch_name'=>'HTTP OTHER','is_active'=>1]);$old=DB::table('sales_invoices')->where('id',$hs)->value('branch_id');DB::table('sales_invoices')->where('id',$hs)->update(['branch_id'=>$otherBranch]);$r=$http($branchToken,$target);check($r->getStatusCode()===404,'FULL HTTP foreign branch source rejected');DB::table('sales_invoices')->where('id',$hs)->update(['branch_id'=>$old]);
$r=$http($branchToken,$target,['company_id'=>$foreign,'branch_id'=>$foreignBid]);check($r->getStatusCode()===200&&DB::table('sales_invoices')->where('id',$hs)->value('company_id')==$cid&&DB::table('sales_invoices')->where('id',$hs)->value('branch_id')==$bid,'FULL HTTP forged CID/BID replaced with authenticated context');
check($http($owner,$target,['branch_id'=>$foreignBid])->getStatusCode()===403,'FULL HTTP company admin cannot select foreign company branch');
check(DB::table('journal_entries')->count()===$n,'FULL HTTP security/subscription denials create no journal');
DB::table('plan_features')->where('id',$a)->update(['limit_value'=>0]);
$r=$http($owner,'sales-invoices',['invoice_date'=>'2026-10-10','customer_id'=>$customer,'currency_code'=>'SAR','exchange_rate'=>1,'items'=>[['item_id'=>$item,'quantity'=>1,'unit_price'=>1]]]);
check($r->getStatusCode()===409,'FULL HTTP plan document limit prevents creation');
if($r->getStatusCode()!==409)echo 'HTTP_DIAGNOSTIC limit '.$r->getStatusCode().' '.$r->getContent().PHP_EOL;
DB::table('plan_features')->where('id',$a)->update(['limit_value'=>9999]);
DB::table('subscriptions')->where('id',$sub)->update(['status'=>'TRIAL']);check($http($owner,$target)->getStatusCode()===200,'FULL HTTP valid TRIAL permits authorized retry');DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','accounting')->update(['is_enabled'=>0]);
$r=$http($owner,'vouchers/'.$vouchers[1].'/reverse',['date'=>'2026-10-10']);check($r->getStatusCode()===422&&str_contains($r->getContent(),'already reversed'),'FULL HTTP approved corrective access still rejects reversed settlement with accounting disabled');
DB::table('plan_features')->where('plan_id',$plan)->where('feature_code','accounting')->update(['is_enabled'=>1]);
require __DIR__.'/historical_accounting_http_limit_cases.php';
require __DIR__.'/historical_accounting_atomic_http_cases.php';
require __DIR__.'/historical_accounting_corrective_http_cases.php';
unset($owner,$reader,$pt,$support,$actor,$branchToken);
\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));$app->instance('request',\Illuminate\Http\Request::create('/'));
