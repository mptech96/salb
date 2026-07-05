<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class BaseApiController extends Controller
{
    protected function companyId()
    {
        return request()->header('X-Company-ID');
    }

    protected function branchId()
    {
        return request()->header('X-Branch-ID');
    }

    protected function userId()
    {
        return request()->header('X-User-ID');
    }

    protected function roleCode()
    {
        return request()->header('X-Role-Code');
    }

    protected function isSuper()
    {
        return $this->roleCode() === 'SUPER_ADMIN';
    }

    protected function requireCompany()
    {
        if (!$this->companyId()) {
            abort(response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400));
        }
    }
}