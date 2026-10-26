<?php
namespace App\Services;

use App\Services\Accounting\HistoricalDecimal as Decimal;
use App\Services\Auth\SessionContextService;
use App\Services\Entitlement\EffectiveEntitlementService;
use App\Services\Subscription\SubscriptionAccessModeResolver;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Experimental. No routes, no ordinary DRIVER voucher posting, no historical enrollment. */
class DriverCostSettlement
{
    private function authorize(int $company,int $branch): int
    {
        DriverSettlementIsolation::assert();
        $id=(int)Auth::id();$user=$id?DB::table('users')->where('id',$id)->where('is_active',1)->first():null;
        if(!$user || (int)$user->company_id!==$company || ($user->branch_id!==null && (int)$user->branch_id!==$branch)) throw new \RuntimeException('Actor/company/branch denied.');
        if(!DB::table('branches')->where('company_id',$company)->where('id',$branch)->where('is_active',1)->exists()) throw new \RuntimeException('Branch denied.');
        $context=app(SessionContextService::class);$role=$context->roleForUser($id);
        if(!$role || strtoupper($role->role_code)==='SUPER_ADMIN' || request()->attributes->get('is_support_mode') || request()->attributes->get('support_access_level')==='READ_ONLY') throw new \RuntimeException('Support/platform mutations not activated.');
        if($user->branch_id===null && !in_array(strtoupper($role->role_code),['MANAGER','COMPANY_MANAGER','COMPANY_ADMIN','COMPANY_OWNER','ADMIN'],true))throw new \RuntimeException('Actor requires an assigned branch.');
        $permissions=$context->permissionsForUser($id,$company);
        foreach(['shipments.cost','vouchers.create'] as $permission) if(!$permissions->contains($permission)) throw new \RuntimeException('Permission denied: '.$permission);
        if(app(SubscriptionAccessModeResolver::class)->resolve(app(SubscriptionLifecycleService::class)->effectiveForCompany($company))!==SubscriptionAccessModeResolver::FULL) throw new \RuntimeException('Subscription must permit financial writes.');
        foreach(['accounting','shipments'] as $feature) if(!app(EffectiveEntitlementService::class)->allows($company,$feature)) throw new \RuntimeException('Entitlement denied: '.$feature);
        return $id;
    }

    /** Capture a newly created origin in this transaction only. Existing cost IDs cannot enroll. */
    public function createControlledCost(array $input): int
    {
        $company=(int)($input['company_id']??0);$branch=(int)($input['branch_id']??0);$actor=$this->authorize($company,$branch);
        return DB::transaction(function()use($input,$company,$branch,$actor){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
            if(DB::table('shipment_costs')->where('company_id',$company)->where('shipment_id',$input['shipment_id'])->where('cost_status','DRAFT')->exists()) throw new \RuntimeException('Existing drafts cannot be enrolled/posted by controlled origin creation.');
            $cost=app(ShipmentCostService::class)->storeDraft([...$input,'created_by'=>$actor]);
            app(ShipmentCostService::class)->postPendingForShipment($company,(int)$input['shipment_id'],$actor,$branch);
            [$c,$line]=$this->original($company,$branch,$cost,(int)($input['payee_id']??0));
            $this->policy($c,$line);
            if($this->unallocatedDebits($c,$line)>0) throw new \RuntimeException('UNALLOCATED_LEDGER_MOVEMENT');
            DB::table('shipment_cost_settlement_requests')->insert([
                ...$this->identity($c,$line),'operation'=>'ORIGIN','request_key'=>'origin:'.$cost,'request_hash'=>hash('sha256','origin:'.$cost),
                'amount'=>'0.000','currency_code'=>$c->currency_code,'financial_account_id'=>null,'effective_date'=>$c->expense_date,
                'actor_id'=>$actor,'actor_name'=>Auth::user()->name,'status'=>'COMPLETED','journal_entry_id'=>$c->journal_entry_id,
                'voucher_id'=>null,'paid_after'=>'0.000','remaining_after'=>$c->amount,'created_at'=>now(),'completed_at'=>now()]);
            return $cost;
        });
    }

