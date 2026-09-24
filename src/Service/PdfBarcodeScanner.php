<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class PdfBarcodeScanner
{
    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * @return list<array{page: int, type: string, data: string}>
     */
    public function scan(UploadedFile $pdf, ?int $maxPages = null): array
    {
        $this->assertUploadedPdf($pdf);

        $workDir = $this->createWorkDir('barcode');

        try {
            $pdf->move($workDir, 'input.pdf');

            return $this->scanPdfPath($workDir.'/input.pdf', $workDir, $maxPages);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * @return list<array{page: int, type: string, data: string}>
     */
    public function scanFile(string $pdfPath, ?int $maxPages = null): array
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException(sprintf('PDF file is not readable: %s', $pdfPath));
        }

        $ext = strtolower(pathinfo($pdfPath, \PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new RuntimeException(sprintf('File is not a PDF: %s', $pdfPath));
        }

        $workDir = $this->createWorkDir('barcode');

        try {
            return $this->scanPdfPath($pdfPath, $workDir, $maxPages);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * @return list<array{page: int, type: string, data: string}>
     */
    private function scanPdfPath(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        $prefix = $workDir.'/page';
        $command = [
            'pdftoppm',
            '-png',
            '-r', '200',
        ];

        if ($maxPages !== null && $maxPages > 0) {
            $command[] = '-f';
            $command[] = '1';
            $command[] = '-l';
            $command[] = (string) $maxPages;
        }

        $command[] = $pdfPath;
        $command[] = $prefix;

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to convert PDF pages to images: '.$process->getErrorOutput());
        }

        $images = glob($workDir.'/page-*.png') ?: [];
        natsort($images);

        $codes = [];
        foreach ($images as $imagePath) {
            $page = $this->extractPageNumber((string) $imagePath);
            foreach ($this->scanImage((string) $imagePath) as $code) {
                $codes[] = [
                    'page' => $page,
                    'type' => $code['type'],
                    'data' => $code['data'],
                ];
            }
        }

        return $codes;
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function scanImage(string $imagePath): array
    {
        $process = new Process([
            'zbarimg',
            '--quiet',
            $imagePath,
        ]);
        $process->setTimeout(60);
        $process->run();

        // zbarimg: 0 = found, 1/4 = no symbols — not fatal for our use case.
        $exitCode = $process->getExitCode();
        if (!$process->isSuccessful() && !in_array($exitCode, [1, 4], true)) {
            throw new RuntimeException('Failed to scan barcodes: '.$process->getErrorOutput());
        }

        $codes = [];
        $output = trim($process->getOutput());
        if ($output === '') {
            return $codes;
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Format: TYPE:payload
            $pos = strpos($line, ':');
            if ($pos === false) {
                $codes[] = ['type' => 'UNKNOWN', 'data' => $line];
                continue;
            }

            $codes[] = [
                'type' => substr($line, 0, $pos),
                'data' => substr($line, $pos + 1),
            ];
        }

        return $codes;
    }

    private function extractPageNumber(string $imagePath): int
    {
        if (preg_match('/page-(\d+)\.png$/', $imagePath, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function assertUploadedPdf(UploadedFile $pdf): void
    {
        if ($pdf->getError() !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('PDF upload failed.');
        }

        $mime = $pdf->getMimeType() ?? '';
        $ext = strtolower((string) $pdf->getClientOriginalExtension());

        if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true) && $ext !== 'pdf') {
            throw new RuntimeException('Uploaded file must be a PDF.');
        }
    }

    private function createWorkDir(string $prefix): string
    {
        $base = rtrim($this->storageDir, '/').'/tmp';
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create temp storage directory.');
        }

        $dir = $base.'/'.$prefix.'_'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create work directory.');
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
