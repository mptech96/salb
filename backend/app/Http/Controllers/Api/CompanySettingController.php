<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\EntityAddressService;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanySettingController extends Controller
{
    use LogsActivity;

    public function show(Request $request, AccountingContext $context, EntityAddressService $addresses)
    {
        $companyId = $context->companyId($request);

        $settings = DB::table('company_settings')->where('company_id', $companyId)->first();
        $hasPersistedSettings = $settings !== null;

        if (!$settings) {
            $settings = (object) [
                'company_id' => $companyId,
                'currency_name' => 'USD',
                'currency_code' => 'USD',
                'base_currency_code' => 'USD',
                'currency_decimal_places' => 3,
                'primary_color' => '#0B2A4A',
                'secondary_color' => '#123D68',
            ];
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        $data = (array) $settings;
        $data['company_name_fallback'] = $company->company_name ?? null;
        $data['print_company_name'] = ($data['print_company_name'] ?? null) ?: ($company->company_name ?? null);
        $data['company'] = $company;
        $data['address_details'] = $addresses->getDefault($companyId, 'COMPANY', $companyId);
        $baseCurrency = strtoupper(trim((string) ($data['base_currency_code'] ?? $data['currency_code'] ?? '')));
        $activeBases = DB::table('company_currencies')->where('company_id', $companyId)->where('is_base', 1)->where('is_active', 1)->pluck('currency_code');
        $data['currency_configuration_status'] = ! $hasPersistedSettings
            ? 'MISSING'
            : ($baseCurrency !== '' && $activeBases->count() === 1 && strtoupper((string) $activeBases->first()) === $baseCurrency ? 'CONFIGURED' : 'DRIFT');
        foreach (['logo','signature','stamp','header_image','footer_image','watermark'] as $asset) {
            $column = $asset.'_path';
            $data[$asset.'_url'] = !empty($settings->{$column} ?? null) ? url('/api/company-settings/assets/'.$asset) : null;
        }
        foreach (['print_header_texts','print_footer_texts','print_options'] as $json) {
            $data[$json] = json_decode((string)($settings->{$json} ?? ''), true) ?: [];
        }
        return response()->json(['status' => true, 'data' => $data]);
    }

    public function update(Request $request, AccountingContext $context, EntityAddressService $addresses)
    {
        $companyId = $context->companyId($request);
        $validated = $request->validate([
            'print_company_name'=>['nullable','string','max:255'],'print_phone'=>['nullable','string','max:50'],
            'print_email'=>['nullable','email','max:150'],'print_city'=>['nullable','string','max:100'],
            'print_address'=>['nullable','string','max:2000'],'tax_number'=>['nullable','string','max:100'],
            'commercial_register'=>['nullable','string','max:100'],'currency_name'=>['nullable','string','max:50'],
            'currency_code'=>['nullable','string','max:10'],'invoice_footer'=>['nullable','string','max:3000'],
            'report_footer'=>['nullable','string','max:3000'],'primary_color'=>['nullable','string','max:20'],
            'secondary_color'=>['nullable','string','max:20'],'legal_name'=>['nullable','string','max:255'],
            'registration_number'=>['nullable','string','max:120'],'country_code'=>['nullable','string','size:2'],
            'default_language'=>['nullable','string','max:10'],'timezone'=>['nullable','string','max:80'],
            'short_address'=>['nullable','string','max:100'],'building_no'=>['nullable','string','max:50'],
            'street_name'=>['nullable','string','max:200'],'district'=>['nullable','string','max:150'],
            'city'=>['nullable','string','max:150'],'state_region'=>['nullable','string','max:150'],
            'postal_code'=>['nullable','string','max:50'],'additional_no'=>['nullable','string','max:50'],
            'unit_no'=>['nullable','string','max:50'],'address_line1'=>['nullable','string','max:500'],
            'address_line2'=>['nullable','string','max:500'],
            'print_header_texts'=>['nullable','array'],'print_footer_texts'=>['nullable','array'],
            'print_header_texts.*'=>['nullable','string','max:3000'],'print_footer_texts.*'=>['nullable','string','max:3000'],
            'print_options'=>['nullable','array'],
            'print_options.paper'=>['nullable','in:A4'],
            'print_options.orientation'=>['nullable','in:portrait,landscape'],
            'print_options.margin_mm'=>['nullable','numeric','between:5,30'],
            'print_options.logo_width_mm'=>['nullable','numeric','between:15,70'],
            'print_options.header_height_mm'=>['nullable','numeric','between:10,60'],
            'print_options.footer_height_mm'=>['nullable','numeric','between:10,50'],
            'print_options.show_company_name'=>['nullable','boolean'],
            'print_options.show_company_details'=>['nullable','boolean'],
            'print_options.company_fields'=>['nullable','array'],
            'print_options.company_fields.phone'=>['nullable','boolean'],
            'print_options.company_fields.email'=>['nullable','boolean'],
            'print_options.company_fields.city'=>['nullable','boolean'],
            'print_options.company_fields.address'=>['nullable','boolean'],
            'print_options.company_fields.commercial_register'=>['nullable','boolean'],
            'print_options.company_fields.tax_number'=>['nullable','boolean'],
            'print_options.header_mode'=>['nullable','in:FULL_IMAGE,LOGO_DETAILS,TEXT,MIXED'],
            'print_options.footer_mode'=>['nullable','in:IMAGE,TEXT,CONTACT_PAGE,MIXED'],
            'print_options.visibility'=>['nullable','array'],
            'print_options.visibility.*'=>['boolean'],
            'print_options.watermark'=>['nullable','array'],
            'print_options.watermark.enabled'=>['nullable','boolean'],
            'print_options.watermark.mode'=>['nullable','in:TEXT,IMAGE'],
            'print_options.watermark.text'=>['nullable','string','max:100'],
            'print_options.watermark.opacity'=>['nullable','numeric','between:0.03,0.3'],
            'print_options.watermark.size'=>['nullable','numeric','between:12,160'],
            'print_options.watermark.angle'=>['nullable','numeric','between:-70,70'],
            'print_options.watermark.position'=>['nullable','in:CENTER,TOP,BOTTOM'],
            'print_options.watermark.pages'=>['nullable','in:ALL,FIRST'],
            'print_options.watermark.color'=>['nullable','regex:/^#[0-9a-fA-F]{6}$/'],
            'print_options.templates'=>['nullable','array'],
            'print_options.templates.*.selected'=>['nullable','in:CLASSIC,MODERN,FULL_HEADER,COMPACT,COMPANY,ROAD_BOXES'],
            'print_options.templates.*.visibility'=>['nullable','array'],
            'print_options.templates.*.visibility.*'=>['boolean'],
        ]);

        DB::transaction(function () use ($companyId, $validated, $addresses) {
            $settingsPayload = collect($validated)->only(['print_company_name','print_phone','print_email','print_city','print_address','tax_number','commercial_register','currency_name','currency_code','invoice_footer','report_footer','primary_color','secondary_color','country_code'])->all();
            foreach (['print_header_texts','print_footer_texts','print_options'] as $json) if (array_key_exists($json, $validated)) $settingsPayload[$json] = json_encode($validated[$json], JSON_UNESCAPED_UNICODE);
            if (!empty($validated['currency_code'])) $settingsPayload['base_currency_code'] = strtoupper($validated['currency_code']);
            if (empty($settingsPayload['primary_color'])) $settingsPayload['primary_color'] = '#0B2A4A';
            if (empty($settingsPayload['secondary_color'])) $settingsPayload['secondary_color'] = '#123D68';
            $settingsPayload['updated_at'] = now();
            DB::table('company_settings')->updateOrInsert(['company_id'=>$companyId],$settingsPayload);
            DB::table('companies')->where('id',$companyId)->update([
                'legal_name'=>$validated['legal_name']??null,
                'registration_number'=>$validated['registration_number']??($validated['commercial_register']??null),
                'tax_number'=>$validated['tax_number']??null,
                'country_code'=>!empty($validated['country_code'])?strtoupper($validated['country_code']):null,
                'default_language'=>$validated['default_language']??'ar',
                'timezone'=>$validated['timezone']??'UTC',
                'city'=>$validated['city']??($validated['print_city']??null),
                'address'=>$validated['address_line1']??($validated['print_address']??null),
                'updated_at'=>now(),
            ]);
            $addresses->upsertDefault($companyId,'COMPANY',$companyId,$validated);
        });

        $this->logUpdate('CompanySettings', $companyId, 'تم تحديث إعدادات الشركة والطباعة');
        return response()->json(['status' => true, 'message' => 'تم حفظ إعدادات الشركة بنجاح.']);
    }

    public function upload(Request $request, AccountingContext $context)
    {
        $companyId = $context->companyId($request);

        $type = (string) $request->input('type', '');
        if (!in_array($type, ['logo', 'signature', 'stamp', 'header_image', 'footer_image', 'watermark'], true)) {
            throw ValidationException::withMessages(['type' => ['نوع الملف غير صحيح.']]);
        }

        $column = match ($type) {
            'logo' => 'logo_path',
            'signature' => 'signature_path',
            'stamp' => 'stamp_path',
            'header_image' => 'header_image_path',
            'footer_image' => 'footer_image_path',
            'watermark' => 'watermark_path',
        };

        $oldPath = DB::table('company_settings')->where('company_id', $companyId)->value($column);
        $path = null;

        $dataUrl = $request->input('file_base64');
        if (is_string($dataUrl) && $dataUrl !== '') {
            if (!preg_match('#^data:image/(png|jpeg|jpg|webp|gif|bmp|x-ms-bmp);base64,(.+)$#is', $dataUrl, $m)) {
                throw ValidationException::withMessages(['file' => ['صيغة الصورة غير صحيحة. استخدم PNG أو JPG/JPEG أو WEBP أو GIF ثابت أو BMP.']]);
            }

            $binary = base64_decode($m[2], true);
            if ($binary === false || $binary === '') {
                throw ValidationException::withMessages(['file' => ['تعذر قراءة بيانات الصورة.']]);
            }

            [$binary, $ext] = $this->validateBrandingImage($binary);
            $safeName = $type . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $path = "print-branding/{$companyId}/{$safeName}";
            if (!Storage::disk('local')->put($path, $binary)) throw ValidationException::withMessages(['file'=>['تعذر حفظ الصورة في التخزين الخاص.']]);
        } elseif ($request->hasFile('file')) {
            $request->validate([
                'file' => ['required','file','max:2048'],
            ], [
                'file.required' => 'اختر ملفاً للرفع.',
                'file.file' => 'الملف المرفوع غير صالح.',
                'file.max' => 'حجم الصورة يجب ألا يتجاوز 2 MB وفق حد رفع الخادم.',
            ]);

            $file = $request->file('file');
            [$binary, $ext] = $this->validateBrandingImage($file->getContent());
            $safeName = $type . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $path = "print-branding/{$companyId}/{$safeName}";
            if (!Storage::disk('local')->put($path, $binary)) throw ValidationException::withMessages(['file'=>['تعذر حفظ الصورة في التخزين الخاص.']]);
        } else {
            throw ValidationException::withMessages(['file' => ['لم تصل بيانات الصورة إلى الخادم.']]);
        }

        try {
            DB::table('company_settings')->updateOrInsert(
                ['company_id' => $companyId],
                [$column => $path, 'updated_at' => now()]
            );
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }

        if ($oldPath && $oldPath !== $path) {
            $this->deleteStoredAsset((string)$oldPath);
        }

        $this->logUpdate('CompanySettings', $companyId, 'تم رفع ملف إعدادات: ' . $type);

        return response()->json([
            'status' => true,
            'message' => 'تم رفع الملف بنجاح.',
            'path' => $path,
            'url' => url('/api/company-settings/assets/'.$type),
        ]);
    }

    public function asset(Request $request, string $type, AccountingContext $context): StreamedResponse
    {
        $companyId = $context->companyId($request);
        $column = $this->assetColumn($type);
        $path = (string) DB::table('company_settings')->where('company_id',$companyId)->value($column);
        abort_if($path === '', 404);
        $disk = str_starts_with($path, 'print-branding/') ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($path), 404);
        return Storage::disk($disk)->response($path, null, ['Cache-Control'=>'private, max-age=300']);
    }

    public function removeAsset(Request $request, string $type, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $column = $this->assetColumn($type);
        $path = (string) DB::table('company_settings')->where('company_id',$companyId)->value($column);
        DB::table('company_settings')->where('company_id',$companyId)->update([$column=>null,'updated_at'=>now()]);
        if ($path !== '') $this->deleteStoredAsset($path);
        return response()->json(['status'=>true,'message'=>'تمت إزالة الصورة.']);
    }

    private function assetColumn(string $type): string
    {
        return match($type) {
            'logo'=>'logo_path','signature'=>'signature_path','stamp'=>'stamp_path',
            'header_image'=>'header_image_path','footer_image'=>'footer_image_path',
            'watermark'=>'watermark_path',
            default=>abort(404),
        };
    }

    private function deleteStoredAsset(string $path): void
    {
        if (str_starts_with($path, 'print-branding/')) Storage::disk('local')->delete($path);
        elseif (str_starts_with($path, 'company-settings/')) Storage::disk('public')->delete($path);
    }

    /** @return array{0: string, 1: string} */
    private function validateBrandingImage(string $binary): array
    {
        if ($binary === '' || strlen($binary) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['file'=>['الصورة فارغة أو يتجاوز حجمها 2 MB وفق حد رفع الخادم.']]);
        }
        $info = @getimagesizefromstring($binary);
        if (!$info || empty($info['mime'])) {
            throw ValidationException::withMessages(['file'=>['محتوى الملف ليس صورة نقطية صالحة؛ لا يُقبل SVG غير المعقّم.']]);
        }
        [$width, $height] = [(int)$info[0], (int)$info[1]];
        if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 16000000) {
            throw ValidationException::withMessages(['file'=>['أبعاد الصورة غير آمنة للطباعة (الحد 6000 بكسل و16 مليون بكسل إجمالًا).']]);
        }
        $mime = strtolower((string)$info['mime']);
        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
            'image/gif', 'image/bmp', 'image/x-ms-bmp' => 'png',
            default => null,
        };
        if (!$ext) throw ValidationException::withMessages(['file'=>['الصيغة غير مدعومة بأمان؛ استخدم PNG أو JPG أو WEBP أو GIF ثابت أو BMP. TIFF وSVG غير مدعومين في هذه البيئة.']]);
        if ($mime === 'image/gif' && preg_match_all('/\x21\xF9\x04/s', $binary) > 1) {
            throw ValidationException::withMessages(['file'=>['GIF المتحرك غير مدعوم؛ استخدم صورة ثابتة.']]);
        }
        if ($ext === 'png' && $mime !== 'image/png') {
            if (!function_exists('imagecreatefromstring')) throw ValidationException::withMessages(['file'=>['تحويل GIF/BMP يتطلب امتداد GD.']]);
            $image = @imagecreatefromstring($binary);
            if (!$image) throw ValidationException::withMessages(['file'=>['تعذر تحويل الصورة إلى PNG آمن.']]);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $converted = ob_get_clean();
            imagedestroy($image);
            if (!is_string($converted) || $converted === '') throw ValidationException::withMessages(['file'=>['تعذر تحويل الصورة إلى PNG.']]);
            $binary = $converted;
        }
        return [$binary, $ext];
    }
}