    private function original(int $company,int $branch,int $cost,int $driver): array
    {
        $c=DB::table('shipment_costs')->where('company_id',$company)->where('branch_id',$branch)->where('id',$cost)->lockForUpdate()->first();
        if(!$c || $c->payee_type!=='DRIVER' || (int)$c->payee_id!==$driver) throw new \RuntimeException('INCOMPLETE_EVIDENCE: driver/cost scope');
        if($c->payment_status==='PAID') throw new \RuntimeException('DIRECT_PAID');
        $d=DB::table('drivers')->where('company_id',$company)->where('id',$driver)->where('is_active',1)->first();
        $s=DB::table('shipments')->where('company_id',$company)->where('branch_id',$branch)->where('id',$c->shipment_id)->first();
        if(!$d || !$s || ($d->branch_id!==null && (int)$d->branch_id!==$branch) || $c->cost_status!=='POSTED' || $c->financial_account_id) throw new \RuntimeException('INCOMPLETE_EVIDENCE');
        $e=DB::table('journal_entries')->where('company_id',$company)->where('branch_id',$branch)->where('id',$c->journal_entry_id)->lockForUpdate()->first();
        if(!$e || $e->status!=='POSTED' || $e->source_type!=='SHIPMENT_COST' || (int)$e->source_id!==$cost || $e->reversed_by_id || $e->reversal_of_id || $e->reversed_at
            || DB::table('journal_entries')->where('company_id',$company)->where('reversal_of_id',$e->id)->exists()
            || DB::table('journal_entries')->where('company_id',$company)->where('source_type','SHIPMENT_COST')->where('source_id',$cost)->count()!==1) throw new \RuntimeException('INCOMPLETE_EVIDENCE: source/reversal');
        $lines=DB::table('journal_entry_lines')->where('journal_entry_id',$e->id)->orderBy('id')->lockForUpdate()->get();$balance=0;
        foreach($lines as $l){
            if((int)$l->company_id!==$company || (int)$l->branch_id!==$branch || !DB::table('accounts')->where('company_id',$company)->where('id',$l->account_id)->exists()) throw new \RuntimeException('INCOMPLETE_EVIDENCE: GL scope');
            $debit=Decimal::units($l->debit);$credit=Decimal::units($l->credit);if($debit<0||$credit<0)throw new \RuntimeException('INCOMPLETE_EVIDENCE');$balance+=$debit-$credit;
        }
        $credits=$lines->filter(fn($l)=>Decimal::units($l->credit)>0);
        $l=$credits->first();
        if($balance!==0 || $credits->count()!==1 || !$l || $l->party_type!=='DRIVER' || (int)$l->party_id!==$driver || $l->financial_account_id || Decimal::units($l->debit)!==0 || Decimal::units($l->credit)!==Decimal::units($c->amount)) throw new \RuntimeException('INCOMPLETE_EVIDENCE: original credit');
        return [$c,$l];
    }

    private function policy(object $cost,object $line): void
    {
        $base=DB::table('company_settings')->where('company_id',$cost->company_id)->value('base_currency_code');
        $a=DB::table('accounts')->where('company_id',$cost->company_id)->where('id',$line->account_id)->first();
        if($base!=='SAR' || $cost->currency_code!=='SAR' || $line->currency_code!=='SAR' || bccomp((string)$cost->exchange_rate,'1',10)!==0
            || bccomp((string)$line->exchange_rate,'1',10)!==0 || Decimal::units($cost->foreign_amount)!==Decimal::units($cost->amount)
            || Decimal::units($cost->amount)%10!==0 || !$a || !$a->is_active || !$a->allow_posting || $a->is_group || $a->account_type!=='LIABILITY') throw new \RuntimeException('BLOCKED_POLICY');
    }

    private function identity(object $c,object $l): array
    {
        return ['company_id'=>$c->company_id,'branch_id'=>$c->branch_id,'driver_id'=>$c->payee_id,'shipment_cost_id'=>$c->id,
            'original_journal_id'=>$c->journal_entry_id,'original_line_id'=>$l->id,'original_account_id'=>$l->account_id];
    }

    private function unallocatedDebits(object $c,object $l): int
    {
        $sum=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')
            ->where('l.company_id',$c->company_id)->where('e.company_id',$c->company_id)->where('l.branch_id',$c->branch_id)->where('e.status','POSTED')
            ->where(fn($q)=>$q->whereNull('l.party_type')->orWhere(fn($q)=>$q->where('l.party_type','DRIVER')->where('l.party_id',$c->payee_id)))->where('l.account_id',$l->account_id)->where('l.debit','>',0)
            ->whereNotIn('l.id',DB::table('shipment_cost_settlement_allocations')->where('company_id',$c->company_id)->select('payment_line_id'))->sum('l.debit');
        return Decimal::units($sum);
    }

