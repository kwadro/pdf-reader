<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Orchestrates /pdf/codes: version transfer, scan all pages, optional page ZIP archives.
 */
final class PdfCodesService
{
    private const ARCHIVE_PAGE_LIMIT = 100;

    public function __construct(
        private readonly string $storageDir,
        private readonly PdfBarcodeScanner $barcodeScanner,
        private readonly PdfPageSplitter $pageSplitter,
        private readonly PdfVersionTransfer $versionTransfer,
    ) {
    }

    /**
     * @return array{
     *     count: int,
     *     pages: int,
     *     codes: list<array{page: int, type: string, data: string, method: string}>,
     *     downloads: list<array{id: string, pages: int, filename: string, download_url?: string, from: int, to: int}>
     * }
     */
    public function processUpload(UploadedFile $pdf): array
    {
        $this->assertPdf($pdf);

        $originalFilename = $this->resolveOriginalFilename($pdf);
        $workDir = $this->createWorkDir('codes');

        try {
            $pdf->move($workDir, 'input.pdf');
            $sourcePdf = $workDir . '/input.pdf';

            $pdfPath = $this->versionTransfer->ensureFpdiCompatible($sourcePdf, $workDir);

            $codes = $this->barcodeScanner->scanFile($pdfPath, 1);
            return [
                'originalFilename' => $originalFilename,
                'count' => count($codes),
                'codes' => $codes,
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function resolveOriginalFilename(UploadedFile $pdf): string
    {
        $name = basename(str_replace('\\', '/', (string) $pdf->getClientOriginalName()));
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'document.pdf';
        }

        return $name;
    }

    private function assertPdf(UploadedFile $pdf): void
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
