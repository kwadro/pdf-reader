<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BarcodeScan\PdfCodeScanMethodInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Runs multiple detection methods and merges unique codes for all pages.
 */
final class PdfBarcodeScanner
{
    /**
     * @param iterable<PdfCodeScanMethodInterface> $methods
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly iterable $methods,
        private readonly PdfVersionTransfer $versionTransfer,
    ) {
    }

    /**
     * @return list<array{page: int, type: string, data: string, method: string}>
     */
    public function scan(UploadedFile $pdf, ?int $maxPages = null): array
    {
        $this->assertUploadedPdf($pdf);

        $workDir = $this->createWorkDir('barcode');

        try {
            $pdf->move($workDir, 'input.pdf');
            $sourcePdf = $workDir.'/input.pdf';
            $pdfPath = $this->versionTransfer->ensureFpdiCompatible($sourcePdf, $workDir);

            try {
                return $this->scanPreparedPdf($pdfPath, $workDir, $maxPages);
            } catch (\Throwable) {
                $pdfPath = $this->versionTransfer->forceToPdf14($sourcePdf, $workDir);

                return $this->scanPreparedPdf($pdfPath, $workDir, $maxPages);
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * @return list<array{page: int, type: string, data: string, method: string}>
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
            $normalized = $this->versionTransfer->ensureFpdiCompatible($pdfPath, $workDir);

            return $this->scanPreparedPdf($normalized, $workDir, $maxPages);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Scan an already prepared (optionally normalized) PDF path. Caller owns $workDir lifetime.
     *
     * @return list<array{page: int, type: string, data: string, method: string}>
     */
    public function scanPreparedPdf(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        return $this->scanPdfPath($pdfPath, $workDir, $maxPages);
    }

    /**
     * @return list<string>
     */
    public function getAvailableMethods(): array
    {
        $names = [];
        foreach ($this->methods as $method) {
            if ($method->isSupported()) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * @return list<array{page: int, type: string, data: string, method: string}>
     */
    private function scanPdfPath(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        $merged = [];
        $seen = [];
        $anySupported = false;

        foreach ($this->methods as $method) {
            if (!$method->isSupported()) {
                continue;
            }

            $anySupported = true;
            $methodDir = $workDir.'/'.$method->getName();
            if (!is_dir($methodDir) && !mkdir($methodDir, 0775, true) && !is_dir($methodDir)) {
                continue;
            }

            try {
                $found = $method->scan($pdfPath, $methodDir, $maxPages);
            } catch (\Throwable) {
                continue;
            }

            foreach ($found as $code) {
                $key = $code['page'].'|'.$code['type'].'|'.$code['data'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = [
                    'page' => $code['page'],
                    'type' => $code['type'],
                    'data' => $code['data'],
                    'method' => $code['method'] ?? $method->getName(),
                ];
            }
        }

        if (!$anySupported) {
            throw new RuntimeException(
                'No PDF code scan methods are available. Enable imagick and/or install smalot/pdfparser.'
            );
        }

        $merged = $this->preferPrimaryCodesPerPage($merged);

        usort(
            $merged,
            static fn (array $a, array $b): int => [$a['page'], $a['type'], $a['data']]
                <=> [$b['page'], $b['type'], $b['data']]
        );

        return $merged;
    }

    /**
     * On each page, if I2/5 or QR-Code exists, drop weaker types for that page only.
     *
     * @param list<array{page: int, type: string, data: string, method: string}> $codes
     *
     * @return list<array{page: int, type: string, data: string, method: string}>
     */
    private function preferPrimaryCodesPerPage(array $codes): array
    {
        $pagesWithPrimary = [];
        foreach ($codes as $code) {
            if (in_array($code['type'], ['I2/5', 'QR-Code'], true)) {
                $pagesWithPrimary[$code['page']] = true;
            }
        }

        if ($pagesWithPrimary === []) {
            return $codes;
        }

        return array_values(array_filter(
            $codes,
            static function (array $code) use ($pagesWithPrimary): bool {
                if (!isset($pagesWithPrimary[$code['page']])) {
                    return true;
                }

                return in_array($code['type'], ['I2/5', 'QR-Code'], true);
            }
        ));
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
