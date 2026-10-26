<?php

namespace App\Http\Middleware;

use App\Services\Platform\PrivilegedAuditService;
use App\Services\Support\SupportSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSupportAccess
{
    public function __construct(private SupportSessionService $sessions,private PrivilegedAuditService $audit){}
    public function handle(Request $request,Closure $next): Response
    {
        if(!(bool)$request->attributes->get('is_support_mode',false))return $next($request);
        $session=$request->attributes->get('support_session')??$this->sessions->resolve($request);
        if(!$this->sessions->authorize($session,$request)){
            $this->audit->denied($request,['actor_type'=>'SUPPORT','target_company_id'=>(int)$session->company_id,'support_session_id'=>$session->support_session_id,
                'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,'resource'=>'SupportWrite','action'=>'SUPPORT_WRITE','scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);
            return response()->json(['status'=>false,'code'=>'SUPPORT_WRITE_DENIED','message'=>'جلسة الدعم للقراءة فقط أو لا تملك capability صريحة.'],403);
        }
        if(in_array(strtoupper($request->method()),['GET','HEAD','OPTIONS'],true))return $next($request);
        $operation=function()use($request,$next,$session){$response=$next($request);
            app(\App\Services\ShipmentTransactionOwner::class)->throwIfRollbackOnly();
            app(\App\Services\InventoryTransferTransactionOwner::class)->throwIfRollbackOnly();
            app(\App\Services\Uom\OperationalTransactionOwner::class)->assertResponseSuccessful($response->getStatusCode());
            $this->audit->record($request,['actor_type'=>'SUPPORT','target_company_id'=>(int)$session->company_id,
            'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,'resource'=>'SupportWrite',
            'action'=>'SUPPORT_WRITE','result'=>$response->getStatusCode()<400?'SUCCESS':'FAILED','scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);return $response;};
        // Only actual purchase post/void and linked sale post receive this owner.
        // All downstream subscription, tenant and permission middleware still run.
        $purchaseAction=$request->route()?->getActionName();
        $purchaseController=\App\Http\Controllers\Api\PurchaseInvoiceController::class;
        $salePost=$purchaseAction===\App\Http\Controllers\Api\SalesInvoiceController::class.'@post';
        if($salePost||in_array($purchaseAction,[$purchaseController.'@post',$purchaseController.'@void'],true)) {
            $purpose=$salePost?'SALE_POST':($purchaseAction===$purchaseController.'@void'?'PURCHASE_VOID':'PURCHASE_POST');
            $company=(int)$session->company_id;
            $invoice=(int)$request->route('id');
            $scope=$salePost?DB::table('invoice_shipment_links')->where('company_id',$company)
                ->where('invoice_type','SALE')->where('invoice_id',$invoice)->orderBy('id')->pluck('shipment_id')->all()
                :\App\Services\ShipmentPurchasePostingScope::snapshot($company,$invoice);
            if($scope||$purpose==='PURCHASE_VOID') {
                try { return app(\App\Services\ShipmentTransactionOwner::class)->run(
                    $company,$invoice,(int)$request->attributes->get('authenticated_user_id'),$scope,$operation,$purpose);
                } catch(\App\Services\ShipmentPostingFailure $failure) {
                    if(!$failure->rollbackConfirmed)throw $failure;
                    // Retain existing FAILED-event policy, only AFTER root rollback.
                    // This insert cannot COMMIT any original financial work.
                    $this->audit->record($request,['actor_type'=>'SUPPORT','target_company_id'=>$company,
                        'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,
                        'resource'=>'SupportWrite','action'=>'SUPPORT_WRITE','result'=>'FAILED',
                        'scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);
                    return response()->json(['status'=>false,'message'=>\App\Support\ApiExceptionMessage::for($failure)],422);
                }
            }
        }
        if(config('inventory_transfer.root_protocol_enabled')&&$purchaseAction===\App\Http\Controllers\Api\InventoryOperationController::class.'@approve') {
            $company=(int)$session->company_id;$id=(int)$request->route('id');
            if(DB::table('inventory_operations')->useWritePdo()->where('company_id',$company)->where('id',$id)->value('operation_type')==='TRANSFER') {
                try {
                    return app(\App\Services\InventoryTransferTransactionOwner::class)->run($company,$id,(int)$request->attributes->get('authenticated_user_id'),$operation);
                } catch(\App\Services\ShipmentPostingFailure $failure) {
                    if(!$failure->rollbackConfirmed)throw $failure;
                    $this->audit->record($request,['actor_type'=>'SUPPORT','target_company_id'=>$company,
                        'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,
                        'resource'=>'SupportWrite','action'=>'SUPPORT_WRITE','result'=>'FAILED',
                        'scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);
                    return response()->json(['status'=>false,'message'=>\App\Support\ApiExceptionMessage::for($failure)],422);
                }
            }
        }
        $uomPurpose=match($purchaseAction){
            \App\Http\Controllers\Api\InventoryOperationController::class.'@store'=>$request->filled('recipe_id')?'PROCESS_CREATE':null,
            \App\Http\Controllers\Api\InventoryOperationController::class.'@approve'=>'PROCESS_POST',
            \App\Http\Controllers\Api\InventoryOperationController::class.'@void'=>'PROCESS_VOID',
            $purchaseController.'@post'=>'PURCHASE_POST',
            \App\Http\Controllers\Api\SalesInvoiceController::class.'@post'=>'SALE_POST',
            \App\Http\Controllers\Api\SalesInvoiceController::class.'@void'=>'SALE_VOID',
            \App\Http\Controllers\Api\CommercialReturnController::class.'@post'=>'RETURN_POST',
            \App\Http\Controllers\Api\CommercialReturnController::class.'@void'=>'RETURN_VOID',
            \App\Http\Controllers\Api\OpeningBalanceController::class.'@post'=>'OPENING_POST',
            \App\Http\Controllers\Api\InventoryController::class.'@adjustment'=>'ADJUSTMENT_CREATE',
            \App\Http\Controllers\Api\InventoryController::class.'@voidAdjustment'=>'ADJUSTMENT_VOID',
            \App\Http\Controllers\Api\PurchaseOrderController::class.'@convert'=>'ORDER_CONVERT',
            \App\Http\Controllers\Api\SalesQuotationController::class.'@convert'=>'QUOTE_CONVERT',
            default=>null,
        };
        $company=(int)$session->company_id;$document=(int)$request->route('id');
        if($uomPurpose==='ADJUSTMENT_CREATE')$document=(int)$request->input('item_id');
        if($uomPurpose==='PROCESS_CREATE')$document=(int)$request->input('recipe_id');
        if($uomPurpose!==null&&\App\Services\Uom\OperationalTransactionOwner::eligible($company,$document,$uomPurpose)){
            try{return app(\App\Services\Uom\OperationalTransactionOwner::class)->run($company,$document,
                (int)$request->attributes->get('authenticated_user_id'),$uomPurpose,$operation);
            }catch(\App\Services\ShipmentPostingFailure $failure){
                if(!$failure->rollbackConfirmed)throw $failure;
                $this->audit->record($request,['actor_type'=>'SUPPORT','target_company_id'=>$company,
                    'support_session_id'=>$session->support_session_id,'ticket_reference'=>$session->ticket_reference,'reason'=>$session->reason,
                    'resource'=>'SupportWrite','action'=>'SUPPORT_WRITE','result'=>'FAILED',
                    'scope'=>['method'=>$request->method(),'uri'=>optional($request->route())->uri()]]);
                return response()->json(['status'=>false,'message'=>\App\Support\ApiExceptionMessage::for($failure)],422);
            }
        }
        return DB::transaction($operation);
    }
}
