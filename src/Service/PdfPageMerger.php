<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * Merges PDF files from a ZIP archive into one multi-page PDF (FPDI + FPDF).
 */
final class PdfPageMerger
{
    public function __construct(
        private readonly string $storageDir,
        private readonly PdfVersionTransfer $versionTransfer,
    ) {
    }

    /**
     * @return array{id: string, pages: int, files: int, filename: string, path: string}
     */
    public function mergeArchiveToPdf(UploadedFile $archive): array
    {
        $this->assertZip($archive);

        $originalFilename = basename(str_replace('\\', '/', (string) $archive->getClientOriginalName())) ?: 'pages.zip';
        $workDir = $this->createWorkDir('merge');

        try {
            $archive->move($workDir, 'input.zip');
            $zipPath = $workDir.'/input.zip';
            $extractDir = $workDir.'/extracted';
            if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
                throw new RuntimeException('Unable to create extract directory.');
            }

            $this->extractZip($zipPath, $extractDir);
            $pdfFiles = $this->collectPdfFiles($extractDir);
            if ($pdfFiles === []) {
                throw new RuntimeException('No PDF files found in the archive.');
            }

            $normDir = $workDir.'/normalized';
            if (!mkdir($normDir, 0775, true) && !is_dir($normDir)) {
                throw new RuntimeException('Unable to create normalize directory.');
            }

            $merged = new Fpdi();
            $totalPages = 0;

            foreach ($pdfFiles as $index => $pdfPath) {
                $compatible = $this->makeFpdiCompatible($pdfPath, $normDir, $index);
                try {
                    $pageCount = $merged->setSourceFile($compatible);
                } catch (\Throwable) {
                    $forceDir = $normDir.'/force_'.$index;
                    if (!is_dir($forceDir) && !mkdir($forceDir, 0775, true) && !is_dir($forceDir)) {
                        throw new RuntimeException('Unable to create force-normalize directory.');
                    }
                    $compatible = $this->versionTransfer->forceToPdf14($pdfPath, $forceDir);
                    $pageCount = $merged->setSourceFile($compatible);
                }

                for ($page = 1; $page <= $pageCount; ++$page) {
                    $templateId = $merged->importPage($page);
                    $size = $merged->getTemplateSize($templateId);
                    $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $merged->useTemplate($templateId);
                    ++$totalPages;
                }
            }

            if ($totalPages < 1) {
                throw new RuntimeException('No pages were produced from the archive.');
            }

            $archivesDir = rtrim($this->storageDir, '/').'/archives';
            if (!is_dir($archivesDir) && !mkdir($archivesDir, 0775, true) && !is_dir($archivesDir)) {
                throw new RuntimeException('Unable to create archives directory.');
            }

            $id = bin2hex(random_bytes(16));
            $outputPath = $archivesDir.'/'.$id.'.pdf';
            $merged->Output('F', $outputPath);

            $downloadName = $this->buildDownloadName($originalFilename);
            $this->writeMeta($id, [
                'download_name' => $downloadName,
                'pages' => $totalPages,
                'files' => count($pdfFiles),
                'type' => 'pdf',
            ]);

            return [
                'id' => $id,
                'pages' => $totalPages,
                'files' => count($pdfFiles),
                'filename' => $downloadName,
                'path' => $outputPath,
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function getResultPath(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.pdf';

        return is_file($path) ? $path : null;
    }

    public function getDownloadName(string $id): string
    {
        $meta = $this->readMeta($id);
        if (isset($meta['download_name']) && is_string($meta['download_name']) && $meta['download_name'] !== '') {
            return $meta['download_name'];
        }

        return 'merged_'.$id.'.pdf';
    }

    public function deleteMeta(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function makeFpdiCompatible(string $pdfPath, string $normDir, int $index): string
    {
        $fileNormDir = $normDir.'/'.$index;
        if (!is_dir($fileNormDir) && !mkdir($fileNormDir, 0775, true) && !is_dir($fileNormDir)) {
            throw new RuntimeException('Unable to create per-file normalize directory.');
        }

        return $this->versionTransfer->ensureFpdiCompatible($pdfPath, $fileNormDir);
    }

    private function extractZip(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open ZIP archive.');
        }

        try {
            $extractRoot = realpath($extractDir);
            if ($extractRoot === false) {
                throw new RuntimeException('Unable to resolve extract directory.');
            }

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $name);
                if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || str_contains($normalized, '..')) {
                    throw new RuntimeException('Archive contains an unsafe path.');
                }

                $target = $extractRoot.'/'.$normalized;
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                    throw new RuntimeException('Unable to create archive entry directory.');
                }

                $parentReal = realpath($parent);
                if ($parentReal === false || !str_starts_with($parentReal, $extractRoot)) {
                    throw new RuntimeException('Archive extraction escaped the target directory.');
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Unable to read archive entry.');
                }

                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Unable to write extracted file.');
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function collectPdfFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'pdf') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        usort($files, static function (string $a, string $b): int {
            return strnatcasecmp($a, $b);
        });

        return $files;
    }

    private function buildDownloadName(string $zipName): string
    {
        $base = pathinfo($zipName, \PATHINFO_FILENAME);
        $base = trim((string) $base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'merged';
        }

        return $base.'.pdf';
    }

    private function assertZip(UploadedFile $archive): void
    {
        if ($archive->getError() !== \UPLOAD_ERR_OK) {
            throw new RuntimeException('Archive upload failed.');
        }

        $mime = $archive->getMimeType() ?? '';
        $ext = strtolower((string) $archive->getClientOriginalExtension());
        $allowedMimes = [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'multipart/x-zip',
        ];

        if (!in_array($mime, $allowedMimes, true) && $ext !== 'zip') {
            throw new RuntimeException('Uploaded file must be a ZIP archive.');
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $id, array $meta): void
    {
        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        file_put_contents($path, json_encode($meta, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return [];
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
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
