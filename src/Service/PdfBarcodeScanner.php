<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BarcodeScan\PdfCodeScanMethodInterface;
use App\Service\BarcodeScan\PurePhp1dBarcodeDecoder;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;
use Zxing\QrReader;

/**
 * Runs multiple detection methods and merges unique codes.
 */
final class PdfBarcodeScanner
{
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
        'image/tiff',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'];

    /**
     * @param iterable<PdfCodeScanMethodInterface> $methods
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly iterable $methods,
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

            return $this->scanPdfPath($workDir.'/input.pdf', $workDir, $maxPages);
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
            return $this->scanPdfPath($pdfPath, $workDir, $maxPages);
        } finally {
            $this->removeDirectory($workDir);
        }
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
                if ($this->isWebAddressCode($code['data'])) {
                    continue;
                }
                $type = $this->normalizeBarcodeType($code['type'], $code['data']);
                $key = $code['page'].'|'.$type.'|'.$code['data'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = [
                    'page' => $code['page'],
                    'type' => $type,
                    'data' => $code['data'],
                    'method' => $code['method'] ?? $method->getName(),
                ];
            }

            // Primary barcode is enough — skip slower methods (imagick / cli-zbar).
            if ($this->hasPrimaryCode($merged)) {
                break;
            }
        }

        if (!$anySupported) {
            throw new RuntimeException(
                'No PDF code scan methods are available. Enable imagick and/or install smalot/pdfparser.'
            );
        }

        if ($this->hasPrimaryCode($merged)) {
            $merged = array_values(array_filter(
                $merged,
                static fn (array $code): bool => in_array($code['type'], ['I2/5', 'QR-Code'], true)
            ));
        }

        usort(
            $merged,
            static fn (array $a, array $b): int => [$a['page'], $a['type'], $a['data']]
                <=> [$b['page'], $b['type'], $b['data']]
        );

        return $merged;
    }

    /**
     * Read barcodes from a raster image (JPEG, PNG, GIF, WebP, BMP, TIFF).
     *
     * @return list<array{type: string, data: string, method: string}>
     */
    public function scanImage(UploadedFile $image): array
    {
        $this->assertUploadedImage($image);

        $workDir = $this->createWorkDir('image');

        try {
            $ext = strtolower((string) $image->guessExtension());

            if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $ext = 'img';
            }

            $image->move($workDir, 'input.'.$ext);
            $path = $workDir.'/input.'.$ext;

            $scanPath = $this->prepareImageForScan($path, $workDir);

            $codes = $this->decodeImageCodes($scanPath);
            if ($codes === [] && $scanPath === $path) {
                $normalized = $this->prepareImageForScan($path, $workDir, true);

                if ($normalized !== $path) {
                    $codes = $this->decodeImageCodes($normalized);
                }
            }

            usort(
                $codes,
                static fn (array $a, array $b): int => [$a['type'], $a['data']] <=> [$b['type'], $b['data']]
            );

            return $codes;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Numeric CODE-128 ticket barcodes are treated as I2/5 for a stable API.
     */
    private function normalizeBarcodeType(string $type, string $data): string
    {
        if ($type === 'CODE-128' && $data !== '' && ctype_digit($data)) {
            return 'I2/5';
        }

        return $type;
    }

    private function isWebAddressCode(string $data): bool
    {
        return preg_match('#^(https?://|www\.)#i', trim($data)) === 1;
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

    private function assertUploadedImage(UploadedFile $image): void
    {
        if ($image->getError() !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('Image upload failed.');
        }

        $mime = $image->getMimeType() ?? '';
        $ext = strtolower((string) $image->getClientOriginalExtension());

        if (!in_array($mime, self::IMAGE_MIMES, true) && !in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('Uploaded file must be an image (JPEG, PNG, GIF, WebP, BMP, or TIFF).');
        }
    }

    private function prepareImageForScan(string $path, string $workDir, bool $force = false): string
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (!$force && in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return $path;
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return $path;
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return $path;
        }

        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            return $path;
        }

        $out = $workDir.'/scan.png';
        imagepng($gd, $out);
        imagedestroy($gd);

        return is_file($out) ? $out : $path;
    }

    /**
     * @return list<array{type: string, data: string, method: string}>
     */
    private function decodeImageCodes(string $imagePath): array
    {
        $merged = [];
        $seen = [];

        // Pure-PHP 1D first: low memory and works when zbarimg is missing on hosting.
        foreach ((new PurePhp1dBarcodeDecoder())->decodeFile($imagePath) as $code) {
            $this->pushImageCode($merged, $seen, $code['type'], $code['data'], 'php-1d');
        }

        if ($this->commandExists('zbarimg')) {
            foreach ($this->decodeWithZbar($imagePath) as $code) {
                $this->pushImageCode($merged, $seen, $code['type'], $code['data'], 'zbar');
            }
        }

        // Large ticket scans OOM QrReader at 128M; skip when a 1D primary is already found.
        if (!$this->hasPrimaryCode($merged) && class_exists(QrReader::class)) {
            foreach ($this->decodeQr($imagePath) as $code) {
                $this->pushImageCode($merged, $seen, $code['type'], $code['data'], 'qr-reader');
            }
        }

        return $this->preferLongerDigitCodes($merged);
    }

    /**
     * Drop truncated digit hits that are suffixes/prefixes of a longer valid read
     * (e.g. Code-C "99" mis-latch → 6008… vs full 9960…).
     *
     * @param list<array{type: string, data: string, method: string}> $codes
     *
     * @return list<array{type: string, data: string, method: string}>
     */
    private function preferLongerDigitCodes(array $codes): array
    {
        $digits = [];
        foreach ($codes as $code) {
            if (ctype_digit($code['data']) && strlen($code['data']) >= 6) {
                $digits[] = $code['data'];
            }
        }

        if ($digits === []) {
            return $codes;
        }

        usort($digits, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $filtered = [];
        foreach ($codes as $code) {
            $data = $code['data'];
            if (!ctype_digit($data) || strlen($data) < 6) {
                $filtered[] = $code;
                continue;
            }

            $dominated = false;
            foreach ($digits as $longer) {
                if (strlen($longer) <= strlen($data)) {
                    break;
                }
                if (str_contains($longer, $data)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $filtered[] = $code;
            }
        }

        return $filtered;
    }

    /**
     * @param list<array{type: string, data: string, method: string}> $merged
     * @param array<string, true> $seen
     */
    private function pushImageCode(array &$merged, array &$seen, string $type, string $data, string $method): void
    {
        if ($data === '' || $this->isWebAddressCode($data)) {
            return;
        }

        $type = $this->normalizeBarcodeType($type, $data);
        $key = $type.'|'.$data;
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $merged[] = [
            'type' => $type,
            'data' => $data,
            'method' => $method,
        ];
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

            $codes[] = [
                'type' => substr($line, 0, $pos),
                'data' => substr($line, $pos + 1),
            ];
        }

        return $codes;
    }

    private function commandExists(string $command): bool
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($command));
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
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
