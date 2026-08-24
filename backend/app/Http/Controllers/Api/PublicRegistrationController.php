<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Provisioning\CompanyProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PublicRegistrationController extends Controller
{
    public function register(Request $request, CompanyProvisioningService $provisioning)
    {
        $request->merge(['idempotency_key'=>$request->header('Idempotency-Key',$request->input('idempotency_key'))]);
        $validator = Validator::make($request->all(), [
            'idempotency_key'=>['required','string','max:100'],
            'company_name'=>['required','string','max:255'],'owner_name'=>['required','string','max:255'],
            'phone'=>['required','string','max:50'],'email'=>['nullable','email','max:150'],
            'city'=>['nullable','string','max:100'],'address'=>['nullable','string','max:1000'],
            'username'=>['required','string','max:150'],'password'=>['required','string','min:6','max:100','confirmed'],
            'plan_id'=>['required','integer','exists:plans,id'],
            'billing_period'=>['required',Rule::in(['MONTHLY','QUARTERLY','SEMI_ANNUAL','YEARLY'])],
        ]);
        if ($validator->fails()) return response()->json(['status'=>false,'message'=>'يرجى مراجعة بيانات التسجيل.','errors'=>$validator->errors()],422);

        $months=['MONTHLY'=>1,'QUARTERLY'=>3,'SEMI_ANNUAL'=>6,'YEARLY'=>12][$request->billing_period];
        $start=CarbonImmutable::today();
        try {
            $result=$provisioning->provision([...$validator->validated(),
                'channel'=>'PUBLIC_REGISTRATION','subscription_mode'=>'PAID','trial_allowed'=>false,
                'company_is_active'=>false,'start_date'=>$start->toDateString(),
                'end_date'=>$start->addMonthsNoOverflow($months)->subDay()->toDateString(),
                'currency_code'=>strtoupper((string)env('SUBSCRIPTION_CURRENCY','SAR')),
                'tax_rate'=>(float)env('SUBSCRIPTION_TAX_RATE',0),
            ]);
            return response()->json(['status'=>true,'message'=>'تم إنشاء الحساب والفاتورة. يبدأ الاشتراك بعد تأكيد الدفع.','data'=>$result],$result['idempotent_replay']?200:201);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['status'=>false,'message'=>$e->getMessage()],422);
        }
    }
}
