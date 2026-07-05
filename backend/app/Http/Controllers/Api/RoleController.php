<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index()
    {
        $data = DB::table('roles')
            ->orderBy('role_name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}