<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanySettingController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function show()
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $settings = DB::table('company_settings')
            ->where('company_id', $companyId)
            ->first();

        if (!$settings) {
            DB::table('company_settings')->insert([
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $settings = DB::table('company_settings')
                ->where('company_id', $companyId)
                ->first();
        }

        return response()->json([
            'status' => true,
            'data' => $settings
        ]);
    }

    public function update(Request $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'print_company_name' => 'nullable|string|max:255',
            'print_phone' => 'nullable|string|max:50',
            'print_email' => 'nullable|string|max:150',
            'print_city' => 'nullable|string|max:100',
            'print_address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:100',
            'commercial_register' => 'nullable|string|max:100',
            'currency_name' => 'nullable|string|max:50',
            'currency_code' => 'nullable|string|max:10',
            'invoice_footer' => 'nullable|string',
            'report_footer' => 'nullable|string',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
        ]);

        DB::table('company_settings')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'print_company_name' => $request->print_company_name,
                'print_phone' => $request->print_phone,
                'print_email' => $request->print_email,
                'print_city' => $request->print_city,
                'print_address' => $request->print_address,
                'tax_number' => $request->tax_number,
                'commercial_register' => $request->commercial_register,
                'currency_name' => $request->currency_name ?? 'ريال',
                'currency_code' => $request->currency_code ?? 'SAR',
                'invoice_footer' => $request->invoice_footer,
                'report_footer' => $request->report_footer,
                'primary_color' => $request->primary_color ?? '#0B2A4A',
                'secondary_color' => $request->secondary_color ?? '#123D68',
                'updated_at' => now(),
            ]
        );

        $this->logUpdate('CompanySettings', $companyId, 'تم تحديث إعدادات الشركة');

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ إعدادات الشركة بنجاح'
        ]);
    }

    public function upload(Request $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'type' => 'required|in:logo,signature,stamp',
            'file' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $path = $request->file('file')->store("company-settings/{$companyId}", 'public');

        $column = match ($request->type) {
            'logo' => 'logo_path',
            'signature' => 'signature_path',
            'stamp' => 'stamp_path',
        };

        DB::table('company_settings')->updateOrInsert(
            ['company_id' => $companyId],
            [
                $column => $path,
                'updated_at' => now(),
            ]
        );

        $this->logUpdate('CompanySettings', $companyId, 'تم رفع ملف إعدادات: ' . $request->type);

        return response()->json([
            'status' => true,
            'message' => 'تم رفع الملف بنجاح',
            'path' => $path,
            'url' => asset('storage/' . $path),
        ]);
    }
}