<?php
// Included by the owned MariaDB HTTP runner only; no existing tenant rows used.
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$middleware=app(\App\Http\Middleware\EnforceUsageLimit::class);
foreach([0,1,3,null]as$limit){
    $lc=fixture('companies',['company_name'=>'HTTP LIMIT TEST '.($limit??'NULL'),'is_active'=>1]);
    $lb=fixture('branches',['company_id'=>$lc,'branch_name'=>'TEST','is_active'=>1]);
    $lp=fixture('customers',['company_id'=>$lc,'branch_id'=>$lb,'customer_name'=>'TEST','is_active'=>1]);
    fixture('subscriptions',['company_id'=>$lc,'plan_id'=>$plan,'start_date'=>now()->subDay()->toDateString(),'end_date'=>now()->addMonth()->toDateString(),'status'=>'ACTIVE']);
    DB::table('plan_features')->where('id',$a)->update(['limit_value'=>$limit]);
    $invoke=function(string $method,string $uri,?string $name=null)use($middleware,$lc){
        $r=\Illuminate\Http\Request::create('/'.$uri,$method);$route=new \Illuminate\Routing\Route($method,$uri,fn()=>null);if($name)$route->name($name);$r->setRouteResolver(fn()=>$route);$r->attributes->set('tenant_company_id',$lc);
        try{return $middleware->handle($r,fn()=>response()->json(['status'=>true],201))->getStatusCode();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){return $e->getStatusCode();}
    };
    foreach(['sales-invoices','purchase-invoices','official-documents']as$uri){
        foreach(['','api/']as$prefix)
            check($invoke('POST',$prefix.$uri)===($limit===0?409:201),'LIMIT '.($limit??'NULL').' empty company '.$prefix.$uri);
    }
    // Named create actions remain correct under a different mount prefix.
    check($invoke('POST','custom/invoices','sales-invoices.store')===($limit===0?409:201),'LIMIT route name independent of mount prefix '.($limit??'NULL'));
    // Only creation is counted, not post/void/attachments/update/list/vouchers.
    foreach([['POST','api/sales-invoices/1/post'],['POST','api/purchase-invoices/1/void'],['POST','api/official-documents/1/attachments'],['PUT','api/sales-invoices/1'],['GET','api/sales-invoices'],['POST','api/vouchers']]as[$method,$uri])check($invoke($method,$uri)===201,'LIMIT does not count '.$method.' '.$uri.' limit '.($limit??'NULL'));
    // Source company has many invoices; they must not consume this company's limit.
    check(app(\App\Services\Entitlement\UsageLimitService::class)->usage($lc,'max_documents')===0,'LIMIT company isolation '.($limit??'NULL'));
    if($limit!==null&&$limit>0){
        for($i=0;$i<$limit;$i++)fixture('sales_invoices',['company_id'=>$lc,'branch_id'=>$lb,'customer_id'=>$lp,'invoice_number'=>'LIMIT-'.$lc.'-'.$i,'invoice_date'=>'2026-10-10','document_status'=>'DRAFT']);
        check($invoke('POST','api/sales-invoices')===409,'LIMIT '.$limit.' exhausted rejects');
    }
}
DB::table('plan_features')->where('id',$a)->update(['limit_value'=>9999]);

// Real current POST /vouchers, including its automatic JournalService posting.
\Illuminate\Support\Facades\Route::post('api/vouchers',[\App\Http\Controllers\Api\VoucherController::class,'store'])->middleware($chain);
foreach([true,false]as$receipt){
    $payload=['voucher_type_id'=>$receipt?1:2,'voucher_date'=>'2026-10-10','reference_type'=>$receipt?'CUSTOMER':'SUPPLIER','reference_id'=>$receipt?$customer:$supplier,'amount'=>'1.000','financial_account_id'=>$fa,'currency_code'=>'SAR','exchange_rate'=>1,'payment_method'=>'CASH'];
    check($http($reader,'vouchers',$payload)->getStatusCode()===403,'REAL VOUCHER unauthorized create denied');
    DB::table('subscriptions')->where('id',$sub)->update(['status'=>'SUSPENDED']);check($http($owner,'vouchers',$payload)->getStatusCode()===403,'REAL VOUCHER suspended create denied');DB::table('subscriptions')->where('id',$sub)->update(['status'=>'ACTIVE']);
    $before=DB::table('journal_entries')->count();$response=$http($owner,'vouchers',$payload);check($response->getStatusCode()===201,'REAL VOUCHER create and post '.($receipt?'receipt':'payment'));
    if($response->getStatusCode()!==201)throw new \RuntimeException('Real voucher HTTP '.$response->getContent());
    $data=json_decode($response->getContent(),true);$j=(int)$data['journal_entry_id'];
    check($j>0&&DB::table('journal_entries')->count()===$before+1,'REAL VOUCHER current contract automatically posts once');
    $difference=DB::table('journal_entry_lines')->where('journal_entry_id',$j)->sum(DB::raw('debit-credit'));check(abs((float)$difference)<0.0001,'REAL VOUCHER journal balanced');
    $invoice=$receipt?$sale:$purchase; // captured original, same party; already posted voucher still cannot allocate
    $beforeAlloc=DB::table('voucher_invoice_allocations')->count();
    $r=$http($owner,'vouchers/'.$data['id'].'/allocations',['key'=>'REAL-POSTED-'.$data['id'],'invoice_type'=>$receipt?'SALE':'PURCHASE','allocations'=>[['invoice_id'=>$invoice,'amount'=>'1.000']]]);
    check($r->getStatusCode()===422&&str_contains($r->getContent(),'Existing posted voucher'),'REAL VOUCHER refuses speculative post-then-allocate contract');
    check(DB::table('voucher_invoice_allocations')->count()===$beforeAlloc&&DB::table('journal_entries')->count()===$before+1,'REAL VOUCHER allocation refusal has no extra financial effect');
}
