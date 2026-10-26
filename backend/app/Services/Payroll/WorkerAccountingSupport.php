<?php
namespace App\Services\Payroll;

use App\Services\Accounting\PostingSupport;
use App\Services\Accounting\AccountingContext;
use App\Services\Auth\SessionContextService;
use App\Services\FinancialAccountService;
use App\Support\TenantScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Worker finance uses canonical GL settings, not a compatibility stub. */
final class WorkerAccountingSupport
{
    public function authorize(): int
    {
        $r=request();$user=Auth::user();$cid=TenantScope::companyId($r);
        abort_unless($user&&$user->is_active&&(int)$user->company_id===$cid,403);
        $sessions=app(SessionContextService::class);
        $actualRole=strtoupper((string)($sessions->roleForUser($user->id)->role_code??''));
        abort_unless($actualRole!==''&&strtoupper((string)$r->attributes->get('effective_role_code',''))===$actualRole,403,'سياق الصلاحيات غير صالح.');
        abort_if($actualRole==='SUPER_ADMIN',403);
        abort_if((bool)$r->attributes->get('is_support_mode',false),403,'عمليات العمال المالية غير متاحة في سياق الدعم.');
        $permissions=$sessions->permissionsForUser($user->id,$cid);
        abort_unless($permissions->contains('workers.view')&&$permissions->contains('vouchers.create'),403,'لا تملك صلاحية تنفيذ عملية مالية للعامل.');
        $mode=app(\App\Services\Subscription\SubscriptionAccessModeResolver::class)->resolve($sessions->effectiveSubscription($cid));
        abort_unless($mode===\App\Services\Subscription\SubscriptionAccessModeResolver::FULL,403,'الاشتراك لا يسمح بعمليات مالية جديدة.');
        return $cid;
    }

    public function worker(int $id): object
    {
        $cid=$this->authorize();$scope=TenantScope::branchId();
        $worker=DB::table('workers')->where('company_id',$cid)->where('id',$id)
            ->when($scope!==null,fn($q)=>$q->where('branch_id',$scope))->lockForUpdate()->first();
        abort_unless($worker,404,'العامل غير موجود في النطاق المسموح.');
        return $worker;
    }

    public function branch(object $worker): int
    {
        $branch=app(AccountingContext::class)->branchForOperation(request(),$worker->branch_id?(int)$worker->branch_id:null);
        abort_if($worker->branch_id!==null&&(int)$worker->branch_id!==$branch,403,'العامل يتبع فرعًا آخر.');
        abort_unless(DB::table('branches')->where('company_id',$this->authorize())->where('id',$branch)->where('is_active',1)->exists(),403);
        return $branch;
    }

    public function setting(int $cid,string $key): int
    {
        try{$id=app(PostingSupport::class)->setting($cid,$key);}
        catch(\RuntimeException $e){throw ValidationException::withMessages(['accounting'=>$e->getMessage()]);}
        $expected=match($key){'WORKER_LOAN_ACCOUNT'=>'ASSET','WORKER_PAYABLE_ACCOUNT'=>'LIABILITY','WORKER_COMMISSION_EXPENSE_ACCOUNT'=>'EXPENSE',default=>null};
        $a=DB::table('accounts')->where('company_id',$cid)->where('id',$id)->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->first();
        if(!$a||($expected&&strtoupper((string)$a->account_type)!==$expected))throw ValidationException::withMessages(['accounting'=>'حساب غير صالح في الإعدادات: '.$key]);
        return $id;
    }

    public function payment(int $cid,int $bid,?string $method,?int $id=null): object
    {
        $money=app(FinancialAccountService::class);
        try{$fa=$money->resolve($cid,$bid,$method,$id,'PAYMENT');}
        catch(\RuntimeException $e){throw ValidationException::withMessages(['financial_account_id'=>$e->getMessage()]);}
        // Existing worker amounts are base-currency amounts; no speculative FX policy.
        if(strtoupper((string)$fa->currency_code)!==strtoupper($money->baseCurrency($cid)))throw ValidationException::withMessages(['financial_account_id'=>'اختر حساب دفع بعملة الشركة الأساسية لعملية العامل.']);
        if(!DB::table('accounts')->where('company_id',$cid)->where('id',$fa->gl_account_id)->where('account_type','ASSET')->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists())throw ValidationException::withMessages(['financial_account_id'=>'حساب الدفع غير صالح للترحيل.']);
        return $fa;
    }

    public function commission(int $id): object
    {
        $cid=$this->authorize();$scope=TenantScope::branchId();
        $c=DB::table('worker_commissions')->where('company_id',$cid)->where('id',$id)
            ->when($scope!==null,fn($q)=>$q->where('branch_id',$scope))->lockForUpdate()->first();
        abort_unless($c,404,'العمولة غير موجودة في النطاق المسموح.');
        $worker=$this->worker((int)$c->worker_id);
        abort_if($worker->branch_id!==null&&(int)$worker->branch_id!==(int)$c->branch_id,403,'تغير فرع العامل؛ يلزم مراجعة العملية الأصلية.');
        return $c;
    }

    public function payable(object $commission): int
    {
        $entry=DB::table('journal_entries')->where('company_id',$commission->company_id)->where('branch_id',$commission->branch_id)->where('id',$commission->journal_entry_id)->where('source_type','WORKER_COMMISSION')->where('source_id',$commission->id)->where('status','POSTED')->whereNull('reversed_at')->first();
        $credits=$entry?DB::table('journal_entry_lines')->where('journal_entry_id',$entry->id)->where('company_id',$commission->company_id)->where('credit','>',0)->get():collect();
        if($credits->count()!==1||round((float)$credits[0]->credit,3)!==round((float)$commission->amount,3))throw ValidationException::withMessages(['accounting'=>'لا يمكن إثبات حساب الاستحقاق الأصلي لهذه العمولة.']);
        $id=(int)$credits[0]->account_id;
        if(!DB::table('accounts')->where('company_id',$commission->company_id)->where('id',$id)->where('account_type','LIABILITY')->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->exists())throw ValidationException::withMessages(['accounting'=>'حساب الاستحقاق التاريخي غير صالح للتسوية.']);
        return $id;
    }
}
