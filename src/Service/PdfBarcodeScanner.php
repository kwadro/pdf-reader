<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zxing\QrReader;

/**
 * Scans QR / barcodes from PDF using Composer packages only
 * (no pdftoppm, zbarimg, or ImageMagick CLI).
 */
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
        try {
            $pdf = (new PdfParser())->parseFile($pdfPath);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to parse PDF: '.$e->getMessage(), 0, $e);
        }

        $pages = $pdf->getPages();
        $codes = [];
        $seen = [];
        $pageNumber = 0;

        foreach ($pages as $page) {
            ++$pageNumber;

            if ($maxPages !== null && $pageNumber > $maxPages) {
                break;
            }

            $text = $this->normalizePdfText($page->getText());

            foreach ($this->extractCodesFromText($text) as $code) {
                $key = $pageNumber.'|'.$code['type'].'|'.$code['data'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $codes[] = [
                    'page' => $pageNumber,
                    'type' => $code['type'],
                    'data' => $code['data'],
                ];
            }

            foreach ($this->extractQrFromPageObjects($page, $workDir, $pageNumber) as $code) {
                $key = $pageNumber.'|'.$code['type'].'|'.$code['data'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $codes[] = [
                    'page' => $pageNumber,
                    'type' => $code['type'],
                    'data' => $code['data'],
                ];
            }
        }

        return $codes;
    }

    /**
     * Ticket PDFs often store text as UTF-16BE (null bytes between chars).
     */
    private function normalizePdfText(string $text): string
    {
        if (str_contains($text, "\x00")) {
            $text = str_replace("\x00", '', $text);
        }

        // Some parsers leave UTF-16 BOM leftovers.
        $text = str_replace(["\xFE\xFF", "\xFF\xFE"], '', $text);

        return trim(preg_replace("/[ \t]+/u", ' ', $text) ?? $text);
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function extractCodesFromText(string $text): array
    {
        $codes = [];

        // Interleaved 2 of 5 / ticket barcodes: long numeric payloads.
        if (preg_match_all('/(?<!\d)(\d{16,48})(?!\d)/', $text, $matches)) {
            foreach (array_unique($matches[1]) as $digits) {
                $codes[] = [
                    'type' => 'I2/5',
                    'data' => $digits,
                ];
            }
        }

        // TicketDirect-style codes: mix of letters + digits (e.g. 7YE5EHF1).
        if (preg_match_all('/\b(?=[A-Z0-9]*[A-Z])(?=[A-Z0-9]*\d)[A-Z0-9]{6,16}\b/i', $text, $matches)) {
            foreach (array_unique($matches[0]) as $value) {
                // Skip obvious date fragments like 10.10.2026 leftovers already normalized away.
                if (preg_match('/^\d+$/', $value)) {
                    continue;
                }
                $codes[] = [
                    'type' => 'TEXT-CODE',
                    'data' => strtoupper($value),
                ];
            }
        }

        // Standalone URLs (often encoded as QR payloads too).
        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            foreach (array_unique($matches[0]) as $url) {
                $codes[] = [
                    'type' => 'URL',
                    'data' => rtrim($url, '.,);'),
                ];
            }
        }

        return $codes;
    }

    /**
     * Try to decode QR codes from embedded page images (GD-based PHP package).
     *
     * @return list<array{type: string, data: string}>
     */
    private function extractQrFromPageObjects(object $page, string $workDir, int $pageNumber): array
    {
        if (!\function_exists('imagecreatefromstring')) {
            return [];
        }

        $codes = [];

        try {
            $xObjects = method_exists($page, 'getXObjects') ? $page->getXObjects() : [];
        } catch (\Throwable) {
            return [];
        }

        $index = 0;
        foreach ($xObjects as $xObject) {
            ++$index;
            $content = null;

            if (is_object($xObject) && method_exists($xObject, 'getContent')) {
                $content = $xObject->getContent();
            }

            if (!is_string($content) || $content === '') {
                continue;
            }

            // Only attempt on data that looks like a raster image.
            if (!str_starts_with($content, "\xFF\xD8") && !str_starts_with($content, "\x89PNG")) {
                continue;
            }

            $imagePath = sprintf('%s/xobj-p%d-%d.bin', $workDir, $pageNumber, $index);
            if (file_put_contents($imagePath, $content) === false) {
                continue;
            }

            try {
                $reader = new QrReader($imagePath, QrReader::SOURCE_TYPE_FILE, false);
                $text = $reader->text();
                if (is_string($text) && $text !== '') {
                    $codes[] = [
                        'type' => 'QR-Code',
                        'data' => $text,
                    ];
                }
            } catch (\Throwable) {
                // Not a QR image — ignore.
            }
        }

        return $codes;
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
