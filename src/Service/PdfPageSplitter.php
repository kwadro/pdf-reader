<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;
use ZipArchive;

final class PdfPageSplitter
{
    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * @return array{id: string, pages: int, filename: string, path: string}
     */
    public function splitToArchive(UploadedFile $pdf): array
    {
        $this->assertPdf($pdf);

        $workDir = $this->createWorkDir('split');
        $pagesDir = $workDir.'/pages';
        $pdfPath = $workDir.'/input.pdf';

        try {
            if (!mkdir($pagesDir, 0775, true) && !is_dir($pagesDir)) {
                throw new RuntimeException('Unable to create pages directory.');
            }

            $pdf->move($workDir, 'input.pdf');

            $process = new Process([
                'qpdf',
                '--split-pages',
                $pdfPath,
                $pagesDir.'/page.pdf',
            ]);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException('Failed to split PDF pages: '.$process->getErrorOutput());
            }

            $pageFiles = glob($pagesDir.'/page-*.pdf') ?: [];
            natsort($pageFiles);
            $pageFiles = array_values($pageFiles);

            if ($pageFiles === []) {
                // qpdf may produce page.pdf for single-page docs depending on version
                $single = $pagesDir.'/page.pdf';
                if (is_file($single)) {
                    $pageFiles = [$single];
                }
            }

            if ($pageFiles === []) {
                throw new RuntimeException('No pages were produced from the PDF.');
            }

            $id = bin2hex(random_bytes(16));
            $archivesDir = rtrim($this->storageDir, '/').'/archives';
            if (!is_dir($archivesDir) && !mkdir($archivesDir, 0775, true) && !is_dir($archivesDir)) {
                throw new RuntimeException('Unable to create archives directory.');
            }

            $archiveName = $id.'.zip';
            $archivePath = $archivesDir.'/'.$archiveName;

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create ZIP archive.');
            }

            $index = 1;
            foreach ($pageFiles as $pageFile) {
                $zip->addFile((string) $pageFile, sprintf('page_%04d.pdf', $index));
                ++$index;
            }
            $zip->close();

            return [
                'id' => $id,
                'pages' => count($pageFiles),
                'filename' => $archiveName,
                'path' => $archivePath,
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function getArchivePath(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.zip';

        return is_file($path) ? $path : null;
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
