<?php

namespace App\Services\Platform;

use Illuminate\Http\Request;

final class PlatformAuthorityService
{
    public function allows(Request $request): bool
    {
        $user=$request->user();
        return $user !== null
            && $user->company_id === null
            && strtoupper((string)$request->attributes->get('actual_role_code','')) === 'SUPER_ADMIN'
            && !(bool)$request->attributes->get('is_support_mode',false)
            && $user->tokenCan('platform-admin');
    }
}
