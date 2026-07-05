<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function isSuper()
    {
        return request()->header('X-Role-Code') === 'SUPER_ADMIN';
    }

    public function index()
    {
        $companyId = $this->companyId();

        $query = DB::table('branches as b')
            ->leftJoin('companies as c', 'c.id', '=', 'b.company_id');

        if (!$this->isSuper()) {
            $query->where('b.company_id', $companyId);
        }

        $branches = $query
            ->select('b.*', 'c.company_name')
            ->orderByDesc('b.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $branches
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'branch_name' => 'required|string|max:255',
            'branch_code' => 'nullable|string|max:50|unique:branches,branch_code',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (!$this->isSuper() && (int)$request->company_id !== (int)$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بإنشاء فرع لشركة أخرى'
            ], 403);
        }

        $branch = Branch::create([
            'company_id' => $request->company_id,
            'branch_code' => $request->branch_code,
            'branch_name' => $request->branch_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'is_active' => $request->is_active ?? 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم إنشاء الفرع بنجاح',
            'data' => $branch
        ], 201);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $query = DB::table('branches as b')
            ->leftJoin('companies as c', 'c.id', '=', 'b.company_id')
            ->select('b.*', 'c.company_name')
            ->where('b.id', $id);

        if (!$this->isSuper()) {
            $query->where('b.company_id', $companyId);
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $branch
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $query = Branch::where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $companyId);
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود'
            ], 404);
        }

        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'branch_name' => 'required|string|max:255',
            'branch_code' => 'nullable|string|max:50|unique:branches,branch_code,' . $id,
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (!$this->isSuper() && (int)$request->company_id !== (int)$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'غير مسموح بتعديل فرع لشركة أخرى'
            ], 403);
        }

        $branch->update([
            'company_id' => $request->company_id,
            'branch_code' => $request->branch_code,
            'branch_name' => $request->branch_name,
            'phone' => $request->phone,
            'city' => $request->city,
            'address' => $request->address,
            'is_active' => $request->is_active ?? 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث الفرع بنجاح',
            'data' => $branch
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $query = Branch::where('id', $id);

        if (!$this->isSuper()) {
            $query->where('company_id', $companyId);
        }

        $branch = $query->first();

        if (!$branch) {
            return response()->json([
                'status' => false,
                'message' => 'الفرع غير موجود'
            ], 404);
        }

        $branch->delete();

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الفرع بنجاح'
        ]);
    }
}