<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Accounting\Services\AccountService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function tree(AccountService $service)
    {
        return response()->json([
            'status' => true,
            'data' => $service->tree((int) $this->companyId())
        ]);
    }

    public function posting(AccountService $service)
    {
        return response()->json([
            'status' => true,
            'data' => $service->postingAccounts((int) $this->companyId())
        ]);
    }

    public function store(Request $request, AccountService $service)
    {
        $request->validate([
            'account_code' => 'required|string|max:50',
            'account_name' => 'required|string|max:255',
            'account_type' => 'required|string|max:50',
            'normal_side' => 'required|in:DEBIT,CREDIT',
            'parent_id' => 'nullable|integer',
            'is_group' => 'nullable|boolean',
            'allow_cost_center' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ], [
            'account_code.required' => 'رقم الحساب مطلوب.',
            'account_name.required' => 'اسم الحساب مطلوب.',
            'account_type.required' => 'نوع الحساب مطلوب.',
            'normal_side.required' => 'طبيعة الحساب مطلوبة.',
            'normal_side.in' => 'طبيعة الحساب يجب أن تكون DEBIT أو CREDIT.',
        ]);

        try {
            $id = $service->create([
                'company_id' => (int) $this->companyId(),
                'parent_id' => $request->parent_id,
                'account_code' => $request->account_code,
                'account_name' => $request->account_name,
                'account_type' => $request->account_type,
                'normal_side' => $request->normal_side,
                'is_group' => $request->is_group ?? 0,
                'allow_cost_center' => $request->allow_cost_center ?? 0,
                'notes' => $request->notes,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الحساب بنجاح.',
                'id' => $id
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}