<?php
namespace Tests\Unit;

use App\Services\Print\ChromiumPdfRenderer;

class ChromiumPdfRendererDiagnosticTest extends \Tests\TestCase
{
    public function test_explicit_binary_configuration_keeps_precedence(): void
    {
        config(['sulb_features.pdf.chromium_path' => PHP_BINARY]);
        $method = new \ReflectionMethod(ChromiumPdfRenderer::class, 'binary');
        self::assertSame(PHP_BINARY, $method->invoke(new ChromiumPdfRenderer));
    }

    public function test_failed_renderer_reports_exit_and_missing_output_instead_of_blank_error(): void
    {
        // PHP is a real executable, but cannot understand Chromium's arguments.
        // This exercises failure reporting without a fake success PDF or business DB.
        config(['sulb_features.pdf.chromium_path' => PHP_BINARY]);
        try {
            (new ChromiumPdfRenderer)->render('<html><head></head><body>Diagnostic fixture</body></html>');
            self::fail('Non-Chromium executable must not return a PDF');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString(basename(PHP_BINARY), $error->getMessage());
            self::assertMatchesRegularExpression('/exit=\d+/', $error->getMessage());
            self::assertStringContainsString('output=missing', $error->getMessage());
        }
    }
}