    public function settle(int $company,int $branch,int $driver,int $cost,int $financialAccount,string $date,string $amount,string $key): array
    {
        $actor=$this->authorize($company,$branch);$units=Decimal::units($amount);
        if($units<=0 || $units%10!==0 || $units>999999999999999 || !preg_match('/^[A-Za-z0-9_.:-]{1,90}$/D',$key)
            || !validator(['date'=>$date],['date'=>'required|date_format:Y-m-d'])->passes()) throw new \RuntimeException('BLOCKED_POLICY: invalid exact amount/key/date');
        $amount=Decimal::format($units);
        $hash=hash('sha256',json_encode([$company,$branch,$driver,$cost,$financialAccount,$date,$amount,$actor],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($company,$branch,$driver,$cost,$financialAccount,$date,$amount,$key,$units,$hash,$actor){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
            $prior=DB::table('shipment_cost_settlement_requests')->where('company_id',$company)->where('operation','PAYMENT')->where('request_key',$key)->lockForUpdate()->first();
            if($prior){if($prior->request_hash!==$hash || $prior->status!=='COMPLETED')throw new \RuntimeException('IDEMPOTENCY_CONFLICT');return $this->result($prior->id);}
            [$c,$line]=$this->original($company,$branch,$cost,$driver);$this->policy($c,$line);
            if($date<$c->expense_date)throw new \RuntimeException('BLOCKED_POLICY: payment predates original');
            $origin=DB::table('shipment_cost_settlement_requests')->where('company_id',$company)->where('operation','ORIGIN')->where('request_key','origin:'.$cost)->where('status','COMPLETED')->lockForUpdate()->first();
            if(!$this->matchesOrigin($origin,$c,$line)) throw new \RuntimeException('HISTORICAL_REVIEW_REQUIRED');
            if($this->unallocatedDebits($c,$line)>0)throw new \RuntimeException('UNALLOCATED_LEDGER_MOVEMENT');
            $allocated=Decimal::units(DB::table('shipment_cost_settlement_allocations')->where('company_id',$company)->where('shipment_cost_id',$cost)->sum('amount'));
            if($units>Decimal::units($c->amount)-$allocated)throw new \RuntimeException('OVERPAYMENT');
            $fa=DB::table('financial_accounts')->where('company_id',$company)->where('id',$financialAccount)->where('is_active',1)->lockForUpdate()->first();
            if(!$fa || ($fa->branch_id!==null&&(int)$fa->branch_id!==$branch) || $fa->currency_code!=='SAR' || !in_array($fa->account_type,['CASH','BANK'],true) || (int)$fa->gl_account_id===(int)$line->account_id)throw new \RuntimeException('BLOCKED_POLICY: financial account');
            $cash=DB::table('accounts')->where('company_id',$company)->where('id',$fa->gl_account_id)->first();
            if(!$cash || !$cash->is_active || !$cash->allow_posting || $cash->is_group || $cash->account_type!=='ASSET')throw new \RuntimeException('BLOCKED_POLICY: payment GL');
            $type=DB::table('voucher_types')->where('type_code','PAYMENT')->value('id');if(!$type)throw new \RuntimeException('Payment voucher type missing.');
            $request=DB::table('shipment_cost_settlement_requests')->insertGetId([...$this->identity($c,$line),'operation'=>'PAYMENT','request_key'=>$key,'request_hash'=>$hash,'amount'=>$amount,'currency_code'=>'SAR','financial_account_id'=>$financialAccount,'effective_date'=>$date,'actor_id'=>$actor,'actor_name'=>Auth::user()->name,'status'=>'PENDING','created_at'=>now()]);
            $number=app(SulbDocumentSequenceService::class)->reserveVoucher($company,'PAYMENT',$date);
            $this->checkpoint('after_number');
            $voucher=DB::table('vouchers')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'voucher_type_id'=>$type,'voucher_number'=>$number,'voucher_date'=>$date,
                'reference_type'=>'SHIPMENT_COST_SETTLEMENT','reference_id'=>$cost,'amount'=>$amount,'financial_account_id'=>$financialAccount,'cash_account_id'=>$fa->gl_account_id,'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>$amount,'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('shipment_cost_settlement_requests')->where('id',$request)->update(['voucher_id'=>$voucher]);
            $journal=app(AccountingService::class)->post(['company_id'=>$company,'branch_id'=>$branch,'entry_date'=>$date,'source_type'=>'DRIVER_SHIPMENT_SETTLEMENT','source_id'=>$request,'description'=>'سداد تكلفة شحنة '.$c->id.' — '.$number,'currency_code'=>'SAR','exchange_rate'=>1,'created_by'=>$actor,'is_system_generated'=>1,'lines'=>[
                ['account_id'=>$line->account_id,'party_type'=>'DRIVER','party_id'=>$driver,'debit'=>$amount,'credit'=>0,'currency_code'=>'SAR','exchange_rate'=>1],
                ['account_id'=>$fa->gl_account_id,'financial_account_id'=>$financialAccount,'debit'=>0,'credit'=>$amount,'currency_code'=>'SAR','exchange_rate'=>1]]]);
            $this->checkpoint('after_journal');
            $paymentLine=DB::table('journal_entry_lines')->where('journal_entry_id',$journal)->where('party_type','DRIVER')->where('debit','>',0)->value('id');
            DB::table('vouchers')->where('id',$voucher)->update(['journal_entry_id'=>$journal]);
            DB::table('shipment_cost_settlement_allocations')->insert([...$this->identity($c,$line),'request_id'=>$request,'voucher_id'=>$voucher,'payment_journal_id'=>$journal,'payment_line_id'=>$paymentLine,'amount'=>$amount,'currency_code'=>'SAR','effective_date'=>$date,'actor_id'=>$actor,'created_at'=>now()]);
            $this->checkpoint('after_allocation');
            DB::table('shipment_cost_settlement_requests')->where('id',$request)->update(['status'=>'COMPLETED','journal_entry_id'=>$journal,'paid_after'=>Decimal::format($allocated+$units),'remaining_after'=>Decimal::format(Decimal::units($c->amount)-$allocated-$units),'completed_at'=>now()]);
            return $this->result($request);
        });
    }

    protected function checkpoint(string $stage): void {}

    public function eligibility(int $company,int $branch,int $driver,int $cost): array
    {
        $this->authorize($company,$branch);
        return DB::transaction(function()use($company,$branch,$driver,$cost){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($company);
            try{
                [$c,$line]=$this->original($company,$branch,$cost,$driver);$this->policy($c,$line);
                if($this->unallocatedDebits($c,$line)>0)return ['eligibility'=>'UNALLOCATED_LEDGER_MOVEMENT','remaining'=>null];
                $origin=DB::table('shipment_cost_settlement_requests')->where('company_id',$company)->where('operation','ORIGIN')->where('request_key','origin:'.$cost)->where('status','COMPLETED')->first();
                if(!$this->matchesOrigin($origin,$c,$line))return ['eligibility'=>'HISTORICAL_REVIEW_REQUIRED','remaining'=>null];
                $paid=Decimal::units(DB::table('shipment_cost_settlement_allocations')->where('company_id',$company)->where('shipment_cost_id',$cost)->sum('amount'));
                return ['eligibility'=>'NEW_VERIFIED','remaining'=>Decimal::format(Decimal::units($c->amount)-$paid)];
            }catch(\RuntimeException $e){$code=explode(':',$e->getMessage())[0];if(!in_array($code,['DIRECT_PAID','INCOMPLETE_EVIDENCE','BLOCKED_POLICY'],true))throw $e;return ['eligibility'=>$code,'remaining'=>null];}
        });
    }

    private function matchesOrigin(?object $origin,object $cost,object $line): bool
    {
        if(!$origin || $origin->currency_code!=='SAR' || (int)$origin->journal_entry_id!==(int)$cost->journal_entry_id || Decimal::units($origin->paid_after)!==0 || Decimal::units($origin->remaining_after)!==Decimal::units($cost->amount))return false;
        foreach($this->identity($cost,$line) as $field=>$value)if((int)$origin->$field!==(int)$value)return false;
        return true;
    }

    public function result(int $request): array
    {
        DriverSettlementIsolation::assert();$r=DB::table('shipment_cost_settlement_requests')->where('id',$request)->first();
        if(!$r)throw new \RuntimeException('Unknown request.');$this->authorize((int)$r->company_id,(int)$r->branch_id);
        return ['request_id'=>$r->id,'voucher_id'=>$r->voucher_id,'journal_entry_id'=>$r->journal_entry_id,'amount'=>$r->amount,'paid'=>$r->paid_after,'remaining'=>$r->remaining_after];
    }
}
