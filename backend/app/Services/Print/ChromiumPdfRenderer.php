<?php

namespace App\Services\Print;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ChromiumPdfRenderer
{
    public function render(string $html, string $orientation = 'portrait'): string
    {
        $binary = $this->binary();
        $directory = storage_path('framework/pdf-render');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('تعذر إنشاء مجلد PDF المؤقت.');
        }

        $token = bin2hex(random_bytes(16));
        $htmlPath = $directory.DIRECTORY_SEPARATOR.$token.'.html';
        $pdfPath = $directory.DIRECTORY_SEPARATOR.$token.'.pdf';
        $profilePath = $directory.DIRECTORY_SEPARATOR.'profile-'.$token;
        $pageSize = strtolower($orientation) === 'landscape' ? 'A4 landscape' : 'A4 portrait';
        $html = str_replace('</head>', '<style>@page{size:'.$pageSize.'}</style></head>', $html);

        try {
            if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
                throw new RuntimeException('تعذر تجهيز ملف PDF المؤقت.');
            }
            $process = new Process([
                $binary, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                '--disable-extensions', '--disable-sync', '--disable-background-networking',
                '--allow-file-access-from-files', '--print-to-pdf-no-header',
                '--user-data-dir='.$profilePath, '--print-to-pdf='.$pdfPath, $this->fileUrl($htmlPath),
            ]);
            $process->setTimeout(60);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                throw new RuntimeException('تعذر إنشاء PDF بمحرك Chromium: '.trim($process->getErrorOutput()));
            }
            $pdf = file_get_contents($pdfPath);
            if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('أنشأ محرك PDF ملفًا غير صالح.');
            }
            return $pdf;
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
            $this->removeDirectory($profilePath);
        }
    }

    private function binary(): string
    {
        $configured = trim((string) config('sulb_features.pdf.chromium_path', ''));
        $candidates = array_filter([$configured,
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
        ]);
        foreach ($candidates as $candidate) if (is_file($candidate) && is_executable($candidate)) return $candidate;
        throw new RuntimeException('محرك Chromium المطلوب لملفات PDF العربية غير مهيأ. اضبط SULB_PDF_CHROMIUM_PATH.');
    }

    private function fileUrl(string $path): string
    {
        return 'file:///'.str_replace(['\\', ' '], ['/', '%20'], $path);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) return;
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
