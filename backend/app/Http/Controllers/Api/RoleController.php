<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $actualRole = strtoupper((string) $request->attributes->get('actual_role_code', ''));
        $isSupportMode = (bool) $request->attributes->get('is_support_mode', false);
        $isPlatformAdmin = $actualRole === 'SUPER_ADMIN' && !$isSupportMode;

        $query = DB::table('roles')
            ->where('is_active', 1);

        if (!$isPlatformAdmin) {
            $query->where('role_code', '<>', 'SUPER_ADMIN');
        }

        return response()->json([
            'status' => true,
            'data' => $query->orderBy('role_name')->get(),
        ]);
    }
}
