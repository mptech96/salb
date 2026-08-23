<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\ReportCenterService;
use App\Support\TenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $pdf = Pdf::loadHTML($html)->setPaper('a4', count($data['columns']) > 7 ? 'landscape' : 'portrait');
        return $pdf->download($name . '.pdf');
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
            '.summary{margin-top:10px;padding:8px;background:#f8fafc;border:1px solid #dbe3ec}.footer{margin-top:14px;border-top:1px solid #cbd5e1;padding-top:8px;color:#64748b;font-size:8px}' .
            '</style></head><body>';
        $logo = !empty($profile['logo_data_uri'])
            ? '<img src="' . e($profile['logo_data_uri']) . '" style="width:54px;height:54px;object-fit:contain;float:right;margin-left:10px">'
            : '';
        $html .= '<div class="header">' . $logo . '<div class="company">' . e($profile['company_name']) . '</div><div class="title">' . e($data['title']) . '</div>';
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
        $html .= '<div class="footer">' . e($profile['report_footer']) . '</div></body></html>';
        return $html;
    }

    private function printProfile(int $companyId, ?int $branchId): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first();
        $settings = DB::table('company_settings')->where('company_id', $companyId)->first();
        $branch = $branchId ? DB::table('branches')->where('company_id', $companyId)->where('id', $branchId)->first() : null;

        return [
            'company_name' => $settings?->print_company_name ?? $company->company_name ?? 'صلب ERP',
            'phone' => $settings?->print_phone ?? $company->phone ?? null,
            'email' => $settings?->print_email ?? $company->email ?? null,
            'city' => $settings?->print_city ?? $company->city ?? null,
            'address' => $settings?->print_address ?? $company->address ?? null,
            'tax_number' => $settings?->tax_number ?? null,
            'commercial_register' => $settings?->commercial_register ?? null,
            'currency_name' => $settings?->currency_name ?? 'ريال',
            'currency_code' => $settings?->base_currency_code ?? $settings?->currency_code ?? 'USD',
            'logo_url' => !empty($settings?->logo_path) ? asset('storage/' . $settings->logo_path) : null,
            'logo_data_uri' => $this->logoDataUri($settings?->logo_path),
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

    private function logoDataUri(?string $path): ?string
    {
        if (!$path) return null;
        try {
            if (!Storage::disk('public')->exists($path)) return null;
            $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($path));
        } catch (\Throwable) {
            return null;
        }
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
