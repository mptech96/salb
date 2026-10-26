<?php

namespace App\Services\Payroll;

use App\Services\Accounting\HistoricalDecimal;
use App\Services\Accounting\IsolatedHistoricalAccountingGate;
use App\Services\AccountingService;
use App\Services\SulbDocumentSequenceService;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Experimental adapter. No runtime route. Must never operate outside an owned test DB. */
final class IsolatedWorkerCreationRequest
{
    public function transition(string $operation, int $commissionId, string $key, array $payload=[]): array
    {
        IsolatedHistoricalAccountingGate::assert();
        if(!in_array($operation,['APPROVE_COMMISSION','PAY_COMMISSION'],true)||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key))
            throw new \RuntimeException('Invalid worker operation or request key.');
        if(array_diff(array_keys($payload),$operation==='PAY_COMMISSION'?['payment_method','financial_account_id']:[]))
            throw new \RuntimeException('Unsupported request fields.');
        if($operation==='PAY_COMMISSION'){
            $payload['payment_method']??='CASH';
            if(isset($payload['financial_account_id'])){
                if(!ctype_digit((string)$payload['financial_account_id'])||(int)$payload['financial_account_id']<1)throw new \RuntimeException('Invalid account reference.');
                $payload['financial_account_id']=(int)$payload['financial_account_id'];
            }
        }
        ksort($payload);
        $companyId=app(WorkerAccountingSupport::class)->authorize();
        return DB::transaction(function()use($operation,$commissionId,$key,$payload,$companyId){
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($companyId);
            $commission=app(WorkerAccountingSupport::class)->commission($commissionId);
            $hash=hash('sha256',json_encode(['version'=>1,'company'=>$companyId,'branch'=>(int)$commission->branch_id,
                'operation'=>$operation,'commission'=>$commissionId,'worker'=>(int)$commission->worker_id,
                'amount'=>HistoricalDecimal::format(HistoricalDecimal::units($commission->amount)),'payload'=>$payload],JSON_THROW_ON_ERROR));
            $prior=DB::table('worker_operation_requests')->where('company_id',$companyId)->where('operation',$operation)->where('request_key',$key)->lockForUpdate()->first();
            if($prior){
                if(!hash_equals($prior->request_hash,$hash)||(int)$prior->actor_id!==(int)auth()->id())throw new \RuntimeException('Request key reused with different content or actor.');
                return json_decode($prior->result_json,true,512,JSON_THROW_ON_ERROR);
            }
            $previous=request();
            $transitionRequest=Request::create('/','POST',$payload);
            $transitionRequest->attributes->replace($previous->attributes->all());
            $transitionRequest->setUserResolver(fn()=>auth()->user());
            app()->instance('request',$transitionRequest);
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
            try{
                $response=$operation==='APPROVE_COMMISSION'
                    ? app(WorkerController::class)->approveCommission($commissionId,app(AccountingService::class))
                    : app(WorkerController::class)->payCommission($commissionId,app(AccountingService::class));
            }finally{
                app()->instance('request',$previous);
                \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
            }
            if($response->getStatusCode()!==200)throw new \RuntimeException('Commission transition rejected: '.$response->getContent());
            $result=$response->getData(true);
            DB::table('worker_operation_requests')->insert(['company_id'=>$companyId,'branch_id'=>$commission->branch_id,'worker_id'=>$commission->worker_id,
                'operation'=>$operation,'request_key'=>$key,'request_hash'=>$hash,'hash_version'=>1,'actor_id'=>auth()->id(),
                'loan_id'=>null,'commission_id'=>$commissionId,'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        });
    }

    public function create(string $operation, int $workerId, string $key, array $payload): array
    {
        IsolatedHistoricalAccountingGate::assert();
        if (!in_array($operation, ['CREATE_LOAN','CREATE_COMMISSION'], true)
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            throw new \RuntimeException('Invalid worker operation or request key.');
        }
        $allowed = $operation === 'CREATE_LOAN'
            ? ['loan_date','amount','payment_method','financial_account_id','notes']
            : ['commission_date','amount','status','shipment_id','sales_invoice_id','payment_method','financial_account_id','notes'];
        if (array_diff(array_keys($payload), $allowed)) throw new \RuntimeException('Unsupported request fields.');
        $canonical = $payload;
        $canonical['amount'] = HistoricalDecimal::format(HistoricalDecimal::units($payload['amount'] ?? ''));
        foreach (['financial_account_id','shipment_id','sales_invoice_id'] as $field) {
            if (isset($canonical[$field])) {
                if (!ctype_digit((string)$canonical[$field]) || (int)$canonical[$field] < 1) throw new \RuntimeException('Invalid reference.');
                $canonical[$field] = (int)$canonical[$field];
            }
        }
        if ($operation === 'CREATE_COMMISSION') $canonical['status'] ??= 'PENDING';
        $canonical['payment_method'] ??= 'CASH';
        $canonical['notes'] ??= null;
        ksort($canonical);
        $companyId=app(WorkerAccountingSupport::class)->authorize();
        return DB::transaction(function() use ($operation,$workerId,$key,$canonical,$companyId) {
            app(SulbDocumentSequenceService::class)->lockVoucherCompany($companyId);
            $worker=app(WorkerAccountingSupport::class)->worker($workerId);
            $branchId=app(WorkerAccountingSupport::class)->branch($worker);
            $hash=hash('sha256',json_encode(['version'=>1,'company'=>$companyId,'branch'=>$branchId,
                'operation'=>$operation,'worker'=>$workerId,'payload'=>$canonical],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $prior=DB::table('worker_operation_requests')->where('company_id',$companyId)
                ->where('operation',$operation)->where('request_key',$key)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash,$hash) || (int)$prior->actor_id !== (int)auth()->id())
                    throw new \RuntimeException('Request key already used with different content or actor.');
                return json_decode($prior->result_json,true,512,JSON_THROW_ON_ERROR);
            }
            $controller=app(WorkerController::class);
            $request=Request::create('/', 'POST', $canonical);
            $request->setUserResolver(fn()=>auth()->user());
            $response=$operation==='CREATE_LOAN'
                ? $controller->addLoan($request,$workerId,app(AccountingService::class))
                : $controller->addCommission($request,$workerId,app(AccountingService::class));
            if ($response->getStatusCode() !== 200) throw new \RuntimeException('Worker creation rejected: '.$response->getContent());
            $result=$response->getData(true);
            DB::table('worker_operation_requests')->insert(['company_id'=>$companyId,'branch_id'=>$branchId,
                'worker_id'=>$workerId,'operation'=>$operation,'request_key'=>$key,'request_hash'=>$hash,
                'hash_version'=>1,'actor_id'=>auth()->id(),'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),
                'loan_id'=>$operation==='CREATE_LOAN'?$result['id']:null,
                'commission_id'=>$operation==='CREATE_COMMISSION'?$result['id']:null,'created_at'=>now()]);
            return $result;
        });
    }
}
