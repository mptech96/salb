<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\ReportCenterService;
use App\Services\Print\ChromiumPdfRenderer;
use App\Support\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdvancedReportController extends Controller
{
    public function catalog(Request $request, ReportCenterService $reports, AccountingContext $context)
    {
        $companyId = $context->companyId($request);
        $branchId = $context->branchFilter($request);
        $branches = DB::table('branches')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->when($branchId !== null, fn ($q) => $q->where('id', $branchId))
            ->select('id', 'branch_code', 'branch_name')
            ->orderBy('branch_name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'reports' => $reports->catalog(),
                'branches' => $branches,
                'print_profile' => $this->printProfile($companyId, $branchId),
            ],
        ]);
    }

    public function run(
        Request $request,
        string $key,
        ReportCenterService $reports,
        AccountingContext $context
    ) {
        $filters = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'q' => ['nullable', 'string', 'max:200'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        try {
            $branchId = $this->resolvedBranchId($request, $context);
            $data = $reports->run(
                $context->companyId($request),
                $branchId,
                $key,
                $filters
            );
            $data['print_profile'] = $this->printProfile(
                $context->companyId($request),
                $branchId
            );
            $data['filters'] = $filters;

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function export(
        Request $request,
        string $key,
        ReportCenterService $reports,
        AccountingContext $context
    ) {
        $validated = $request->validate([
            'format' => ['required', 'in:csv,xls,pdf'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'q' => ['nullable', 'string', 'max:200'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $format = $validated['format'];
        unset($validated['format']);

        try {
            $branchId = $this->resolvedBranchId($request, $context);
            $data = $reports->run(
                $context->companyId($request),
                $branchId,
                $key,
                $validated
            );
            $profile = $this->printProfile(
                $context->companyId($request),
                $branchId
            );

            $safeName = 'sulb-report-' . preg_replace('/[^a-z0-9\-]+/i', '-', $key) . '-' . date('Ymd-His');

            return match ($format) {
                'csv' => $this->csv($data, $safeName),
                'xls' => $this->xls($data, $profile, $safeName),
                'pdf' => $this->pdf($data, $profile, $safeName, $validated),
            };
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function csv(array $data, string $name)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map(fn ($c) => $c['label'], $data['columns']));

        foreach ($data['rows'] as $row) {
            $values = [];
            foreach ($data['columns'] as $c) {
                $values[] = $this->value($row, $c['key']);
            }
            fputcsv($stream, $values);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '.csv"',
        ]);
    }

    private function xls(array $data, array $profile, string $name)
    {
        $html = '<!doctype html><html dir="rtl"><head><meta charset="UTF-8"><style>' .
            'body{font-family:Arial,sans-serif;direction:rtl}.head{font-size:18px;font-weight:bold;margin-bottom:12px}' .
            'table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:7px}th{background:#e9eef5;font-weight:bold}' .
            '</style></head><body>';
        $html .= '<div class="head">' . e($profile['company_name']) . ' — ' . e($data['title']) . '</div><table><thead><tr>';
        foreach ($data['columns'] as $c) $html .= '<th>' . e($c['label']) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            $html .= '<tr>';
            foreach ($data['columns'] as $c) $html .= '<td>' . e((string) $this->value($row, $c['key'])) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if (!empty($data['summary'])) {
            $html .= '<br><table style="width:auto;min-width:420px"><tbody>';
            foreach ($data['summary'] as $k => $v) {
                $display = is_numeric($v) ? number_format((float) $v, 3, '.', ',') : (string) $v;
                $html .= '<tr><th>' . e($this->summaryLabel((string) $k)) . '</th><td>' . e($display) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</body></html>';

        return response("\xEF\xBB\xBF" . $html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '.xls"',
        ]);
    }

    private function pdf(array $data, array $profile, string $name, array $filters)
    {
        $html = $this->reportHtml($data, $profile, $filters);
        $orientation = count($data['columns']) > 7 ? 'landscape' : ($profile['print_options']['orientation'] ?? 'portrait');
        $content = app(ChromiumPdfRenderer::class)->render($html, in_array($orientation, ['portrait','landscape'], true) ? $orientation : 'portrait');
        return response($content, 200, ['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$name.'.pdf"']);
    }

    private function reportHtml(array $data, array $profile, array $filters): string
    {
        $period = '';
        if (!empty($filters['from_date']) || !empty($filters['to_date'])) {
            $period = 'الفترة: ' . e($filters['from_date'] ?? 'البداية') . ' إلى ' . e($filters['to_date'] ?? 'اليوم');
        }

        $html = '<!doctype html><html dir="rtl"><head><meta charset="UTF-8"><style>' .
            '@page{margin:22px 24px}body{font-family:DejaVu Sans,sans-serif;direction:rtl;color:#172033;font-size:10px}' .
            '.header{border-bottom:2px solid #0B2A4A;padding-bottom:10px;margin-bottom:12px}.company{font-size:16px;font-weight:bold;color:#0B2A4A}' .
            '.title{font-size:15px;font-weight:bold;margin-top:6px}.muted{color:#64748b;font-size:9px}.meta{margin-top:5px}' .
            'table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#edf2f7;color:#0B2A4A}th,td{border:1px solid #cbd5e1;padding:5px;text-align:right;vertical-align:top}' .
            '.summary{margin-top:10px;padding:8px;background:#f8fafc;border:1px solid #dbe3ec}.footer{margin-top:14px;border-top:1px solid #cbd5e1;padding-top:8px;color:#64748b;font-size:8px;page-break-inside:avoid}' .
            'thead{display:table-header-group}tr{page-break-inside:avoid}.header{page-break-after:avoid}' .
            '</style></head><body>';
        $printOptions = $profile['print_options'];
        $reportTemplate = $printOptions['templates']['report'] ?? [];
        $variant = $reportTemplate['selected'] ?? 'CLASSIC';
        $variantOptions = $reportTemplate['variants'][$variant] ?? [];
        $printOptions['visibility'] = array_merge($printOptions['visibility'] ?? [], $reportTemplate['visibility'] ?? [], $variantOptions['visibility'] ?? []);
        $printOptions['watermark'] = array_merge($printOptions['watermark'] ?? [], $variantOptions['watermark'] ?? []);
        if ($variant === 'FULL_HEADER' && empty($printOptions['header_mode'])) $printOptions['header_mode'] = 'FULL_IMAGE';
        $visible = static fn (string $key): bool => in_array($key, ['signature', 'stamp'], true)
            ? ($printOptions['visibility'][$key] ?? false) === true
            : ($printOptions['visibility'][$key] ?? true) !== false;
        $headerImage = $visible('header_image') && ($printOptions['header_mode'] ?? '') !== 'TEXT' && !empty($profile['header_image_data_uri'])
            ? '<img src="' . e($profile['header_image_data_uri']) . '" style="display:block;width:100%;max-height:80px;object-fit:contain;margin-bottom:8px">'
            : '';
        $logo = $visible('logo') && !in_array(($printOptions['header_mode'] ?? ''), ['TEXT', 'FULL_IMAGE'], true) && !empty($profile['logo_data_uri'])
            ? '<img src="' . e($profile['logo_data_uri']) . '" style="width:54px;height:54px;object-fit:contain;float:right;margin-left:10px">'
            : '';
        $headerText = $this->localizedPrintText($profile['print_header_texts'], $profile['print_locale']);
        $mark = $printOptions['watermark'] ?? [];
        if ($visible('watermark') && !empty($mark['enabled'])) {
            $opacity = max(.03, min(.3, (float)($mark['opacity'] ?? .12)));
            $size = max(12, min(160, (int)($mark['size'] ?? 42)));
            $angle = max(-70, min(70, (int)($mark['angle'] ?? -30)));
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($mark['color'] ?? '')) ? $mark['color'] : '#64748b';
            $position = match ($mark['position'] ?? 'CENTER') {'TOP'=>'22%','BOTTOM'=>'68%',default=>'45%'};
            $placement = ($mark['pages'] ?? 'ALL') === 'FIRST' ? 'absolute' : 'fixed';
            $markContent = ($mark['mode'] ?? 'TEXT') === 'IMAGE' && !empty($profile['watermark_data_uri'])
                ? '<img src="' . e($profile['watermark_data_uri']) . '" style="max-width:140mm;max-height:65mm">'
                : e((string)($mark['text'] ?? $profile['company_name']));
            $html .= '<div style="position:' . $placement . ';top:' . $position . ';left:15%;width:70%;text-align:center;opacity:' . $opacity . ';font-size:' . $size . 'px;color:' . $color . ';transform:rotate(' . $angle . 'deg);z-index:-1">' . $markContent . '</div>';
        }
        $html .= '<div class="header">' . $headerImage . $logo . '<div class="company">' . ($visible('company_name') && ($printOptions['show_company_name'] ?? true) !== false && ($printOptions['header_mode'] ?? '') !== 'FULL_IMAGE' ? e($profile['company_name']) : '') . '</div><div class="title">' . e($data['title']) . '</div>';
        if ($headerText !== '') $html .= '<div class="muted">' . nl2br(e($headerText)) . '</div>';
        $html .= '<div class="meta">' . e($period) . '</div><div class="muted">تاريخ الإصدار: ' . e($data['generated_at']) . ' | الفرع: ' . e($profile['branch_name']) . '</div></div>';
        $html .= '<table><thead><tr>';
        foreach ($data['columns'] as $c) $html .= '<th>' . e($c['label']) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['rows'] as $row) {
            $html .= '<tr>';
            foreach ($data['columns'] as $c) {
                $v = $this->value($row, $c['key']);
                if (($c['type'] ?? '') === 'number' && is_numeric($v)) $v = number_format((float) $v, 3, '.', ',');
                $html .= '<td>' . e((string) $v) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if (!empty($data['summary'])) {
            $html .= '<div class="summary">';
            foreach ($data['summary'] as $k => $v) $html .= '<span style="margin-left:18px"><b>' . e($this->summaryLabel((string) $k)) . ':</b> ' . e(is_numeric($v) ? number_format((float)$v,3,'.',',') : (string)$v) . '</span>';
            $html .= '</div>';
        }
        $footerText = $this->localizedPrintText($profile['print_footer_texts'], $profile['print_locale']) ?: $profile['report_footer'];
        $footerImage = $visible('footer_image') && ($printOptions['footer_mode'] ?? '') !== 'TEXT' && !empty($profile['footer_image_data_uri'])
            ? '<img src="' . e($profile['footer_image_data_uri']) . '" style="display:block;width:100%;max-height:60px;object-fit:contain;margin-top:6px">'
            : '';
        $signature = $visible('signature') && !empty($profile['signature_data_uri'])
            ? '<img src="' . e($profile['signature_data_uri']) . '" style="max-width:35mm;max-height:18mm;object-fit:contain;margin:5px">' : '';
        $stamp = $visible('stamp') && !empty($profile['stamp_data_uri'])
            ? '<img src="' . e($profile['stamp_data_uri']) . '" style="max-width:25mm;max-height:25mm;object-fit:contain;margin:5px">' : '';
        $html .= '<div class="footer">' . $signature . $stamp . ($visible('footer_notes') && ($printOptions['footer_mode'] ?? '') !== 'IMAGE' ? nl2br(e($footerText)) : '') . $footerImage . '</div></body></html>';
        return $html;
    }

    private function printProfile(int $companyId, ?int $branchId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first();
        $settings = DB::table('company_settings')->where('company_id', $companyId)->first();
        $branch = $branchId ? DB::table('branches')->where('company_id', $companyId)->where('id', $branchId)->first() : null;

        return [
            'company_id' => $companyId,
            'company_name' => $settings?->print_company_name ?? $company->company_name ?? 'صلب ERP',
            'phone' => $settings?->print_phone ?? $company->phone ?? null,
            'email' => $settings?->print_email ?? $company->email ?? null,
            'city' => $settings?->print_city ?? $company->city ?? null,
            'address' => $settings?->print_address ?? $company->address ?? null,
            'tax_number' => $settings?->tax_number ?? null,
            'commercial_register' => $settings?->commercial_register ?? null,
            'currency_name' => $settings?->currency_name ?? 'ريال',
            'currency_code' => $settings?->base_currency_code ?? $settings?->currency_code ?? 'USD',
            'has_logo' => !empty($settings?->logo_path),
            'has_header_image' => !empty($settings?->header_image_path),
            'has_footer_image' => !empty($settings?->footer_image_path),
            'has_signature' => !empty($settings?->signature_path),
            'has_stamp' => !empty($settings?->stamp_path),
            'has_watermark' => !empty($settings?->watermark_path),
            'logo_data_uri' => $this->brandingDataUri($settings?->logo_path),
            'header_image_data_uri' => $this->brandingDataUri($settings?->header_image_path),
            'footer_image_data_uri' => $this->brandingDataUri($settings?->footer_image_path),
            'signature_data_uri' => $this->brandingDataUri($settings?->signature_path),
            'stamp_data_uri' => $this->brandingDataUri($settings?->stamp_path),
            'watermark_data_uri' => $this->brandingDataUri($settings?->watermark_path),
            'print_header_texts' => json_decode((string)($settings?->print_header_texts ?? ''), true) ?: [],
            'print_footer_texts' => json_decode((string)($settings?->print_footer_texts ?? ''), true) ?: [],
            'print_options' => json_decode((string)($settings?->print_options ?? ''), true) ?: [],
            'print_locale' => in_array(data_get($company, 'default_language'), ['ar','en','ur','ja'], true) ? data_get($company, 'default_language') : 'ar',
            'report_footer' => $settings?->report_footer ?? 'تم إنشاء هذا التقرير من نظام صلب ERP.',
            'invoice_footer' => $settings?->invoice_footer ?? null,
            'primary_color' => $settings?->primary_color ?? '#0B2A4A',
            'secondary_color' => $settings?->secondary_color ?? '#123D68',
            'branch_name' => $branch->branch_name ?? 'جميع الفروع',
        ];
    }

    private function resolvedBranchId(Request $request, AccountingContext $context): ?int
    {
        $scopedBranch = $context->branchFilter($request);
        if ($scopedBranch !== null) {
            return $scopedBranch;
        }

        $requested = (int) $request->input('branch_id', 0);
        if ($requested > 0) {
            TenantScope::assertBranchBelongsToCompany($requested, $request);
            return $requested;
        }

        return null;
    }

    private function brandingDataUri(?string $path): ?string
    {
        if (!$path) return null;
        try {
            $disk = str_starts_with($path, 'print-branding/') ? 'local' : 'public';
            if (!Storage::disk($disk)->exists($path)) return null;
            $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk($disk)->get($path));
        } catch (\Throwable) {
            return null;
        }
    }

    private function localizedPrintText(array $texts, string $locale): string
    {
        return trim((string)($texts[$locale] ?? $texts['ar'] ?? $texts['en'] ?? ''));
    }

    private function summaryLabel(string $key): string
    {
        return [
            'count'=>'عدد السجلات','total'=>'الإجمالي','total_before_vat'=>'قبل الضريبة','vat'=>'الضريبة',
            'balance'=>'الرصيد','balance_kg'=>'الرصيد كجم','stock_value'=>'قيمة المخزون','received_kg'=>'الوارد كجم',
            'remaining_kg'=>'المتبقي كجم','in_kg'=>'دخول كجم','out_kg'=>'خروج كجم','input_kg'=>'مدخل كجم',
            'output_kg'=>'مخرج كجم','loss_kg'=>'فاقد كجم','net_kg'=>'صافي كجم','purchase_total'=>'المشتريات',
            'direct_costs'=>'تكاليف مباشرة','revenue'=>'الإيراد','cogs'=>'تكلفة المباع','cost'=>'التكلفة',
            'profit'=>'الربح','gross_profit'=>'مجمل الربح','expenses'=>'المصروفات','operating_result'=>'النتيجة التشغيلية',
            'debit'=>'المدين','credit'=>'الدائن','opening_debit'=>'افتتاحي مدين','opening_credit'=>'افتتاحي دائن',
            'closing_debit'=>'ختامي مدين','closing_credit'=>'ختامي دائن','difference'=>'الفرق','net_result'=>'صافي النتيجة',
            'total_assets'=>'إجمالي الأصول','total_liabilities'=>'إجمالي الالتزامات','total_equity'=>'إجمالي حقوق الملكية',
            'liabilities_equity'=>'الالتزامات وحقوق الملكية','purchase_cost'=>'تكلفة الأصول',
            'accumulated_depreciation'=>'مجمع الإهلاك','book_value'=>'القيمة الدفترية','depreciation'=>'الإهلاك',
            'net_salary'=>'صافي الرواتب','qty'=>'الكمية',
        ][$key] ?? $key;
    }

    private function value($row, string $key): mixed
    {
        if (is_array($row)) return $row[$key] ?? '';
        return $row->{$key} ?? '';
    }
}
