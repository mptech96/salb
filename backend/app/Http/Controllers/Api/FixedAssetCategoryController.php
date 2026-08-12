<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedAssetCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FixedAssetCategoryController extends Controller
{
    private function companyId(Request $request): int
    {
        return (int) $request->header('X-Company-ID');
    }

    private function userId(Request $request): ?int
    {
        $userId = $request->header('X-User-ID');

        return $userId ? (int) $userId : null;
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية.',
            ], 400);
        }

        $query = FixedAssetCategory::query()
            ->where('company_id', $companyId);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('category_code', 'like', "%{$search}%")
                    ->orWhere('category_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('depreciation_method')) {
            $query->where(
                'depreciation_method',
                $request->depreciation_method
            );
        }

        if ($request->has('is_active')) {
            $query->where(
                'is_active',
                filter_var(
                    $request->is_active,
                    FILTER_VALIDATE_BOOLEAN
                )
            );
        }

        return response()->json([
            'status' => true,
            'data' => $query
                ->orderBy('category_code')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية.',
            ], 400);
        }

        $data = $this->validateCategory(
            $request,
            $companyId
        );

        $data['company_id'] = $companyId;
        $data['created_by'] = $this->userId($request);
        $data['updated_by'] = $this->userId($request);
        $data['is_active'] = true;

        $category = FixedAssetCategory::create($data);

        return response()->json([
            'status' => true,
            'message' => 'تمت إضافة فئة الأصل بنجاح.',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $companyId = $this->companyId($request);

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية.',
            ], 400);
        }

        $category = FixedAssetCategory::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'فئة الأصل غير موجودة.',
            ], 404);
        }

        $data = $this->validateCategory(
            $request,
            $companyId,
            $category->id
        );

        $data['updated_by'] = $this->userId($request);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $category->update($data);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث فئة الأصل بنجاح.',
            'data' => $category->fresh(),
        ]);
    }

    private function validateCategory(
        Request $request,
        int $companyId,
        ?int $categoryId = null
    ): array {
        return $request->validate([
            'category_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique(
                    'fixed_asset_categories',
                    'category_code'
                )
                    ->where(
                        fn ($query) =>
                        $query->where('company_id', $companyId)
                    )
                    ->ignore($categoryId),
            ],

            'category_name' => [
                'required',
                'string',
                'max:200',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'depreciation_method' => [
                'required',
                Rule::in([
                    'STRAIGHT_LINE',
                    'DECLINING_BALANCE',
                    'NO_DEPRECIATION',
                ]),
            ],

            'useful_life_months' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'annual_depreciation_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'default_salvage_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'asset_account_id' => [
                'nullable',
                'integer',
            ],

            'accumulated_depreciation_account_id' => [
                'nullable',
                'integer',
            ],

            'depreciation_expense_account_id' => [
                'nullable',
                'integer',
            ],

            'disposal_gain_account_id' => [
                'nullable',
                'integer',
            ],

            'disposal_loss_account_id' => [
                'nullable',
                'integer',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ], [
            'category_code.required' =>
                'كود الفئة مطلوب.',

            'category_code.unique' =>
                'كود الفئة مستخدم مسبقًا داخل الشركة.',

            'category_name.required' =>
                'اسم الفئة مطلوب.',

            'depreciation_method.required' =>
                'طريقة الإهلاك مطلوبة.',

            'useful_life_months.min' =>
                'العمر الإنتاجي يجب أن يكون أكبر من صفر.',
        ]);
    }
}