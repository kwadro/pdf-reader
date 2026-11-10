<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Process\Process;
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

            $pageCodes = [];
            $text = $this->normalizePdfText($page->getText());
            foreach ($this->extractCodesFromText($text) as $code) {
                $pageCodes[] = $this->withMeta($pageNumber, $code);
            }

            if (!$this->hasPrimaryCode($pageCodes)) {
                foreach ($this->extractCodesFromPageObjects($page, $workDir, $pageNumber) as $code) {
                    $pageCodes[] = $this->withMeta($pageNumber, $code);
                }
            }

            if ($this->hasPrimaryCode($pageCodes)) {
                $pageCodes = array_values(array_filter(
                    $pageCodes,
                    static fn (array $code): bool => in_array($code['type'], ['I2/5', 'QR-Code'], true)
                ));
            }

            foreach ($pageCodes as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param list<array{type: string}> $codes
     */
    private function hasPrimaryCode(array $codes): bool
    {
        foreach ($codes as $code) {
            $type = $code['type'] ?? '';
            if ($type === 'I2/5' || $type === 'QR-Code') {
                return true;
            }
        }

        return false;
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

        // Some FPDF/CID extracts store digits as 0x13..0x1C instead of ASCII '0'..'9'
        // (seen on Ticketcorner print@home PDFs). Map them back so text scanning works
        // without zbar/imagick on the server.
        $text = preg_replace_callback(
            '/[\x13-\x1c]+/',
            static function (array $m): string {
                $out = '';
                $len = strlen($m[0]);
                for ($i = 0; $i < $len; ++$i) {
                    $out .= chr(ord('0') + (ord($m[0][$i]) - 0x13));
                }

                return $out;
            },
            $text
        ) ?? $text;

        return trim(preg_replace("/[ \t]+/u", ' ', $text) ?? $text);
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function extractCodesFromText(string $text): array
    {
        $codes = [];
        $seen = [];

        $pushDigits = function (string $digits) use (&$codes, &$seen): void {
            if ($this->isTrivialDigitCode($digits) || isset($seen[$digits])) {
                return;
            }
            $len = strlen($digits);
            if ($len < 14 || $len > 48) {
                return;
            }
            $seen[$digits] = true;
            $codes[] = ['type' => 'I2/5', 'data' => $digits];
        };

        if (preg_match_all('/(?<!\d)(\d{14,48})(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $digits) {
                $pushDigits($digits);
            }
        }

        // Ticket IDs printed as dashed groups: 0621-5775-0935-8872-3172
        if (preg_match_all('/(?<![\d-])(\d{4}(?:-\d{4}){3,11})(?![\d-])/', $text, $matches)) {
            foreach ($matches[1] as $grouped) {
                $pushDigits(str_replace('-', '', $grouped));
            }
        }

        // Prefer I2/5 — do not collect weaker text matches when barcode is present.
        if ($codes !== []) {
            return $codes;
        }

        if (preg_match_all('/\b(?=[A-Z0-9]*[A-Z])(?=[A-Z0-9]*\d)[A-Z0-9]{6,16}\b/i', $text, $matches)) {
            foreach (array_unique($matches[0]) as $value) {
                if (preg_match('/^\d+$/', $value)) {
                    continue;
                }
                if ($this->isWebAddressCode($value)) {
                    continue;
                }
                $codes[] = ['type' => 'TEXT-CODE', 'data' => strtoupper($value)];
            }
        }

        return $codes;
    }

    private function isWebAddressCode(string $value): bool
    {
        return preg_match('#^(https?://|www\.)#i', trim($value)) === 1;
    }

    private function isTrivialDigitCode(string $digits): bool
    {
        // Barcode art / placeholders often extract as runs of the same digit (e.g. 111111111111111).
        return $digits !== '' && preg_match('/^(\d)\1+$/', $digits) === 1;
    }

    /**
     * Decode embedded page images (JPEG/PNG/FlateDecode) and read barcodes via zbar / QrReader.
     * Large images are never passed to QrReader (it OOMs and aborts the whole request).
     *
     * @return list<array{type: string, data: string}>
     */
    private function extractCodesFromPageObjects(object $page, string $workDir, int $pageNumber): array
    {
        $zbarAvailable = $this->commandExists('zbarimg');
        $qrAvailable = class_exists(QrReader::class) && \function_exists('imagecreatefromstring');
        if (!$zbarAvailable && !$qrAvailable) {
            return [];
        }

        try {
            $xObjects = method_exists($page, 'getXObjects') ? $page->getXObjects() : [];
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        $seen = [];
        $index = 0;

        foreach ($xObjects as $xObject) {
            ++$index;
            if (!is_object($xObject)) {
                continue;
            }

            $imagePath = $this->materializeXObjectImage($xObject, $workDir, $pageNumber, $index);
            if ($imagePath === null) {
                continue;
            }

            $pixels = $this->imagePixelCount($imagePath);

            if ($zbarAvailable) {
                foreach ($this->decodeWithZbar($imagePath) as $code) {
                    $key = $code['type'].'|'.$code['data'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $codes[] = $code;
                }
            }

            // QrReader allocates huge GD buffers — skip page-sized images.
            if ($qrAvailable && $pixels > 0 && $pixels <= 640_000) {
                foreach ($this->decodeQr($imagePath) as $code) {
                    $key = $code['type'].'|'.$code['data'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * @return non-empty-string|null
     */
    private function materializeXObjectImage(object $xObject, string $workDir, int $pageNumber, int $index): ?string
    {
        $content = method_exists($xObject, 'getContent') ? $xObject->getContent() : null;
        if (!is_string($content) || $content === '') {
            return null;
        }

        if (str_starts_with($content, "\xFF\xD8") || str_starts_with($content, "\x89PNG")) {
            $ext = str_starts_with($content, "\xFF\xD8") ? 'jpg' : 'png';
            $imagePath = sprintf('%s/text-xobj-p%d-%d.%s', $workDir, $pageNumber, $index, $ext);
            if (file_put_contents($imagePath, $content) === false) {
                return null;
            }

            return $imagePath;
        }

        if (!method_exists($xObject, 'getDetails') || !\function_exists('imagecreatetruecolor')) {
            return null;
        }

        /** @var array<string, mixed> $details */
        $details = $xObject->getDetails();
        if (($details['Subtype'] ?? '') !== 'Image') {
            return null;
        }

        $width = (int) ($details['Width'] ?? 0);
        $height = (int) ($details['Height'] ?? 0);
        if ($width < 40 || $height < 40 || $width * $height > 2_000_000) {
            return null;
        }

        $raw = $this->decodePdfImageSamples($content, $details, $width, $height);
        if ($raw === null) {
            return null;
        }

        $imagePath = sprintf('%s/text-xobj-p%d-%d.png', $workDir, $pageNumber, $index);
        if (!$this->writeRawRgbPng($raw, $width, $height, $imagePath)) {
            return null;
        }

        return $imagePath;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function decodePdfImageSamples(string $content, array $details, int $width, int $height): ?string
    {
        $colorSpace = $details['ColorSpace'] ?? '';
        if (is_array($colorSpace)) {
            $colorSpace = (string) ($colorSpace[0] ?? '');
        }
        $colorSpace = (string) $colorSpace;

        $colors = match ($colorSpace) {
            'DeviceGray', 'Gray', '' => 1,
            'DeviceRGB', 'RGB' => 3,
            default => 0,
        };
        if ($colors === 0) {
            return null;
        }

        $decodeParms = $details['DecodeParms'] ?? null;
        if (!is_array($decodeParms)) {
            $expected = $width * $height * $colors;
            if (strlen($content) !== $expected) {
                return null;
            }

            if ($colors === 1) {
                // Expand gray to RGB for a uniform PNG writer.
                $rgb = '';
                $len = strlen($content);
                for ($i = 0; $i < $len; ++$i) {
                    $rgb .= $content[$i].$content[$i].$content[$i];
                }

                return $rgb;
            }

            return $content;
        }

        $predictor = (int) ($decodeParms['Predictor'] ?? 1);
        $columns = (int) ($decodeParms['Columns'] ?? $width);
        $parmColors = (int) ($decodeParms['Colors'] ?? $colors);
        if ($predictor < 10 || $columns !== $width || $parmColors !== $colors) {
            // Only PNG predictors (10-15) with matching geometry are handled.
            if ($predictor < 10) {
                $expected = $width * $height * $colors;
                return strlen($content) === $expected ? ($colors === 1 ? $this->grayToRgb($content) : $content) : null;
            }

            return null;
        }

        $decoded = $this->decodePngPredictor($content, $width, $height, $colors);
        if ($decoded === null) {
            return null;
        }

        return $colors === 1 ? $this->grayToRgb($decoded) : $decoded;
    }

    private function grayToRgb(string $gray): string
    {
        $rgb = '';
        $len = strlen($gray);
        for ($i = 0; $i < $len; ++$i) {
            $rgb .= $gray[$i].$gray[$i].$gray[$i];
        }

        return $rgb;
    }

    private function decodePngPredictor(string $data, int $width, int $height, int $colors): ?string
    {
        $rowSamples = $width * $colors;
        $rowLen = 1 + $rowSamples;
        if ($rowLen * $height !== strlen($data)) {
            return null;
        }

        $out = '';
        $prev = str_repeat("\0", $rowSamples);
        $offset = 0;

        for ($y = 0; $y < $height; ++$y) {
            $filter = ord($data[$offset]);
            ++$offset;
            $row = substr($data, $offset, $rowSamples);
            $offset += $rowSamples;
            $decoded = '';

            for ($i = 0; $i < $rowSamples; ++$i) {
                $x = ord($row[$i]);
                $a = $i >= $colors ? ord($decoded[$i - $colors]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $colors ? ord($prev[$i - $colors]) : 0;
                $val = match ($filter) {
                    0 => $x,
                    1 => ($x + $a) & 255,
                    2 => ($x + $b) & 255,
                    3 => ($x + intdiv($a + $b, 2)) & 255,
                    4 => ($x + $this->paethPredictor($a, $b, $c)) & 255,
                    default => $x,
                };
                $decoded .= \chr($val);
            }

            $out .= $decoded;
            $prev = $decoded;
        }

        return $out;
    }

    private function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }

    private function writeRawRgbPng(string $rgb, int $width, int $height, string $path): bool
    {
        if (strlen($rgb) !== $width * $height * 3) {
            return false;
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return false;
        }

        $i = 0;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $color = (ord($rgb[$i]) << 16) | (ord($rgb[$i + 1]) << 8) | ord($rgb[$i + 2]);
                $i += 3;
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $ok = imagepng($img, $path);
        imagedestroy($img);

        return $ok && is_file($path);
    }

    private function imagePixelCount(string $imagePath): int
    {
        $info = @getimagesize($imagePath);
        if ($info === false) {
            return 0;
        }

        return max(0, (int) $info[0]) * max(0, (int) $info[1]);
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function decodeWithZbar(string $imagePath): array
    {
        $process = new Process(['zbarimg', '--quiet', '-S*.enable', $imagePath]);
        $process->setTimeout(60);
        $process->run();

        $exitCode = $process->getExitCode();
        if (!$process->isSuccessful() && !in_array($exitCode, [1, 4], true)) {
            return [];
        }

        return $this->parseZbarOutput($process->getOutput());
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function decodeQr(string $imagePath): array
    {
        try {
            $reader = new QrReader($imagePath, QrReader::SOURCE_TYPE_FILE, false);
            $text = $reader->text();
            if (is_string($text) && $text !== '') {
                return [['type' => 'QR-Code', 'data' => $text]];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function parseZbarOutput(string $output): array
    {
        $codes = [];
        $output = trim($output);
        if ($output === '') {
            return $codes;
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                $codes[] = ['type' => 'UNKNOWN', 'data' => $line];
                continue;
            }

            $type = substr($line, 0, $pos);
            $data = substr($line, $pos + 1);
            if ($type === 'CODE-128' && $data !== '' && ctype_digit($data)) {
                $type = 'I2/5';
            }
            $codes[] = ['type' => $type, 'data' => $data];
        }

        return $codes;
    }

    private function commandExists(string $command): bool
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($command));
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }
}
