<?php

declare(strict_types=1);

namespace App\Service\BarcodeScan;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Process\Process;

/**
 * Method 3: classic CLI pipeline (pdftoppm + zbarimg) when both tools exist.
 */
#[AutoconfigureTag('app.pdf_code_scan_method', ['priority' => 10])]
final class CliZbarScanMethod implements PdfCodeScanMethodInterface
{
    public function getName(): string
    {
        return 'cli-zbar';
    }

    public function isSupported(): bool
    {
        return $this->commandExists('pdftoppm') && $this->commandExists('zbarimg');
    }

    public function scan(string $pdfPath, string $workDir, ?int $maxPages = null): array
    {
        if (!$this->isSupported()) {
            return [];
        }

        $imagesDir = $workDir.'/cli';
        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0775, true) && !is_dir($imagesDir)) {
            return [];
        }

        $prefix = $imagesDir.'/page';
        $command = ['pdftoppm', '-png', '-r', '300'];
        if ($maxPages !== null && $maxPages > 0) {
            $command[] = '-f';
            $command[] = '1';
            $command[] = '-l';
            $command[] = (string) $maxPages;
        }
        $command[] = $pdfPath;
        $command[] = $prefix;

        $process = new Process($command);
        $process->setTimeout(180);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $images = glob($imagesDir.'/page-*.png') ?: [];
        natsort($images);

        $codes = [];
        foreach ($images as $imagePath) {
            $page = 0;
            if (preg_match('/page-(\d+)\.png$/', (string) $imagePath, $matches) === 1) {
                $page = (int) $matches[1];
            }

            $zbar = new Process(['zbarimg', '--quiet', '-S*.enable', (string) $imagePath]);
            $zbar->setTimeout(90);
            $zbar->run();

            $exitCode = $zbar->getExitCode();
            if (!$zbar->isSuccessful() && !in_array($exitCode, [1, 4], true)) {
                continue;
            }

            foreach ($this->parseZbarOutput($zbar->getOutput()) as $code) {
                $codes[] = [
                    'page' => $page,
                    'type' => $code['type'],
                    'data' => $code['data'],
                    'method' => $this->getName(),
                ];
            }
        }

        return $codes;
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
