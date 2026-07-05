<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index()
    {
        $data = DB::table('plans')
            ->where('is_active', 1)
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}