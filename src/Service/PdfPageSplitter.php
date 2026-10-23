<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * Splits PDF pages using Composer packages only (FPDI + FPDF), no qpdf/gs CLI.
 * Modern PDFs are transferred to classic 1.4 structure before FPDI when needed.
 */
final class PdfPageSplitter
{
    public function __construct(
        private readonly string $storageDir,
        private readonly PdfVersionTransfer $versionTransfer,
    ) {
    }

    /**
     * @return array{id: string, pages: int, filename: string, path: string}
     */
    public function splitToArchive(UploadedFile $pdf): array
    {
        $this->assertPdf($pdf);

        $originalFilename = basename(str_replace('\\', '/', (string) $pdf->getClientOriginalName())) ?: 'document.pdf';
        $workDir = $this->createWorkDir('split');

        try {
            $pdf->move($workDir, 'input.pdf');
            $sourcePdf = $workDir.'/input.pdf';
            $pdfPath = $this->versionTransfer->ensureFpdiCompatible($sourcePdf, $workDir);

            try {
                $pageCount = $this->countPages($pdfPath);
            } catch (\Throwable) {
                $pdfPath = $this->versionTransfer->forceToPdf14($sourcePdf, $workDir);
                $pageCount = $this->countPages($pdfPath);
            }

            $archives = $this->createPagedArchives($pdfPath, $originalFilename, $pageCount, $pageCount);
            if ($archives === []) {
                throw new RuntimeException('No pages were produced from the PDF.');
            }

            $first = $archives[0];

            return [
                'id' => $first['id'],
                'pages' => $pageCount,
                'filename' => $first['filename'],
                'path' => $first['path'],
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function countPages(string $pdfPath): int
    {
        $reader = new Fpdi();
        $pageCount = $reader->setSourceFile($pdfPath);
        if ($pageCount < 1) {
            throw new RuntimeException('No pages were produced from the PDF.');
        }

        return $pageCount;
    }

    /**
     * Build ZIP archive(s) with per-page PDFs.
     *
     * Layout inside each ZIP: `{originalFilename}/1.pdf`, `2.pdf`, …
     * Download name: `{basename}_{from}_{to}.zip`.
     *
     * @return list<array{id: string, pages: int, filename: string, path: string, from: int, to: int}>
     */
    public function createPagedArchives(
        string $pdfPath,
        string $originalFilename,
        int $pageCount,
        int $chunkSize = 100,
    ): array {
        if ($pageCount < 1) {
            throw new RuntimeException('No pages were produced from the PDF.');
        }
        if ($chunkSize < 1) {
            throw new RuntimeException('Invalid archive page chunk size.');
        }

        $workDir = $this->createWorkDir('pagesplit');
        $pagesDir = $workDir.'/pages';

        try {
            if (!mkdir($pagesDir, 0775, true) && !is_dir($pagesDir)) {
                throw new RuntimeException('Unable to create pages directory.');
            }

            $folderName = $this->sanitizeZipEntryName($originalFilename);
            $archiveBase = pathinfo($folderName, \PATHINFO_FILENAME);
            if ($archiveBase === '' || $archiveBase === '.' || $archiveBase === '..') {
                $archiveBase = 'document';
            }

            $pageFiles = [];
            for ($page = 1; $page <= $pageCount; ++$page) {
                $pagePdf = new Fpdi();
                $pagePdf->setSourceFile($pdfPath);
                $templateId = $pagePdf->importPage($page);
                $size = $pagePdf->getTemplateSize($templateId);

                $pagePdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pagePdf->useTemplate($templateId);

                $pageFile = sprintf('%s/%d.pdf', $pagesDir, $page);
                $pagePdf->Output('F', $pageFile);
                $pageFiles[$page] = $pageFile;
            }

            $archivesDir = rtrim($this->storageDir, '/').'/archives';
            if (!is_dir($archivesDir) && !mkdir($archivesDir, 0775, true) && !is_dir($archivesDir)) {
                throw new RuntimeException('Unable to create archives directory.');
            }

            $archives = [];

            for ($from = 1; $from <= $pageCount; $from += $chunkSize) {
                $to = min($pageCount, $from + $chunkSize - 1);
                $pagesInZip = $to - $from + 1;
                $downloadName = sprintf('%s_%d_%d.zip', $archiveBase, $from, $to);

                $id = bin2hex(random_bytes(16));
                $archivePath = $archivesDir.'/'.$id.'.zip';

                $zip = new ZipArchive();
                if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to create ZIP archive.');
                }

                for ($page = $from; $page <= $to; ++$page) {
                    $zip->addFile(
                        $pageFiles[$page],
                        sprintf('%s/%d.pdf', $folderName, $page)
                    );
                }
                $zip->close();

                $this->writeArchiveMeta($id, [
                    'download_name' => $downloadName,
                    'pages' => $pagesInZip,
                    'from' => $from,
                    'to' => $to,
                    'folder' => $folderName,
                ]);

                $archives[] = [
                    'id' => $id,
                    'pages' => $pagesInZip,
                    'filename' => $downloadName,
                    'path' => $archivePath,
                    'from' => $from,
                    'to' => $to,
                ];
            }

            return $archives;
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

    public function getArchiveDownloadName(string $id): string
    {
        $meta = $this->readArchiveMeta($id);
        if (isset($meta['download_name']) && is_string($meta['download_name']) && $meta['download_name'] !== '') {
            return $meta['download_name'];
        }

        return 'pages_'.$id.'.zip';
    }

    public function deleteArchiveMeta(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return;
        }

        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeArchiveMeta(string $id, array $meta): void
    {
        $path = rtrim($this->storageDir, '/').'/archives/'.$id.'.json';
        file_put_contents($path, json_encode($meta, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function readArchiveMeta(string $id): array
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

    private function sanitizeZipEntryName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = str_replace(["\0", '..'], '', $name);
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
