<?php
// In-process HTTP kernel acceptance harness, registered ONLY by the isolated CLI.
// No additions to routes/api.php and no externally listening test server.
\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
$httpRole=fixture('roles',['role_code'=>'TEST_HTTP_READER','role_name'=>'TEST HTTP READER','is_active'=>1]);
$httpUser=fixture('users',['company_id'=>$cid,'branch_id'=>$bid,'username'=>'TEST_HTTP_READER','name'=>'TEST HTTP READER','is_active'=>1,'password'=>password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);fixture('user_roles',['company_id'=>$cid,'user_id'=>$httpUser,'role_id'=>$httpRole,'is_active'=>1]);
$httpSource=$realDraft('SALE',[['item_id'=>$item,'quantity'=>1,'unit_price'=>20,'vat_percent'=>0]]);
$ownerToken=\App\Models\User::findOrFail($uid)->createToken('ISOLATED HTTP ONLY')->plainTextToken;
$readerToken=\App\Models\User::findOrFail($httpUser)->createToken('ISOLATED HTTP ONLY')->plainTextToken;
\Illuminate\Support\Facades\Route::post('/api/__isolated-historical/sales/{id}/post',function(\Illuminate\Http\Request $r,int $id){
    $company=(int)$r->attributes->get('tenant_company_id');$branch=(int)$r->attributes->get('tenant_branch_id');$actor=(int)$r->user()->id;
    try{\App\Services\Accounting\HistoricalAccountingAuthorization::assert($company,$branch,$actor,['sales.post']);}catch(\RuntimeException){return response()->json(['code'=>'FORBIDDEN'],403);}
    $r->validate(['company_id'=>'prohibited','user_id'=>'prohibited','branch_id'=>'prohibited','account_id'=>'prohibited']);
    try{$jid=app(\App\Services\Accounting\InvoiceSnapshotPostingService::class)->post($company,$branch,'SALE',$id,$actor,true);return response()->json(['journal_entry_id'=>$jid]);}catch(\RuntimeException){return response()->json(['code'=>'SOURCE_OR_STATE_REJECTED'],422);}
})->middleware(['auth:sanctum','auth.context']);
$callHttp=function(?string $token,int $id,array $body=[])use($app){
    \Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');
    $req=\Illuminate\Http\Request::create('/api/__isolated-historical/sales/'.$id.'/post','POST',$body,[],[],['HTTP_ACCEPT'=>'application/json']);
    if($token)$req->headers->set('Authorization','Bearer '.$token);
    $req->headers->set('X-Company-ID','999999');$req->headers->set('X-User-ID','999999');$req->headers->set('X-Branch-ID','999999');
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($req);$kernel->terminate($req,$response);return$response;
};
$before=DB::table('journal_entries')->count();
check($callHttp(null,$httpSource)->getStatusCode()===401,'HTTP harness unauthenticated request rejected');
check($callHttp($readerToken,$httpSource)->getStatusCode()===403,'HTTP harness authenticated actor without permission rejected');
check($callHttp($ownerToken,$httpSource,['company_id'=>$foreign])->getStatusCode()===422,'HTTP harness client company ID prohibited');
check($callHttp($ownerToken,$httpSource,['user_id'=>$httpUser])->getStatusCode()===422,'HTTP harness client UID prohibited');
check($callHttp($ownerToken,$httpSource,['branch_id'=>$foreignBid])->getStatusCode()===422,'HTTP harness client branch prohibited');
check(DB::table('journal_entries')->count()===$before,'HTTP denied and invalid requests produce zero journals');
$response=$callHttp($ownerToken,$httpSource);check($response->getStatusCode()===200,'HTTP harness authorized bearer posts real lifecycle');
$jid=json_decode($response->getContent(),true)['journal_entry_id']??null;$retry=$callHttp($ownerToken,$httpSource);
check($retry->getStatusCode()===200&&(json_decode($retry->getContent(),true)['journal_entry_id']??null)===$jid&&DB::table('journal_entries')->count()===$before+1,'HTTP retry does not duplicate journal');
$foreignSource=fixture('sales_invoices',['company_id'=>$foreign,'branch_id'=>$foreignBid,'customer_id'=>fixture('customers',['company_id'=>$foreign,'branch_id'=>$foreignBid,'customer_name'=>'TEST HTTP FOREIGN','is_active'=>1]),'invoice_number'=>'HTTP-FOREIGN','invoice_date'=>'2026-10-01','document_status'=>'DRAFT']);
check($callHttp($ownerToken,$foreignSource)->getStatusCode()===422,'HTTP foreign document ID rejected');
// Platform token outside Support cannot inherit tenant financial authority.
$platformToken=\App\Models\User::findOrFail($platform)->createToken('ISOLATED PLATFORM HTTP ONLY')->plainTextToken;
check($callHttp($platformToken,$httpSource)->getStatusCode()===403,'HTTP platform outside Support cannot post');
// Sensitive tokens remain in process memory only and are removed with the DB.
unset($ownerToken,$readerToken,$platformToken);
\Illuminate\Support\Facades\Auth::forgetGuards();\Illuminate\Support\Facades\Auth::shouldUse('web');\Illuminate\Support\Facades\Auth::setUser(\App\Models\User::findOrFail($uid));
$app->instance('request',\Illuminate\Http\Request::create('/'));
