<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Zxing\QrReader;

/**
 * Method 1: extract codes from PDF text + embedded raster images (Composer only).
 */
#[AutoconfigureTag('app.pdf_code_scan_method', ['priority' => 100])]
final class TextPdfScanMethod implements PdfCodeScanMethodInterface
{
    public function getName(): string
    {
        return 'text';
    }

    public function isSupported(): bool
    {
        return class_exists(PdfParser::class);
    }

    public function scan(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        try {
            $pdf = (new PdfParser())->parseFile($pdfPath);
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        $pageNumber = 0;

        foreach ($pdf->getPages() as $page) {
            ++$pageNumber;
            if ($maxPages !== null && $pageNumber > $maxPages) {
                break;
            }

            $text = $this->normalizePdfText($page->getText());
            foreach ($this->extractCodesFromText($text) as $code) {
                $codes[] = $this->withMeta($pageNumber, $code);
            }

            foreach ($this->extractQrFromPageObjects($page, $workDir, $pageNumber) as $code) {
                $codes[] = $this->withMeta($pageNumber, $code);
            }
        }

        return $codes;
    }

    /**
     * @param array{type: string, data: string} $code
     *
     * @return array{page: int, type: string, data: string, method: string}
     */
    private function withMeta(int $page, array $code): array
    {
        return [
            'page' => $page,
            'type' => $code['type'],
            'data' => $code['data'],
            'method' => $this->getName(),
        ];
    }

    private function normalizePdfText(string $text): string
    {
        if (str_contains($text, "\x00")) {
            $text = str_replace("\x00", '', $text);
        }

        $text = str_replace(["\xFE\xFF", "\xFF\xFE"], '', $text);

        return trim(preg_replace("/[ \t]+/u", ' ', $text) ?? $text);
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function extractCodesFromText(string $text): array
    {
        $codes = [];

        if (preg_match_all('/(?<!\d)(\d{16,48})(?!\d)/', $text, $matches)) {
            foreach (array_unique($matches[1]) as $digits) {
                $codes[] = ['type' => 'I2/5', 'data' => $digits];
            }
        }

        if (preg_match_all('/\b(?=[A-Z0-9]*[A-Z])(?=[A-Z0-9]*\d)[A-Z0-9]{6,16}\b/i', $text, $matches)) {
            foreach (array_unique($matches[0]) as $value) {
                if (preg_match('/^\d+$/', $value)) {
                    continue;
                }
                $codes[] = ['type' => 'TEXT-CODE', 'data' => strtoupper($value)];
            }
        }

        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            foreach (array_unique($matches[0]) as $url) {
                $codes[] = ['type' => 'URL', 'data' => rtrim($url, '.,);')];
            }
        }

        return $codes;
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function extractQrFromPageObjects(object $page, string $workDir, int $pageNumber): array
    {
        if (!\function_exists('imagecreatefromstring') || !class_exists(QrReader::class)) {
            return [];
        }

        try {
            $xObjects = method_exists($page, 'getXObjects') ? $page->getXObjects() : [];
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        $index = 0;

        foreach ($xObjects as $xObject) {
            ++$index;
            $content = (is_object($xObject) && method_exists($xObject, 'getContent'))
                ? $xObject->getContent()
                : null;

            if (!is_string($content) || $content === '') {
                continue;
            }

            if (!str_starts_with($content, "\xFF\xD8") && !str_starts_with($content, "\x89PNG")) {
                continue;
            }

            $imagePath = sprintf('%s/text-xobj-p%d-%d.bin', $workDir, $pageNumber, $index);
            if (file_put_contents($imagePath, $content) === false) {
                continue;
            }

            try {
                $reader = new QrReader($imagePath, QrReader::SOURCE_TYPE_FILE, false);
                $decoded = $reader->text();
                if (is_string($decoded) && $decoded !== '') {
                    $codes[] = ['type' => 'QR-Code', 'data' => $decoded];
                }
            } catch (\Throwable) {
            }
        }

        return $codes;
    }
}
