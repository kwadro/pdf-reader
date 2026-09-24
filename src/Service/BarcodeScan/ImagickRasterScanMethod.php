<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

use Imagick;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Process\Process;
use Zxing\QrReader;

/**
 * Method 2: render PDF pages with PHP Imagick, then decode QR (PHP) and
 * optionally 1D/2D codes via zbarimg on the rendered images (if CLI exists).
 */
#[AutoconfigureTag('app.pdf_code_scan_method', ['priority' => 50])]
final class ImagickRasterScanMethod implements PdfCodeScanMethodInterface
{
    public function getName(): string
    {
        return 'imagick';
    }

    public function isSupported(): bool
    {
        return \extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function scan(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        $imagesDir = $workDir.'/imagick';
        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0775, true) && !is_dir($imagesDir)) {
            return [];
        }

        try {
            $pageImages = $this->renderPages($pdfPath, $imagesDir, $maxPages);
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        $zbarAvailable = $this->commandExists('zbarimg');

        foreach ($pageImages as $page => $imagePath) {
            foreach ($this->decodeQr($imagePath) as $code) {
                $codes[] = [
                    'page' => $page,
                    'type' => $code['type'],
                    'data' => $code['data'],
                    'method' => $this->getName(),
                ];
            }

            if ($zbarAvailable) {
                foreach ($this->decodeWithZbar($imagePath) as $code) {
                    $codes[] = [
                        'page' => $page,
                        'type' => $code['type'],
                        'data' => $code['data'],
                        'method' => $this->getName(),
                    ];
                }
            }
        }

        return $codes;
    }

    /**
     * @return array<int, string> page number => png path
     */
    private function renderPages(string $pdfPath, string $imagesDir, ?int $maxPages): array
    {
        $imagick = new Imagick();
        $imagick->setResolution(300, 300);
        $imagick->readImage($pdfPath);

        $pages = [];
        $index = 0;

        foreach ($imagick as $frame) {
            ++$index;
            if ($maxPages !== null && $index > $maxPages) {
                break;
            }

            /** @var Imagick $frame */
            $frame->setImageBackgroundColor('white');
            $frame->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $frame->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $frame->setImageFormat('png');

            $path = sprintf('%s/page-%d.png', $imagesDir, $index);
            $frame->writeImage($path);
            $pages[$index] = $path;
        }

        $imagick->clear();
        $imagick->destroy();

        return $pages;
    }

    /**
     * @return list<array{type: string, data: string}>
     */
    private function decodeQr(string $imagePath): array
    {
        if (!class_exists(QrReader::class)) {
            return [];
        }

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
    private function decodeWithZbar(string $imagePath): array
    {
        $process = new Process(['zbarimg', '--quiet', '-S*.enable', $imagePath]);
        $process->setTimeout(90);
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
}
